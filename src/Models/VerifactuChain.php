<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use IntrAgile\EInvoicing\Spain\Nif;
use IntrAgile\EInvoicing\Spain\Verifactu\ChainKey;
use IntrAgile\EInvoicing\Spain\Verifactu\IssuerDriver;
use IntrAgile\EInvoicing\Spain\VerifactuEnvironment;

/**
 * One VERI*FACTU chain (issuer, installation, environment) with its head.
 *
 * The head columns are written only by the chain store, under a lock and a compare-and-set on the
 * sequence. head_generated_at is the literal FechaHoraHusoGenRegistro text of the head record.
 *
 * @property int $id
 * @property string $issuer_nif
 * @property string $installation_number
 * @property VerifactuEnvironment $environment
 * @property IssuerDriver $driver
 * @property string|null $throttle_key
 * @property int $sequence
 * @property string|null $head_number
 * @property string|null $head_issue_date
 * @property string|null $head_hash
 * @property string|null $head_generated_at
 * @property bool $seeded
 * @property Carbon|null $closed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class VerifactuChain extends Model
{
    public const TABLE = 'einvoicing_verifactu_chains';

    protected $table = self::TABLE;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'environment' => VerifactuEnvironment::class,
            'driver' => IssuerDriver::class,
            'sequence' => 'integer',
            'seeded' => 'boolean',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * Stores the closing time as the same instant in the application timezone.
     *
     * @return Attribute<Carbon|null, DateTimeInterface|string|null>
     */
    protected function closedAt(): Attribute
    {
        return Attribute::make(set: fn (DateTimeInterface|string|null $value): ?string => $value === null
            ? null
            : $this->fromDateTime($this->asDateTime($value)->setTimezone(date_default_timezone_get())));
    }

    public function key(): ChainKey
    {
        return new ChainKey(Nif::parse($this->issuer_nif), $this->installation_number, $this->environment);
    }

    /**
     * @return HasMany<VerifactuRecord, $this>
     */
    public function records(): HasMany
    {
        return $this->hasMany(VerifactuRecord::class, 'chain_id');
    }
}
