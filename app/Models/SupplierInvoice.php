<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Payables\SupplierInvoiceStatus;
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
 */
class SupplierInvoice extends Model
{
    use BelongsToCompany;
    use HasUuids;

    /** Columns that may change after the invoice is registered. */
    private const array MUTABLE_WHEN_REGISTERED = ['status', 'paid_ore', 'approved_at', 'approved_by', 'updated_at'];

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'supplier_id',
        'invoice_number',
        'ocr',
        'invoice_date',
        'due_date',
        'description',
        'created_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'DRAFT',
        'total_ore' => 0,
        'vat_ore' => 0,
        'paid_ore' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'status' => SupplierInvoiceStatus::class,
            'invoice_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'supplier_snapshot' => 'array',
            'total_ore' => MoneyCast::class,
            'vat_ore' => MoneyCast::class,
            'paid_ore' => MoneyCast::class,
            'file_size' => 'integer',
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

    public function isDraft(): bool
    {
        return $this->status === SupplierInvoiceStatus::Draft;
    }

    public function remaining(): Money
    {
        return $this->total_ore->minus($this->paid_ore);
    }

    public function isOverdue(?CarbonImmutable $today = null): bool
    {
        return in_array($this->status, [SupplierInvoiceStatus::Registered, SupplierInvoiceStatus::Approved], true)
            && $this->due_date->lessThan($today ?? CarbonImmutable::today());
    }

    /**
     * "Leverantörsfaktura 12" or "Utkast till leverantörsfaktura".
     */
    public function title(): string
    {
        return $this->number === null
            ? __('Utkast till leverantörsfaktura')
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
