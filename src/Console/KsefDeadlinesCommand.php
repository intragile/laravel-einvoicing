<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Console;

use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use IntrAgile\EInvoicing\Exception\UnsafeXml;
use IntrAgile\EInvoicing\Exception\UnsupportedDocument;
use IntrAgile\EInvoicing\Laravel\EInvoicingManager;
use IntrAgile\EInvoicing\Laravel\Enums\OutboundState;
use IntrAgile\EInvoicing\Laravel\Events\KsefOfflineDeadlineApproaching;
use IntrAgile\EInvoicing\Laravel\Exceptions\ConfigurationException;
use IntrAgile\EInvoicing\Laravel\Jobs\SendKsefInvoice;
use IntrAgile\EInvoicing\Laravel\Ksef\KsefInvoiceFacts;
use IntrAgile\EInvoicing\Laravel\Ksef\KsefManager;
use IntrAgile\EInvoicing\Laravel\Models\OutboundMessage;
use IntrAgile\EInvoicing\Poland\Offline\DeadlineReason;
use IntrAgile\EInvoicing\Poland\Offline\OfflineDeadline;
use IntrAgile\EInvoicing\Poland\Offline\Outage;
use IntrAgile\EInvoicing\Transport\Exception\TransportException;
use IntrAgile\EInvoicing\Transport\FailureCategory;
use IntrAgile\EInvoicing\Transport\Ksef\Latarnia\OutageEvents;
use InvalidArgumentException;

/**
 * Lists the offline KSeF invoices still on their way to KSeF (queued, sending, pending or
 * unconfirmed) with their sending deadlines, and fires KsefOfflineDeadlineApproaching for each one
 * whose deadline lies within einvoicing.ksef.deadline_warning_hours or has passed, on every run.
 * A failed row is listed too when it failed for a reason that does not settle the invoice, such as
 * a transport error during a KSeF outage: the invoice still has to reach KSeF by its deadline, and
 * the row is marked for a resend with einvoicing:resolve --resend. Delivered rows, and rows failed
 * on validation, as a duplicate or as an unsupported document, are left out.
 *
 * Every offline row is taken as an offline24 invoice issued on its P_1 date. The failures
 * announced in Latarnia move the deadlines as the KSeF rules say; when Latarnia cannot be reached,
 * or the environment has none, the deadlines are computed without announced outages and the
 * command says so. The computed deadline is stored on the row (null while it is unknown). This is
 * a calculation aid, not legal advice.
 *
 * Schedule it, for example hourly: `Schedule::command('einvoicing:ksef-deadlines')->hourly();`
 */
final class KsefDeadlinesCommand extends Command
{
    protected $signature = 'einvoicing:ksef-deadlines';

    protected $description = 'List the offline KSeF invoices waiting for delivery with their deadlines, and warn about those due soon';

    /** The states of a row that may still reach KSeF; delivered and failed rows are final. */
    private const OPEN_STATES = [OutboundState::Queued, OutboundState::Sending, OutboundState::Pending, OutboundState::Unconfirmed];

    /** The failure categories that settle an invoice: resending the same file cannot help. */
    private const SETTLED_FAILURES = [FailureCategory::Validation, FailureCategory::Duplicate, FailureCategory::DocumentNotSupported];

    /** Seconds the Latarnia request may take to connect and in total. */
    private const LATARNIA_TIMEOUT = 10;

