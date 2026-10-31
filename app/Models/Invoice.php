<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Invoicing\InvoiceStatus;
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
 * A customer invoice or credit invoice. A draft can be changed freely; once
 * issued it has a number and its contents never change. Only its status,
 * payments and sending are updated. A database trigger backs this up.
 *
 * @property bool $reverse_charge
 * @property string $id
 * @property int $company_id
 * @property int $customer_id
 * @property ?int $number
 * @property string $kind
 * @property ?string $credits_invoice_id
 * @property InvoiceStatus $status
 * @property CarbonImmutable $invoice_date
 * @property CarbonImmutable $due_date
 * @property ?CarbonImmutable $delivery_date
 * @property ?string $your_reference
 * @property ?string $our_reference
 * @property ?string $notes
 * @property ?array<string, mixed> $customer_snapshot
 * @property Money $net_ore
 * @property Money $vat_ore
 * @property Money $rounding_ore
 * @property Money $total_ore
 * @property Money $paid_ore
 * @property ?string $ocr
 * @property ?string $voucher_id
 * @property ?CarbonImmutable $issued_at
 * @property ?int $issued_by
 * @property ?CarbonImmutable $sent_at
 * @property ?string $sent_to
 * @property ?int $created_by
 * @property ?string $recurring_invoice_id
 */
class Invoice extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public const string INVOICE = 'INVOICE';

    public const string CREDIT = 'CREDIT';

    /** Columns that may change after the invoice is issued. */
    private const array MUTABLE_WHEN_ISSUED = ['status', 'paid_ore', 'sent_at', 'sent_to', 'updated_at'];

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'customer_id',
        'kind',
        'credits_invoice_id',
        'invoice_date',
        'due_date',
        'delivery_date',
        'your_reference',
        'our_reference',
        'notes',
        'reverse_charge',
        'created_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'DRAFT',
        'kind' => 'INVOICE',
        'net_ore' => 0,
        'vat_ore' => 0,
        'rounding_ore' => 0,
        'total_ore' => 0,
        'paid_ore' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'reverse_charge' => 'boolean',
            'number' => 'integer',
            'status' => InvoiceStatus::class,
            'invoice_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'delivery_date' => 'immutable_date',
            'customer_snapshot' => 'array',
            'net_ore' => MoneyCast::class,
            'vat_ore' => MoneyCast::class,
            'rounding_ore' => MoneyCast::class,
            'total_ore' => MoneyCast::class,
            'paid_ore' => MoneyCast::class,
            'issued_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Invoice $invoice): void {
            if ($invoice->getOriginal('status') === InvoiceStatus::Draft) {
                return;
            }

            if (array_diff(array_keys($invoice->getDirty()), self::MUTABLE_WHEN_ISSUED) !== []) {
                throw new LogicException("Issued invoice {$invoice->id} cannot be changed.");
            }
        });

        static::deleting(function (Invoice $invoice): void {
            if ($invoice->getOriginal('status') !== InvoiceStatus::Draft) {
                throw new LogicException("Issued invoice {$invoice->id} cannot be deleted.");
            }
        });
    }

    public function isDraft(): bool
    {
        return $this->status === InvoiceStatus::Draft;
    }

    public function isCredit(): bool
    {
        return $this->kind === self::CREDIT;
    }

    public function remaining(): Money
    {
        return $this->total_ore->minus($this->paid_ore);
    }

    public function isOverdue(?CarbonImmutable $today = null): bool
    {
        return $this->status === InvoiceStatus::Issued && $this->due_date->lessThan($today ?? CarbonImmutable::today());
    }

    /**
     * "Faktura 12", "Kreditfaktura 13", or "Utkast".
     */
    public function title(): string
    {
        if ($this->number === null) {
            return $this->isCredit() ? __('Utkast till kreditfaktura') : __('Utkast till faktura');
        }

        return $this->isCredit()
            ? __('Kreditfaktura :number', ['number' => $this->number])
            : __('Faktura :number', ['number' => $this->number]);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return HasMany<InvoiceRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(InvoiceRow::class)->orderBy('position');
    }

    /** @return HasMany<InvoicePayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class)->orderBy('id');
    }

    /** @return HasMany<InvoiceReminder, $this> */
    public function reminders(): HasMany
    {
        return $this->hasMany(InvoiceReminder::class)->orderBy('sequence');
    }

    /** @return BelongsTo<Voucher, $this> */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function credits(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'credits_invoice_id');
    }

    /** @return HasMany<Invoice, $this> */
    public function creditInvoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'credits_invoice_id');
    }
}
