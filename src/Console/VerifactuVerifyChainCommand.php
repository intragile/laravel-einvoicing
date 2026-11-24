<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Console;

use Illuminate\Console\Command;
use IntrAgile\EInvoicing\Exception\UnsafeXml;
use IntrAgile\EInvoicing\Exception\UnsupportedDocument;
use IntrAgile\EInvoicing\Laravel\Exceptions\ConfigurationException;
use IntrAgile\EInvoicing\Laravel\Models\VerifactuChain;
use IntrAgile\EInvoicing\Laravel\Models\VerifactuRecord;
use IntrAgile\EInvoicing\Laravel\Verifactu\VerifactuManager;
use IntrAgile\EInvoicing\Spain\Verifactu\Exception\InvalidRecord;
use IntrAgile\EInvoicing\Spain\Verifactu\Fingerprint;
use IntrAgile\EInvoicing\Spain\Verifactu\InvoiceId;
use IntrAgile\EInvoicing\Syntax\SafeXml;
use IntrAgile\EInvoicing\Syntax\Verifactu\RecordReader;
use InvalidArgumentException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use UnexpectedValueException;

/**
 * Verifies every stored VERI*FACTU chain of the configured environment, of either driver, from the
 * stored XML alone:
 * - the sequences run 1, 2, 3, … without a gap;
 * - each record's Huella recomputed from its XML equals the stored hash and the Huella written in
 *   the XML, and the record names the row's invoice and the chain's issuer and installation;
 * - each record links to the previous row (stored previous_hash, and the RegistroAnterior Huella
 *   and invoice in the XML). The first record of a seeded chain links to the seeded head, which is
 *   trusted as given (it was registered by the software used before); the first record of an
 *   unseeded chain is a PrimerRegistro;
 * - the head columns of the chain name the last record.
 *
 * A chain row that never held a record or a seed is not a chain and is reported as unused. One
 * line is printed per chain; the exit code is 1 when any chain fails, and the line names the
 * sequence of each mismatch.
 */
final class VerifactuVerifyChainCommand extends Command
{
    use InteractsWithVerifactu;

    protected $signature = 'einvoicing:verifactu-verify-chain
        {--issuer= : Only the chains of this issuer NIF}';

    protected $description = 'Verify the stored VERI*FACTU chains: fingerprints, links and heads';

    /** Records read per query. */
    private const CHUNK = 200;

