<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Models;

use App\Models\Article;
use App\Models\Concerns\HasRegisterUnit;
use App\Models\SupplierInvoiceRow;
use App\Models\User;
use App\Modules\WorkOrders\Domain\MaterialSource;
use App\Modules\WorkOrders\Models\Concerns\AppendOnlyEntry;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Material on a work order (ADR-074 §3): from stock, from a row of an
 * approved supplier invoice (billed once), or typed by hand. Never ROT or
 * RUT labour (AO-R13).
 *
 * @property string $id
 * @property int $company_id
 * @property int $work_order_id
 * @property ?int $change_order_id
 * @property int $user_id
 * @property ?int $article_id
 * @property string $description
 * @property int $quantity_milli
 * @property string $unit
 * @property int $unit_cost_ore
 * @property int $unit_price_ore excluding VAT
 * @property int $vat_rate
 * @property bool $billable
 * @property MaterialSource $source
 * @property ?int $stock_location_id
 * @property ?int $supplier_invoice_row_id
 * @property CarbonImmutable $work_date
 * @property ?string $note
 * @property CarbonImmutable $entered_at
 * @property ?string $reverses_id
 * @property ?string $reversed_by_id
 * @property ?string $reversal_reason
 * @property ?string $invoice_id
 * @property ?int $created_by
 */
class MaterialEntry extends Model
{
    use AppendOnlyEntry;
    use BelongsToCompany;
    use HasRegisterUnit;
    use HasUuids;

    protected $table = 'work_order_material_entries';

    /** @var list<string> */
    protected $fillable = [
        'id', 'company_id', 'work_order_id', 'change_order_id', 'user_id', 'article_id', 'description', 'quantity_milli', 'unit', 'unit_id',
        'unit_cost_ore', 'unit_price_ore', 'vat_rate', 'billable', 'source', 'stock_location_id', 'supplier_invoice_row_id',
        'work_date', 'note', 'entered_at', 'reverses_id', 'reversal_reason', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity_milli' => 'integer',
            'unit_cost_ore' => 'integer',
            'unit_price_ore' => 'integer',
            'vat_rate' => 'integer',
            'billable' => 'boolean',
            'source' => MaterialSource::class,
            'work_date' => 'immutable_date',
            'entered_at' => 'immutable_datetime',
        ];
    }

    /** The sales amount excluding VAT, rounded half up to whole öre. */
    public function netOre(): int
    {
        return (int) round($this->quantity_milli * $this->unit_price_ore / 1000, 0, PHP_ROUND_HALF_UP);
    }

    /** The cost, rounded half up to whole öre. */
    public function costOre(): int
    {
        return (int) round($this->quantity_milli * $this->unit_cost_ore / 1000, 0, PHP_ROUND_HALF_UP);
    }

    /** @return BelongsTo<Article, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /** @return BelongsTo<SupplierInvoiceRow, $this> */
    public function supplierInvoiceRow(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoiceRow::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
