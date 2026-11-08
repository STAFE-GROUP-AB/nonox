<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Models\Article;
use App\Modules\Inventory\Domain\StructureMath;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A row of a structure version: a component article with its net quantity
 * in thousandths of the component's unit and its loss in basis points of
 * the gross quantity. A row without a component is an operation (ADR-071)
 * and is ignored when materials are costed or exploded. Never changed.
 *
 * @property int $id
 * @property int $company_id
 * @property int $bill_of_materials_id
 * @property ?int $component_article_id
 * @property int $quantity_milli
 * @property int $loss_bp
 * @property ?string $entered_quantity
 * @property ?string $entered_unit
 * @property int $position
 * @property ?string $note
 * @property ?int $operation_id
 * @property ?int $minutes
 */
class BomRow extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'bill_of_materials_id',
        'component_article_id',
        'quantity_milli',
        'loss_bp',
        'entered_quantity',
        'entered_unit',
        'position',
        'note',
        'operation_id',
        'minutes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity_milli' => 'integer',
            'loss_bp' => 'integer',
            'position' => 'integer',
            'minutes' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('A structure row cannot be changed; save a new version.');
        });
    }

    /** @return BelongsTo<Article, $this> */
    public function component(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'component_article_id');
    }

    /** RS-C2. */
    public function grossMilli(): int
    {
        return StructureMath::gross($this->quantity_milli, $this->loss_bp);
    }
}