    public function handle(KsefManager $ksef): int
    {
        try {
            $warningHours = $ksef->settings()->deadlineWarningHours;
        } catch (ConfigurationException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $rows = OutboundMessage::query()
            ->where('transport', EInvoicingManager::KSEF)
            ->where('invoicing_mode', SendKsefInvoice::MODE_OFFLINE)
            ->where(static function (Builder $query): void {
                $query->whereIn('state', array_map(static fn (OutboundState $state): string => $state->value, self::OPEN_STATES))
                    ->orWhere(static function (Builder $failed): void {
                        $failed->where('state', OutboundState::Failed->value)
                            ->where(static function (Builder $category): void {
                                $category->whereNull('failure_category')
                                    ->orWhereNotIn('failure_category', array_map(static fn (FailureCategory $settled): string => $settled->value, self::SETTLED_FAILURES));
                            });
                    });
            })
            ->orderBy('id')
            ->get();
        if ($rows->isEmpty()) {
            $this->components->info('No offline KSeF invoice is waiting for delivery.');

            return self::SUCCESS;
        }

        $outages = $this->outages($ksef);
        $warnBefore = now()->addHours($warningHours)->toDateTimeImmutable();
        $now = now()->toDateTimeImmutable();
        $table = [];
        $due = 0;
        foreach ($rows as $row) {
            try {
                $facts = KsefInvoiceFacts::read($row->xml);
                $deadline = $facts->offlineDeadline($outages);
            } catch (InvalidArgumentException|UnsafeXml|UnsupportedDocument $exception) {
                $table[] = [$row->id, $row->reference ?? '', self::state($row), '', 'unknown', $exception->getMessage()];

                continue;
            }

            $row->offline_deadline = $deadline->deadline;
            if ($row->isDirty('offline_deadline')) {
                OutboundMessage::query()->whereKey($row->id)->update(['offline_deadline' => $row->getAttributes()['offline_deadline']]);
            }

            $note = '';
            if ($deadline->deadline !== null && $deadline->deadline <= $warnBefore) {
                $due++;
                $note = $deadline->deadline < $now ? ' (overdue)' : ' (due soon)';
                event(new KsefOfflineDeadlineApproaching($row, $deadline->deadline));
            }
            $table[] = [
                $row->id,
                $row->reference ?? '',
                self::state($row),
                $facts->issueDate->format('Y-m-d'),
                self::describe($deadline).$note,
                self::rule($deadline->reason),
            ];
        }

        $this->table(['ID', 'Reference', 'State', 'Issued (P_1)', 'Deadline (Europe/Warsaw)', 'Rule'], $table);
        $this->components->info(sprintf(
            '%d offline invoices waiting for delivery, %d due within %d hours or overdue.',
            count($table),
            $due,
            $warningHours,
        ));

        return self::SUCCESS;
    }

    /**
     * The outages Latarnia announces, or none when it cannot be read.
     *
     * @return list<Outage>
     */
    private function outages(KsefManager $ksef): array
    {
        try {
            $latarnia = $ksef->latarnia(self::LATARNIA_TIMEOUT);
            if ($latarnia === null) {
                $this->components->warn('The Ministry of Finance publishes no Latarnia for DEMO; the deadlines are computed without announced outages.');

                return [];
            }

            return OutageEvents::fold($latarnia->messages());
        } catch (ConfigurationException|TransportException $exception) {
            $this->components->warn(sprintf(
                'Latarnia could not be read, so the deadlines are computed without announced outages and a failure may move them: %s',
                $exception->getMessage(),
            ));

            return [];
        }
    }

    private static function state(OutboundMessage $row): string
    {
        return $row->state === OutboundState::Failed
            ? 'failed, resend with einvoicing:resolve --resend'
            : $row->state->value;
    }

    private static function describe(OfflineDeadline $deadline): string
    {
        if ($deadline->deadline !== null) {
            return $deadline->deadline->setTimezone(new DateTimeZone('Europe/Warsaw'))->format('Y-m-d H:i');
        }

        return match ($deadline->reason) {
            DeadlineReason::FailureOngoing => 'unknown (failure ongoing)',
            DeadlineReason::UnavailabilityOngoing => 'unknown (unavailability ongoing)',
            DeadlineReason::TotalFailure => 'none (total failure: no duty to send)',
            default => 'unknown',
        };
    }

    private static function rule(DeadlineReason $reason): string
    {
        return match ($reason) {
            DeadlineReason::NextWorkingDay => 'offline24: next working day',
            DeadlineReason::AfterUnavailability => 'next working day after the unavailability',
            DeadlineReason::AfterFailure => '7th working day after the failure',
            DeadlineReason::UnavailabilityOngoing => 'unavailability not ended',
            DeadlineReason::FailureOngoing => 'failure not ended',
            DeadlineReason::TotalFailure => 'total failure',
        };
    }
}
