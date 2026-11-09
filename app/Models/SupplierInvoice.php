<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Payables\SupplierInvoiceStatus;
use App\Domain\Payables\VatTreatment;
use App\Support\Currency\ExchangeRate;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * An invoice from a supplier. A draft can be changed freely; once registered
 * it has a number, is booked (fakturametoden) and its contents never change.
 * Only its status, approval and payments are updated. A database trigger
 * backs this up.
 *
 * @property string $id
 * @property ?string $external_ref the invoice number in the previous program (ADR-076)
 * @property ?string $import_source IMPORT_OPEN for an open invoice read in without a voucher
 * @property int $company_id
 * @property int $supplier_id
 * @property ?int $number
 * @property SupplierInvoiceStatus $status
 * @property ?string $invoice_number
 * @property ?string $ocr
 * @property CarbonImmutable $invoice_date
 * @property CarbonImmutable $due_date
 * @property ?string $description
 * @property Money $total_ore
 * @property Money $vat_ore
 * @property Money $paid_ore
 * @property ?array<string, mixed> $supplier_snapshot
 * @property ?string $file_path
 * @property ?string $file_name
 * @property ?string $file_mime
 * @property ?int $file_size
 * @property ?string $file_sha256
 * @property ?string $voucher_id
 * @property ?CarbonImmutable $registered_at
 * @property ?int $registered_by
 * @property ?CarbonImmutable $approved_at
 * @property ?int $approved_by
 * @property ?int $created_by
 * @property string $kind
 * @property VatTreatment $vat_treatment
 * @property string $invoice_currency
 * @property ?int $foreign_total_minor
 * @property int $foreign_paid_minor
 * @property ?int $exchange_rate_micros
 */
class SupplierInvoice extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public const string INVOICE = 'INVOICE';

    public const string CREDIT = 'CREDIT';

    /** Columns that may change after the invoice is registered. */
    private const array MUTABLE_WHEN_REGISTERED = ['status', 'paid_ore', 'foreign_paid_minor', 'approved_at', 'approved_by', 'updated_at'];

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'supplier_id',
        'invoice_number',
        'ocr',
        'invoice_date',
        'due_date',
        'description',
        'kind',
        'vat_treatment',
        'created_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'DRAFT',
        'kind' => 'INVOICE',
        'vat_treatment' => 'DOMESTIC',
        'total_ore' => 0,
        'vat_ore' => 0,
        'paid_ore' => 0,
        'invoice_currency' => 'SEK',
        'foreign_paid_minor' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'status' => SupplierInvoiceStatus::class,
            'vat_treatment' => VatTreatment::class,
            'invoice_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'supplier_snapshot' => 'array',
            'total_ore' => MoneyCast::class,
            'vat_ore' => MoneyCast::class,
            'paid_ore' => MoneyCast::class,
            'file_size' => 'integer',
            'foreign_total_minor' => 'integer',
            'foreign_paid_minor' => 'integer',
            'exchange_rate_micros' => 'integer',
            'registered_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (SupplierInvoice $invoice): void {
            if ($invoice->getOriginal('status') === SupplierInvoiceStatus::Draft) {
                return;
            }

            if (array_diff(array_keys($invoice->getDirty()), self::MUTABLE_WHEN_REGISTERED) !== []) {
                throw new LogicException("Registered supplier invoice {$invoice->id} cannot be changed.");
            }
        });

        static::deleting(function (SupplierInvoice $invoice): void {
            if ($invoice->getOriginal('status') !== SupplierInvoiceStatus::Draft) {
                throw new LogicException("Registered supplier invoice {$invoice->id} cannot be deleted.");
            }
        });
    }

    /** What a voucher text adds for an imported invoice: the booking is against the opening balance. */
    public function bookingNote(): string
    {
        return $this->isImported() ? ' – '.__('Ingående balans (inläst)') : '';
    }

    /** An open invoice read in from another program (ADR-076): no NONOX number, no approver, no booking. */
    public function isImported(): bool
    {
        return $this->import_source !== null;
    }

    public function isDraft(): bool
    {
        return $this->status === SupplierInvoiceStatus::Draft;
    }

    public function isCredit(): bool
    {
        return $this->kind === self::CREDIT;
    }

    public function remaining(): Money
    {
        return $this->total_ore->minus($this->paid_ore);
    }

    /** An invoice in another currency than kronor, booked at exchange_rate_micros. */
    public function isForeign(): bool
    {
        return $this->invoice_currency !== 'SEK' && $this->foreign_total_minor !== null && $this->exchange_rate_micros !== null;
    }

    /** What is left to pay in the invoice's own currency, in hundredths; null for an invoice in kronor. */
    public function foreignRemaining(): ?int
    {
        return $this->isForeign() ? (int) $this->foreign_total_minor - $this->foreign_paid_minor : null;
    }

    /** The rate the invoice was booked at; null for an invoice in kronor. */
    public function bookingRate(): ?ExchangeRate
    {
        return $this->isForeign()
            ? new ExchangeRate($this->invoice_currency, $this->invoice_date->toDateString(), (int) $this->exchange_rate_micros, 'invoice')
            : null;
    }

    /** "1 517,50 USD": an amount in hundredths of the invoice's currency. */
    public function formatForeign(int $minor): string
    {
        return Money::ofOre($minor)->format(withCurrency: false).' '.$this->invoice_currency;
    }

    public function isOverdue(?CarbonImmutable $today = null): bool
    {
        return ! $this->isCredit() && in_array($this->status, [SupplierInvoiceStatus::Registered, SupplierInvoiceStatus::Approved], true)
            && $this->due_date->lessThan($today ?? CarbonImmutable::today());
    }

    /**
     * "Leverantörsfaktura 12" or "Utkast till leverantörsfaktura".
     */
    public function title(): string
    {
        if ($this->isImported()) {
            return __('Leverantörsfaktura :ref (tidigare program)', ['ref' => (string) ($this->invoice_number ?? $this->external_ref)]);
        }

        if ($this->number === null) {
            return $this->isCredit() ? __('Utkast till kreditnota') : __('Utkast till leverantörsfaktura');
        }

        return $this->isCredit()
            ? __('Kreditnota :number', ['number' => $this->number])
            : __('Leverantörsfaktura :number', ['number' => $this->number]);
    }

    public function supplierName(): string
    {
        return (string) ($this->supplier_snapshot['name'] ?? $this->supplier?->name);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return HasMany<SupplierInvoiceRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(SupplierInvoiceRow::class)->orderBy('position');
    }

    /** @return HasMany<SupplierPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class)->orderBy('paid_on');
    }

    /** @return HasMany<SupplierPaymentBatchItem, $this> */
    public function batchItems(): HasMany
    {
        return $this->hasMany(SupplierPaymentBatchItem::class);
    }

    /** @return BelongsTo<Voucher, $this> */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    /** @return BelongsTo<User, $this> */
    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
