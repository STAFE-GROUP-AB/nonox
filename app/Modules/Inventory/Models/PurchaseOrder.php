<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Modules\Inventory\Domain\PurchaseOrderSource;
use App\Modules\Inventory\Domain\PurchaseOrderStatus;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Inköpsorder (ADR-071 §2): what the company has ordered from a supplier.
 * A commitment, not bookkeeping; it creates no voucher. Goods receipts tick
 * off its rows; the supplier invoice is matched against it by a person.
 *
 * @property int $id
 * @property int $company_id
 * @property int $number
 * @property int $version
 * @property int $supplier_id
 * @property PurchaseOrderStatus $status
 * @property PurchaseOrderSource $source
 * @property CarbonImmutable $order_date
 * @property ?CarbonImmutable $requested_delivery_on
 * @property ?string $our_reference
 * @property ?string $delivery_address
 * @property ?string $notes
 * @property ?int $created_by
 * @property ?CarbonInterface $sent_at
 * @property ?string $sent_to
 * @property ?string $sent_method
 * @property ?int $sent_by
 * @property ?CarbonInterface $created_at
 * @property ?CarbonInterface $updated_at
 */
class PurchaseOrder extends Model
{
    use BelongsToCompany;

    public const string SERIES = 'purchase_orders';

    public const string EMAIL = 'EMAIL';

    public const string PRINT = 'PRINT';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'number',
        'version',
        'supplier_id',
        'status',
        'source',
        'order_date',
        'requested_delivery_on',
        'our_reference',
        'delivery_address',
        'notes',
        'created_by',
        'sent_at',
        'sent_to',
        'sent_method',
        'sent_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'version' => 1,
        'status' => 'DRAFT',
        'source' => 'MANUAL',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'version' => 'integer',
            'status' => PurchaseOrderStatus::class,
            'source' => PurchaseOrderSource::class,
            'order_date' => 'immutable_date',
            'requested_delivery_on' => 'immutable_date',
            'sent_at' => 'immutable_datetime',
        ];
    }

    public function title(): string
    {
        return __('Inköpsorder :number', ['number' => $this->number]);
    }

    public function isDraft(): bool
    {
        return $this->status === PurchaseOrderStatus::Draft;
    }

    /** Sent at least once: a change makes a new version. */
    public function wasSent(): bool
    {
        return $this->sent_at !== null;
    }

    /**
     * The order's total excluding VAT, from its rows: the rows' values in
     * their own units, each rounded half up once. Reads the loaded rows.
     */
    public function totalOre(): int
    {
        return (int) $this->rows->sum(fn (PurchaseOrderRow $row): int => $row->valueOre());
    }

    /** @return HasMany<PurchaseOrderRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(PurchaseOrderRow::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<PurchaseOrderVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(PurchaseOrderVersion::class)->orderByDesc('version');
    }

    /** @return HasMany<StockReceipt, $this> */
    public function receipts(): HasMany
    {
        return $this->hasMany(StockReceipt::class)->orderBy('number');
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsToMany<SupplierInvoice, $this> */
    public function supplierInvoices(): BelongsToMany
    {
        return $this->belongsToMany(SupplierInvoice::class, 'purchase_order_invoices')->withTimestamps();
    }
}
