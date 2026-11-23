<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Models;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use IntrAgile\EInvoicing\Laravel\Enums\VerifactuRecordKind;
use IntrAgile\EInvoicing\Laravel\Enums\VerifactuRecordState;
use IntrAgile\EInvoicing\Spain\Nif;
use IntrAgile\EInvoicing\Spain\Verifactu\InvoiceId;
use IntrAgile\EInvoicing\Spain\Verifactu\IssuerDriver;
use IntrAgile\EInvoicing\Spain\VerifactuEnvironment;
use UnexpectedValueException;

/**
 * One VERI*FACTU billing record with its submission state.
 *
 * xml holds the record exactly as written (or as B2Brouter returned it), and generated_at the
 * literal FechaHoraHusoGenRegistro text that entered the hash; neither is ever re-derived.
 * chain_id and sequence stay null while a B2Brouter record waits for its registration.
 *
 * @property int $id
 * @property int|null $chain_id
 * @property int|null $sequence
 * @property VerifactuRecordKind $kind
 * @property string $issuer_nif
 * @property string $number
 * @property string $issue_date
 * @property VerifactuEnvironment $environment
 * @property IssuerDriver $driver
 * @property string|null $reference
 * @property string|null $previous_hash
 * @property string|null $hash
 * @property string|null $generated_at
 * @property string|null $xml
 * @property string|null $source
 * @property string|null $qr_url
 * @property VerifactuRecordState $state
 * @property string|null $csv
 * @property string|null $error_code
 * @property string|null $error_message
 * @property string|null $error_action
 * @property int $attempts
 * @property string|null $b2brouter_invoice_id
 * @property string|null $b2brouter_report_id
 * @property array<array-key, mixed>|null $cross_check
 * @property Carbon|null $submitted_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class VerifactuRecord extends Model
{
    public const TABLE = 'einvoicing_verifactu_records';

    protected $table = self::TABLE;

    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['xml', 'source'];

    protected $attributes = [
        'attempts' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'chain_id' => 'integer',
            'sequence' => 'integer',
            'kind' => VerifactuRecordKind::class,
            'environment' => VerifactuEnvironment::class,
            'driver' => IssuerDriver::class,
            'state' => VerifactuRecordState::class,
            'attempts' => 'integer',
            'cross_check' => 'array',
            'submitted_at' => 'datetime',
        ];
    }

    /**
     * Stores the submission time as the same instant in the application timezone.
     *
     * @return Attribute<Carbon|null, DateTimeInterface|string|null>
     */
    protected function submittedAt(): Attribute
    {
        return Attribute::make(set: fn (DateTimeInterface|string|null $value): ?string => $value === null
            ? null
            : $this->fromDateTime($this->asDateTime($value)->setTimezone(date_default_timezone_get())));
    }

    /**
     * The invoice the record registers or cancels, from the stored columns.
     *
     * @throws UnexpectedValueException when the stored issue date is not a date
     */
    public function invoiceId(): InvoiceId
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr($this->issue_date, 0, 10));
        if ($date === false) {
            throw new UnexpectedValueException(sprintf('The VERI*FACTU record %d has an unreadable issue date.', $this->id));
        }

        return new InvoiceId(Nif::parse($this->issuer_nif), $this->number, $date);
    }

    /**
     * @return BelongsTo<VerifactuChain, $this>
     */
    public function chain(): BelongsTo
    {
        return $this->belongsTo(VerifactuChain::class, 'chain_id');
    }
}
