<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Inleverans: goods expected from a supplier, received in one go or in
 * parts. Receiving posts RECEIPT movements; it never touches the ledger.
 *
 * @property int $id
 * @property int $company_id
 * @property int $number
 * @property ?int $supplier_id
 * @property ?string $supplier_invoice_id
 * @property string $status
 * @property ?CarbonImmutable $expected_on
 * @property ?string $reference
 * @property ?string $note
 * @property ?int $created_by
 * @property ?CarbonInterface $created_at
 * @property ?CarbonInterface $updated_at
 */
class StockReceipt extends Model
{
    use BelongsToCompany;

    public const string OPEN = 'OPEN';

    public const string PARTIAL = 'PARTIAL';

    public const string RECEIVED = 'RECEIVED';

    public const string CANCELLED = 'CANCELLED';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'number',
        'supplier_id',
        'supplier_invoice_id',
        'status',
        'expected_on',
        'reference',
        'note',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'expected_on' => 'immutable_date',
        ];
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::OPEN, self::PARTIAL], true);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::OPEN => __('Väntar'),
            self::PARTIAL => __('Delvis inlevererad'),
            self::RECEIVED => __('Inlevererad'),
            default => __('Makulerad'),
        };
    }

    /** @return HasMany<StockReceiptLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(StockReceiptLine::class)->orderBy('position')->orderBy('id');
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<SupplierInvoice, $this> */
    public function supplierInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class);
    }
}
