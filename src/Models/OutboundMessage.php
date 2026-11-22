<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use IntrAgile\EInvoicing\Laravel\Enums\OutboundState;
use IntrAgile\EInvoicing\Transport\DeliveryFailure;
use IntrAgile\EInvoicing\Transport\FailureCategory;
use IntrAgile\EInvoicing\Transport\OutboundDocument;
use IntrAgile\EInvoicing\Transport\Peppol\DocumentTypeId;
use IntrAgile\EInvoicing\Transport\Peppol\ParticipantId;
use IntrAgile\EInvoicing\Transport\Peppol\ProcessId;

/**
 * One document handed to a transport, with its delivery state.
 *
 * @property int $id
 * @property string $transport
 * @property string|null $reference
 * @property string $sender
 * @property string $receiver
 * @property string $document_type
 * @property string $process
 * @property string|null $country_c1
 * @property string $xml
 * @property OutboundState $state
 * @property int $attempts
 * @property int $status_checks
 * @property string|null $provider_document_id
 * @property string|null $peppol_message_id
 * @property string|null $envelope_id
 * @property FailureCategory|null $failure_category
 * @property string|null $failure_message
 * @property string|null $provider_code
 * @property Carbon|null $sent_at
 * @property Carbon|null $finished_at
 * @property string|null $ksef_number
 * @property string|null $invoicing_mode
 * @property Carbon|null $offline_deadline
 * @property string|null $upo
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @phpstan-consistent-constructor
 */
class OutboundMessage extends Model
{
    protected $table = 'einvoicing_outbound_documents';

    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['xml', 'upo'];

    protected $attributes = [
        'attempts' => 0,
        'status_checks' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => OutboundState::class,
            'failure_category' => FailureCategory::class,
            'sent_at' => 'datetime',
            'finished_at' => 'datetime',
            'offline_deadline' => 'datetime',
        ];
    }

    /**
     * Stores the KSeF offline deadline as the same instant in the application timezone, which
     * the timestamp column is read back in, whatever timezone the given time carries.
     *
     * @return Attribute<Carbon|null, DateTimeInterface|string|null>
     */
    protected function offlineDeadline(): Attribute
    {
        return Attribute::make(set: fn (DateTimeInterface|string|null $value): ?string => $value === null
            ? null
            : $this->fromDateTime($this->asDateTime($value)->setTimezone(date_default_timezone_get())));
    }

    /**
     * An unsaved message for the document; the caller sets the state and saves.
     */
    public static function fromDocument(OutboundDocument $document, string $transport, ?string $reference): static
    {
        return new static([
            'transport' => $transport,
            'reference' => $reference,
            'sender' => $document->sender->toString(),
            'receiver' => $document->receiver->toString(),
            'document_type' => $document->documentType->value,
            'process' => $document->process->value,
            'country_c1' => $document->countryC1,
            'xml' => $document->xml,
        ]);
    }

    /**
     * The stored document with its identifiers exactly as stored, nothing re-derived from the XML.
     */
    public function toOutboundDocument(): OutboundDocument
    {
        return new OutboundDocument(
            $this->xml,
            ParticipantId::parse($this->sender),
            ParticipantId::parse($this->receiver),
            new DocumentTypeId($this->document_type),
            new ProcessId($this->process),
            $this->country_c1,
        );
    }

    public function failure(): ?DeliveryFailure
    {
        if ($this->failure_category === null) {
            return null;
        }

        return new DeliveryFailure($this->failure_category, $this->failure_message, $this->provider_code);
    }

    /**
     * Atomically move the message from one state to another.
     *
     * Only one worker can win the transition, which guards against the same document
     * being sent twice by duplicate or retried jobs. On a lost race the model is refreshed.
     *
     * Edge cases:
     * - Attributes that were already changed on the model before the call are written too, as
     *   part of the same conditional update.
     * - A null condition matches a NULL column (`IS NULL`), not a comparison with NULL.
     * - On a model that was never saved nothing matches: false is returned, nothing is reloaded,
     *   and the given attributes stay filled in as unsaved changes.
     * - The updated_at timestamp is written to the row but not to the model in memory.
     *
     * @param  array<string, mixed>  $attributes  Further columns to update together with the state.
     * @param  array<string, int|string|null>  $conditions  Further column values the row must still have, e.g. the claimed attempt count.
     */
    public function transition(OutboundState $from, OutboundState $to, array $attributes = [], array $conditions = []): bool
    {
        $attributes['state'] = $to;
        $this->fill($attributes);
        $dirty = $this->getDirty();
        $updatedAt = $this->getUpdatedAtColumn();
        if ($updatedAt !== null) {
            $dirty[$updatedAt] = $this->freshTimestampString();
        }

        $updated = static::query()
            ->whereKey($this->getKey())
            ->where('state', $from->value)
            ->where($conditions)
            ->update($dirty) === 1;

        $updated ? $this->syncOriginal() : $this->refresh();

        return $updated;
    }
}
