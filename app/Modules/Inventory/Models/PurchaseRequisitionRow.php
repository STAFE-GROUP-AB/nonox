<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Domain\Units\UnitConverter;
use App\Models\Account;
use App\Models\Article;
use App\Models\CostCenter;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\Unit;
use App\Modules\Inventory\Domain\Quantity;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A row on a purchase requisition (ADR-081 E9): an article or free text, a
 * quantity as typed in the row's unit (thousandths), an estimated price per
 * that unit, and optionally an account, cost centre, project and supplier.
 * `purchase_order_row_id` is set when a buyer converts the row.
 *
 * @property int $id
 * @property int $company_id
 * @property int $purchase_requisition_id
 * @property int $position
 * @property ?int $article_id
 * @property string $description
 * @property int $quantity_milli
 * @property int $unit_id
 * @property ?int $estimated_price_ore
 * @property ?int $account_id
 * @property ?int $cost_center_id
 * @property ?int $project_id
 * @property ?int $supplier_id
 * @property ?int $purchase_order_row_id
 */
class PurchaseRequisitionRow extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'purchase_requisition_id',
        'position',
        'article_id',
        'description',
        'quantity_milli',
        'unit_id',
        'estimated_price_ore',
        'account_id',
        'cost_center_id',
        'project_id',
        'supplier_id',
        'purchase_order_row_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'quantity_milli' => 'integer',
            'estimated_price_ore' => 'integer',
        ];
    }

    public function isConverted(): bool
    {
        return $this->purchase_order_row_id !== null;
    }

    /** The quantity as a decimal with a point (what a purchase order row keeps as typed). */
    public function quantityDecimal(): string
    {
        return Quantity::decimal($this->quantity_milli);
    }

    /** The estimate for the row, half up once; null when no price is given. */
    public function estimatedValueOre(): ?int
    {
        if ($this->estimated_price_ore === null) {
            return null;
        }

        return UnitConverter::lineValueOre($this->quantityDecimal(), $this->estimated_price_ore);
    }

    /** @return BelongsTo<PurchaseRequisition, $this> */
    public function requisition(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id');
    }

    /** @return BelongsTo<Article, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<CostCenter, $this> */
    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<PurchaseOrderRow, $this> */
    public function purchaseOrderRow(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderRow::class);
    }
}
