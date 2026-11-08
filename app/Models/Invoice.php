<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Invoicing\ForeignSale;
use App\Domain\Invoicing\InvoiceStatus;
use App\Modules\Peppol\Models\PeppolTransmission;
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
 * A customer invoice or credit invoice. A draft can be changed freely; once
 * issued it has a number and its contents never change. Only its status,
 * payments and sending are updated. A database trigger backs this up.
 *
 * @property bool $reverse_charge
 * @property ?ForeignSale $foreign_sale
 * @property bool $swedish_vat_under_threshold an EU consumer sale under the OSS threshold, taxed in Sweden
 * @property ?string $tax_reduction ROT or RUT
 * @property Money $tax_reduction_ore
 * @property Money $tax_reduction_paid_ore
 * @property Money $tax_reduction_repayable_ore on a credit invoice: the part of its deduction Skatteverket had already paid, a debt on 2890
 * @property Money $tax_reduction_repaid_ore on a credit invoice: how much of that debt has been paid back
 * @property ?string $property_designation
 * @property string $id
 * @property ?string $external_ref the invoice number in the previous program (ADR-076)
 * @property ?string $import_source IMPORT_OPEN for an open invoice read in without a voucher
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
 * @property Money $paid_ore what is settled: payments and the credit invoices that settle it
 * @property Money $credited_ore the part of paid_ore that credit invoices settled
 * @property ?string $ocr
 * @property ?string $voucher_id
 * @property ?CarbonImmutable $issued_at
 * @property ?int $issued_by
 * @property ?CarbonImmutable $sent_at
 * @property ?string $sent_to
 * @property ?int $created_by
 * @property ?string $recurring_invoice_id
 * @property string $invoice_currency ISO 4217; the *_ore columns are kronor whatever it is
 * @property ?int $exchange_rate_micros kronor per unit in millionths
 * @property ?CarbonImmutable $exchange_rate_date
 * @property ?string $exchange_rate_source riksbank or manual
 * @property ?int $foreign_net_minor
 * @property ?int $foreign_vat_minor
 * @property ?int $foreign_total_minor
 * @property int $foreign_paid_minor
 * @property bool $unregistered_vat_confirmed VAT charged although the company is not registered, confirmed (#51)
 * @property ?string $vat_exemption_text the company's exemption wording when issued (#51)
 * @property Money $written_off_ore the open part written off as a confirmed customer loss (#57)
 * @property ?CarbonImmutable $written_off_on
 * @property ?string $write_off_voucher_id
 * @property ?string $write_off_reason
 * @property Money $recovered_ore paid by the customer after the write-off (#57)
 */
class Invoice extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public const string INVOICE = 'INVOICE';

    public const string CREDIT = 'CREDIT';

    /** Columns that may change after the invoice is issued. */
    private const array MUTABLE_WHEN_ISSUED = ['status', 'paid_ore', 'credited_ore', 'foreign_paid_minor', 'tax_reduction_paid_ore', 'tax_reduction_repaid_ore', 'written_off_ore', 'written_off_on', 'write_off_voucher_id', 'write_off_reason', 'recovered_ore', 'sent_at', 'sent_to', 'updated_at'];

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
        'foreign_sale',
        'tax_reduction',
        'property_designation',
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
        'credited_ore' => 0,
        'invoice_currency' => 'SEK',
        'foreign_paid_minor' => 0,
        'tax_reduction_repayable_ore' => 0,
        'tax_reduction_repaid_ore' => 0,
        'unregistered_vat_confirmed' => false,
        'written_off_ore' => 0,
        'recovered_ore' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'reverse_charge' => 'boolean',
            'foreign_sale' => ForeignSale::class,
            'swedish_vat_under_threshold' => 'boolean',
            'unregistered_vat_confirmed' => 'boolean',
            'written_off_ore' => MoneyCast::class,
            'written_off_on' => 'immutable_date',
            'recovered_ore' => MoneyCast::class,
            'tax_reduction_ore' => MoneyCast::class,
            'tax_reduction_paid_ore' => MoneyCast::class,
            'tax_reduction_repayable_ore' => MoneyCast::class,
            'tax_reduction_repaid_ore' => MoneyCast::class,
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
            'credited_ore' => MoneyCast::class,
            'issued_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'exchange_rate_micros' => 'integer',
            'exchange_rate_date' => 'immutable_date',
            'foreign_net_minor' => 'integer',
            'foreign_vat_minor' => 'integer',
            'foreign_total_minor' => 'integer',
            'foreign_paid_minor' => 'integer',
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

    /**
     * What is still claimed from Skatteverket on 1513 for a ROT or RUT
     * invoice: the deduction, less what Skatteverket has paid and what
     * issued credit invoices took off the claim (the rest of their
     * deduction is a debt to Skatteverket, not a smaller claim).
     */
    public function taxReductionClaimable(): Money
    {
        if ($this->tax_reduction === null || $this->isCredit()) {
            return Money::zero();
        }

        $credits = $this->relationLoaded('creditInvoices')
            ? $this->creditInvoices->filter(fn (Invoice $credit): bool => ! $credit->isDraft())
            : $this->creditInvoices()->where('status', '<>', InvoiceStatus::Draft->value)->get(['tax_reduction_ore', 'tax_reduction_repayable_ore']);

        $offClaim = (int) $credits->sum(fn (Invoice $credit): int => -$credit->tax_reduction_ore->ore - $credit->tax_reduction_repayable_ore->ore);

        return Money::ofOre(max(0, $this->tax_reduction_ore->ore - $this->tax_reduction_paid_ore->ore - $offClaim));
    }

    /** On a credit invoice: what is still owed back to Skatteverket for its ROT or RUT deduction. */
    public function taxReductionRepaymentLeft(): Money
    {
        return $this->tax_reduction_repayable_ore->minus($this->tax_reduction_repaid_ore);
    }

    /**
     * The kind of foreign sale the invoice is booked and reported as. A sale
     * to a consumer in another EU country under the OSS threshold carries
     * Swedish VAT and is booked like a sale in Sweden, so it has none.
     */
    public function bookedForeignSale(): ?ForeignSale
    {
        return $this->foreign_sale === ForeignSale::EuConsumerOss && $this->swedish_vat_under_threshold ? null : $this->foreign_sale;
    }

    /** An invoice written in another currency than kronor, booked at exchange_rate_micros (ADR-025). */
    public function isForeign(): bool
    {
        return $this->invoice_currency !== 'SEK' && $this->foreign_total_minor !== null && $this->exchange_rate_micros !== null;
    }

    /** What is left to pay in the invoice's own currency, in hundredths; null for an invoice in kronor. */
    public function foreignRemaining(): ?int
    {
        return $this->isForeign() ? (int) $this->foreign_total_minor - $this->foreign_paid_minor : null;
    }

    /** The rate the invoice is booked at; null for an invoice in kronor. */
    public function bookingRate(): ?ExchangeRate
    {
        return $this->isForeign()
            ? new ExchangeRate($this->invoice_currency, ($this->exchange_rate_date ?? $this->invoice_date)->toDateString(), (int) $this->exchange_rate_micros, (string) ($this->exchange_rate_source ?? 'manual'))
            : null;
    }

    /** "1 517,50 EUR": an amount in hundredths of the invoice's currency. */
    public function formatForeign(int $minor): string
    {
        return Money::ofOre($minor)->format(withCurrency: false).' '.$this->invoice_currency;
    }

    /** What is left to pay as the customer sees it: in the invoice's currency, or in kronor. */
    public function formatRemaining(): string
    {
        $foreign = $this->foreignRemaining();

        return $foreign === null ? $this->remaining()->format() : $this->formatForeign($foreign);
    }

    /** A credit invoice with money left to repay to the customer is not overdue; only an unpaid invoice is. */
    public function isOverdue(?CarbonImmutable $today = null): bool
    {
        return ! $this->isCredit() && $this->status === InvoiceStatus::Issued && $this->due_date->lessThan($today ?? CarbonImmutable::today());
    }

    /** "Obetald", or for an open credit invoice "Att återbetala": the customer has money to get back. */
    public function statusLabel(): string
    {
        return $this->isCredit() && $this->status === InvoiceStatus::Issued ? __('Att återbetala') : $this->status->label();
    }

    /**
     * What may still be credited: the total less every issued credit
     * invoice, whether it settled the invoice or became a credit balance.
     * In kronor, and for an invoice in another currency in that currency.
     *
     * @return array{kronor: int, foreign: ?int}
     */
    public function creditable(): array
    {
        $credits = $this->creditInvoices()->where('status', '<>', InvoiceStatus::Draft->value)->get(['total_ore', 'foreign_total_minor']);

        return [
            'kronor' => $this->total_ore->ore + (int) $credits->sum(fn (Invoice $credit): int => $credit->total_ore->ore),
            'foreign' => $this->isForeign() ? (int) $this->foreign_total_minor + (int) $credits->sum(fn (Invoice $credit): int => (int) $credit->foreign_total_minor) : null,
        ];
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

    /**
     * The sendings through Peppol (ADR-050). The table belongs to the Peppol
     * module; it is empty while the module has never been used.
     *
     * @return HasMany<PeppolTransmission, $this>
     */
    public function peppolTransmissions(): HasMany
    {
        return $this->hasMany(PeppolTransmission::class);
    }

    /** @return HasMany<Invoice, $this> */
    public function creditInvoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'credits_invoice_id');
    }
}