    public function handle(VerifactuManager $verifactu): int
    {
        try {
            $environment = $verifactu->settings()->environment;
            $issuer = $this->issuerFilter();
        } catch (ConfigurationException|InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $chains = VerifactuChain::query()
            ->where('environment', $environment->value)
            ->when($issuer !== null, static fn ($query) => $query->where('issuer_nif', $issuer?->value))
            ->orderBy('id')
            ->get();
        if ($chains->isEmpty()) {
            $this->components->info(sprintf('No VERI*FACTU chain in the %s environment%s.', $environment->value, $issuer === null ? '' : ' for issuer '.$issuer->value));

            return self::SUCCESS;
        }

        $failed = false;
        foreach ($chains as $chain) {
            $name = sprintf('%s (%s%s)', $chain->key()->toString(), $chain->driver->value, $chain->closed_at === null ? '' : ', closed');
            [$problems, $count] = $this->verify($chain);

            if ($problems !== []) {
                $failed = true;
                $this->line(sprintf('<error>FAIL</error> %s: %s', OutputFormatter::escape($name), OutputFormatter::escape(implode('; ', $problems))));

                continue;
            }
            $this->line(sprintf('<info>OK</info>   %s: %s', OutputFormatter::escape($name), self::summary($chain, $count)));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{list<string>, int} the problems found, and the number of records read
     */
    private function verify(VerifactuChain $chain): array
    {
        $problems = [];
        $reader = new RecordReader;
        $expected = 0;
        /** @var array{sequence: int, hash: ?string, number: string, issue_date: string, generated_at: ?string, invoice: InvoiceId}|null $previous */
        $previous = null;

        $rows = VerifactuRecord::query()
            ->where('chain_id', $chain->id)
            ->orderBy('sequence')
            ->lazy(self::CHUNK);

        foreach ($rows as $row) {
            $expected++;
            $sequence = (int) $row->sequence;
            $at = 'sequence '.$sequence;
            if ($sequence !== $expected) {
                $problems[] = sprintf('%s: expected sequence %d, a record is missing or numbered twice', $at, $expected);
                $expected = $sequence;
            }

            foreach ($this->checkRecord($chain, $row, $reader, $previous) as $problem) {
                $problems[] = $at.': '.$problem;
            }

            $previous = [
                'sequence' => $sequence,
                'hash' => $row->hash,
                'number' => $row->number,
                'issue_date' => $row->issue_date,
                'generated_at' => $row->generated_at,
                'invoice' => $row->invoiceId(),
            ];
        }

        foreach (self::checkHead($chain, $previous) as $problem) {
            $problems[] = $problem;
        }

        return [$problems, $expected];
    }

    /**
     * @param  array{sequence: int, hash: ?string, number: string, issue_date: string, generated_at: ?string, invoice: InvoiceId}|null  $previous
     * @return list<string>
     */
    private function checkRecord(VerifactuChain $chain, VerifactuRecord $row, RecordReader $reader, ?array $previous): array
    {
        $xml = $row->xml;
        if ($xml === null || $xml === '') {
            return ['no XML is stored'];
        }

        try {
            $element = SafeXml::loadPreservingWhitespace($xml)->documentElement;
            if ($element === null) {
                return ['the stored XML has no root element'];
            }
            $recomputed = Fingerprint::recompute($element);
            $record = $reader->read($xml);
        } catch (InvalidRecord|UnsupportedDocument|UnsafeXml|InvalidArgumentException|UnexpectedValueException $exception) {
            return ['the stored XML cannot be read: '.$exception->getMessage()];
        }

        $problems = [];
        if ($recomputed !== $row->hash) {
            $problems[] = sprintf('the Huella recomputed from the XML is %s, the stored hash is %s', $recomputed, $row->hash ?? 'none');
        }
        if ($record->fingerprint() !== $row->hash) {
            $problems[] = sprintf('the Huella in the XML is %s, the stored hash is %s', $record->fingerprint(), $row->hash ?? 'none');
        }
        if (! $record->invoiceId()->equals($row->invoiceId())) {
            $problems[] = sprintf('the XML names invoice %s of %s, the row %s of %s', $record->invoiceId()->number, $record->invoiceId()->dateText(), $row->number, $row->issue_date);
        }
        if ($record->invoiceId()->issuer->value !== $chain->issuer_nif) {
            $problems[] = sprintf('the XML names issuer %s, the chain %s', $record->invoiceId()->issuer->value, $chain->issuer_nif);
        }
        if ($record->system()->installationNumber !== $chain->installation_number) {
            $problems[] = sprintf('the XML names installation "%s", the chain "%s"', $record->system()->installationNumber, $chain->installation_number);
        }

        $link = $record->chainLink();
        if ($previous === null) {
            if ($chain->seeded) {
                // The seeded head is trusted: only the stored link and the XML must agree.
                if ($link->isFirst()) {
                    $problems[] = 'the chain is seeded, but the first record is a PrimerRegistro';
                } elseif ($link->previousFingerprint() !== $row->previous_hash) {
                    $problems[] = sprintf('the XML links to the seeded Huella %s, the stored previous hash is %s', $link->previousFingerprint(), $row->previous_hash ?? 'none');
                }
            } elseif (! $link->isFirst() || $row->previous_hash !== null) {
                $problems[] = 'the first record of an unseeded chain must be a PrimerRegistro without a previous hash';
            }

            return $problems;
        }

        if ($row->previous_hash !== $previous['hash']) {
            $problems[] = sprintf('the stored previous hash is %s, the hash of sequence %d is %s', $row->previous_hash ?? 'none', $previous['sequence'], $previous['hash'] ?? 'none');
        }
        $named = $link->previous();
        if ($named === null) {
            $problems[] = 'the record is a PrimerRegistro in the middle of the chain';
        } else {
            if ($link->previousFingerprint() !== $previous['hash']) {
                $problems[] = sprintf('the XML links to Huella %s, the hash of sequence %d is %s', $link->previousFingerprint(), $previous['sequence'], $previous['hash'] ?? 'none');
            }
            if (! $named->equals($previous['invoice'])) {
                $problems[] = sprintf('the XML links to invoice %s of %s, sequence %d is %s of %s', $named->number, $named->dateText(), $previous['sequence'], $previous['number'], $previous['issue_date']);
            }
        }

        return $problems;
    }

    /**
     * @param  array{sequence: int, hash: ?string, number: string, issue_date: string, generated_at: ?string, invoice: InvoiceId}|null  $last
     * @return list<string>
     */
    private static function checkHead(VerifactuChain $chain, ?array $last): array
    {
        if ($last === null) {
            return $chain->sequence === 0 ? [] : [sprintf('head: the chain is at sequence %d, but no record is attached', $chain->sequence)];
        }

        $expected = [
            'sequence' => [$last['sequence'], $chain->sequence],
            'hash' => [$last['hash'], $chain->head_hash],
            'number' => [$last['number'], $chain->head_number],
            'issue date' => [$last['issue_date'], $chain->head_issue_date],
            'generation timestamp' => [$last['generated_at'], $chain->head_generated_at],
        ];
        $problems = [];
        foreach ($expected as $column => [$record, $head]) {
            if ($record !== $head) {
                $problems[] = sprintf('head: the %s is %s, the last record (sequence %d) has %s', $column, $head ?? 'none', $last['sequence'], $record ?? 'none');
            }
        }

        return $problems;
    }

    private static function summary(VerifactuChain $chain, int $count): string
    {
        if ($count === 0) {
            return $chain->seeded && $chain->head_hash !== null
                ? sprintf('seeded after invoice %s of %s, no records yet', OutputFormatter::escape((string) $chain->head_number), $chain->head_issue_date)
                : 'unused chain row, no records';
        }

        return sprintf('%d %s, head sequence %d%s', $count, $count === 1 ? 'record' : 'records', $chain->sequence, $chain->seeded ? ', seeded head trusted' : '');
    }
}
