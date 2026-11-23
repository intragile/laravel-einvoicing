<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use IntrAgile\EInvoicing\Transport\Aeat\ThrottleState;

/**
 * The AEAT send throttle of one key (an installation, or an issuer of an installation): the
 * persisted ThrottleState, and whether submissions are blocked until an operator acts.
 *
 * next_send_at is always derived from last_sent_at and wait_seconds; it is kept for queries.
 *
 * @property int $id
 * @property string $key
 * @property Carbon|null $last_sent_at
 * @property int $wait_seconds
 * @property Carbon|null $next_send_at
 * @property Carbon|null $blocked_at
 * @property string|null $blocked_code
 * @property string|null $blocked_message
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class VerifactuThrottle extends Model
{
    public const TABLE = 'einvoicing_verifactu_throttles';

    protected $table = self::TABLE;

    protected $guarded = ['id'];

    protected $attributes = [
        'wait_seconds' => 60,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_sent_at' => 'datetime',
            'wait_seconds' => 'integer',
            'next_send_at' => 'datetime',
            'blocked_at' => 'datetime',
        ];
    }

    public function state(): ThrottleState
    {
        return new ThrottleState($this->last_sent_at?->toDateTimeImmutable(), $this->wait_seconds);
    }

    /** Writes the state and the send time it allows, and saves the row. */
    public function store(ThrottleState $state): void
    {
        $this->last_sent_at = $state->lastSentAt === null ? null : Carbon::instance($state->lastSentAt);
        $this->wait_seconds = $state->waitSeconds;
        $next = $state->nextSendAt();
        $this->next_send_at = $next === null ? null : Carbon::instance($next);
        $this->save();
    }

    /** @return Attribute<Carbon|null, DateTimeInterface|string|null> */
    protected function lastSentAt(): Attribute
    {
        return $this->instant();
    }

    /** @return Attribute<Carbon|null, DateTimeInterface|string|null> */
    protected function nextSendAt(): Attribute
    {
        return $this->instant();
    }

    /** @return Attribute<Carbon|null, DateTimeInterface|string|null> */
    protected function blockedAt(): Attribute
    {
        return $this->instant();
    }

    /**
     * Stores a time as the same instant in the application timezone, which the timestamp column is
     * read back in, whatever timezone the given time carries.
     *
     * @return Attribute<Carbon|null, DateTimeInterface|string|null>
     */
    private function instant(): Attribute
    {
        return Attribute::make(set: fn (DateTimeInterface|string|null $value): ?string => $value === null
            ? null
            : $this->fromDateTime($this->asDateTime($value)->setTimezone(date_default_timezone_get())));
    }
}
