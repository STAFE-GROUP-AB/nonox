<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Models;

use App\Models\Article;
use App\Modules\Inventory\Domain\StructureMath;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A component of a production order's copy (ADR-071 §8): its net quantity
 * for the batch and its loss as on the bill of materials, what the whole
 * order needs in thousandths of the component's stock unit, and what has
 * been issued so far with the valuation cost that left the stock (the sums
 * of the order's issues).
 *
 * @property int $id
 * @property int $company_id
 * @property int $production_order_id
 * @property int $position
 * @property int $component_article_id
 * @property int $quantity_milli
 * @property int $loss_bp
 * @property ?string $entered_quantity
 * @property ?string $entered_unit
 * @property ?string $note
 * @property int $required_milli
 * @property int $issued_milli
 * @property int $issued_value_ore
 */
class ProductionOrderComponent extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'production_order_id',
        'position',
        'component_article_id',
        'quantity_milli',
        'loss_bp',
        'entered_quantity',
        'entered_unit',
        'note',
        'required_milli',
        'issued_milli',
        'issued_value_ore',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'loss_bp' => 0,
        'issued_milli' => 0,
        'issued_value_ore' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'quantity_milli' => 'integer',
            'loss_bp' => 'integer',
            'required_milli' => 'integer',
            'issued_milli' => 'integer',
            'issued_value_ore' => 'integer',
        ];
    }

    /** @return BelongsTo<ProductionOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    /** @return BelongsTo<Article, $this> */
    public function component(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'component_article_id');
    }

    /** @return HasMany<ProductionOrderIssue, $this> */
    public function issues(): HasMany
    {
        return $this->hasMany(ProductionOrderIssue::class)->orderBy('id');
    }

    /** RS-C2: what the batch takes from stock, loss included. */
    public function grossMilli(): int
    {
        return StructureMath::gross($this->quantity_milli, $this->loss_bp);
    }

    /** What is still to be issued, never below zero. */
    public function remainingMilli(): int
    {
        return max(0, $this->required_milli - $this->issued_milli);
    }
}
