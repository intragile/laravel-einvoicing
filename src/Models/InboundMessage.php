<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use IntrAgile\EInvoicing\Laravel\Enums\InboundState;

/**
 * One document received through a transport, from notification to acknowledgement.
 *
 * @property int $id
 * @property string $transport
 * @property string $provider_document_id
 * @property string|null $sender
 * @property string|null $receiver
 * @property string|null $document_type
 * @property Carbon|null $received_at
 * @property InboundState $state
 * @property string|null $xml
 * @property string|null $envelope_id
 * @property int $fetch_attempts
 * @property string|null $last_error
 * @property Carbon|null $fetched_at
 * @property Carbon|null $acknowledged_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class InboundMessage extends Model
{
    protected $table = 'einvoicing_inbound_documents';

    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['xml'];

    protected $attributes = [
        'fetch_attempts' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => InboundState::class,
            'received_at' => 'datetime',
            'fetched_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    /**
     * A query for messages that leaves out the potentially large XML column.
     *
     * @return Builder<static>
     */
    public static function queryWithoutXml(): Builder
    {
        return static::query()->select([
            'id', 'transport', 'provider_document_id', 'sender', 'receiver', 'document_type', 'received_at',
            'state', 'envelope_id', 'fetch_attempts', 'last_error', 'fetched_at', 'acknowledged_at',
            'created_at', 'updated_at',
        ]);
    }

    /**
     * Atomically move the message from one state to another; see OutboundMessage::transition().
     *
     * @param  array<string, mixed>  $attributes  Further columns to update together with the state.
     * @param  array<string, int|string>  $conditions  Further column values the row must still have.
     */
    public function transition(InboundState $from, InboundState $to, array $attributes = [], array $conditions = []): bool
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
