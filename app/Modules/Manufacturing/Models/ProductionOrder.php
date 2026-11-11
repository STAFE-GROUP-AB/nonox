<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Models;

use App\Models\Article;
use App\Models\SalesDocument;
use App\Models\Unit;
use App\Models\User;
use App\Modules\Inventory\Models\BillOfMaterials;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Manufacturing\Domain\ProductionOrderStatus;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Tillverkningsorder (ADR-071 §8): a quantity of an article made from the
 * order's own copy of its bill of materials. Released, it reserves its
 * components; started, it may take them out of stock; finished, it takes
 * the rest out and receives the finished goods at the manufacturing cost.
 * No voucher: the year-end valuation counts finished goods and the work in
 * progress of unfinished orders.
 *
 * @property int $id
 * @property int $company_id
 * @property int $number
 * @property ProductionOrderStatus $status
 * @property int $article_id
 * @property ?int $bill_of_materials_id
 * @property string $name
 * @property int $yield_milli
 * @property int $quantity_milli
 * @property ?string $entered_quantity
 * @property ?int $unit_id
 * @property ?int $location_id
 * @property ?string $sales_document_id
 * @property ?string $sales_line_key
 * @property ?CarbonImmutable $planned_start_on
 * @property ?CarbonImmutable $due_on
 * @property ?int $plan_priority
 * @property ?string $notes
 * @property ?CarbonInterface $released_at
 * @property ?int $released_by
 * @property ?CarbonImmutable $started_on
 * @property ?int $started_by
 * @property ?CarbonImmutable $finished_on
 * @property ?int $finished_by
 * @property ?int $produced_milli
 * @property ?CarbonImmutable $cancelled_on
 * @property ?int $cancelled_by
 * @property ?string $cancel_reason
 * @property ?int $material_cost_ore
 * @property ?int $labour_cost_ore
 * @property ?int $overhead_cost_ore
 * @property ?int $overhead_bp
 * @property ?int $unit_cost_ore
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property ?CarbonInterface $created_at
 * @property ?CarbonInterface $updated_at
 */
class ProductionOrder extends Model
{
    use BelongsToCompany;

    public const string SERIES = 'production_orders';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'number',
        'status',
        'article_id',
        'bill_of_materials_id',
        'name',
        'yield_milli',
        'quantity_milli',
        'entered_quantity',
        'unit_id',
        'location_id',
        'sales_document_id',
        'sales_line_key',
        'planned_start_on',
        'due_on',
        'plan_priority',
        'notes',
        'released_at',
        'released_by',
        'started_on',
        'started_by',
        'finished_on',
        'finished_by',
        'produced_milli',
        'cancelled_on',
        'cancelled_by',
        'cancel_reason',
        'material_cost_ore',
        'labour_cost_ore',
        'overhead_cost_ore',
        'overhead_bp',
        'unit_cost_ore',
        'created_by',
        'updated_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'DRAFT',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ProductionOrderStatus::class,
            'number' => 'integer',
            'yield_milli' => 'integer',
            'quantity_milli' => 'integer',
            'produced_milli' => 'integer',
            'planned_start_on' => 'immutable_date',
            'due_on' => 'immutable_date',
            'plan_priority' => 'integer',
            'released_at' => 'immutable_datetime',
            'started_on' => 'immutable_date',
            'finished_on' => 'immutable_date',
            'cancelled_on' => 'immutable_date',
            'material_cost_ore' => 'integer',
            'labour_cost_ore' => 'integer',
            'overhead_cost_ore' => 'integer',
            'overhead_bp' => 'integer',
            'unit_cost_ore' => 'integer',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeReserving(Builder $query): Builder
    {
        return $query->whereIn('status', [ProductionOrderStatus::Released->value, ProductionOrderStatus::Started->value]);
    }

    /** TO-1042: the number as people say it. */
    public function reference(): string
    {
        return "TO-{$this->number}";
    }

    /** The manufacturing cost of a finished order: material, labour and overhead. */
    public function totalCostOre(): ?int
    {
        if ($this->material_cost_ore === null) {
            return null;
        }

        return $this->material_cost_ore + (int) $this->labour_cost_ore + (int) $this->overhead_cost_ore;
    }

    /** @return BelongsTo<Article, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /** @return BelongsTo<BillOfMaterials, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(BillOfMaterials::class, 'bill_of_materials_id');
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return BelongsTo<StockLocation, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class);
    }

    /** @return BelongsTo<SalesDocument, $this> */
    public function salesDocument(): BelongsTo
    {
        return $this->belongsTo(SalesDocument::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<ProductionOrderComponent, $this> */
    public function components(): HasMany
    {
        return $this->hasMany(ProductionOrderComponent::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<ProductionOrderOperation, $this> */
    public function operations(): HasMany
    {
        return $this->hasMany(ProductionOrderOperation::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<ProductionOrderIssue, $this> */
    public function issues(): HasMany
    {
        return $this->hasMany(ProductionOrderIssue::class)->orderBy('id');
    }

    /** @return HasMany<ProductionLabourEntry, $this> */
    public function labourEntries(): HasMany
    {
        return $this->hasMany(ProductionLabourEntry::class)->orderBy('id');
    }

    /**
     * The copy as structure lines, for the costing engine (ManufacturingBook):
     * unsaved, in the order of the copy, components first. Needs the
     * components and operations loaded.
     *
     * @return Collection<int, RowStructureLine>
     */
    public function structureLines(): Collection
    {
        $lines = collect();

        foreach ($this->components as $component) {
            $lines->push(new RowStructureLine([
                'position' => $component->position,
                'component_article_id' => $component->component_article_id,
                'quantity_milli' => $component->quantity_milli,
                'loss_bp' => $component->loss_bp,
                'entered_quantity' => $component->entered_quantity,
                'entered_unit' => $component->entered_unit,
                'note' => $component->note,
            ]));
        }

        foreach ($this->operations as $operation) {
            $lines->push(new RowStructureLine([
                'position' => $operation->position,
                'operation_id' => $operation->operation_id,
                'minutes' => $operation->minutes,
                'note' => $operation->note,
            ]));
        }

        return $lines;
    }
}
