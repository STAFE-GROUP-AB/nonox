<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Models;

use App\Models\Article;
use App\Modules\Inventory\Domain\StructureMath;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line of a row's bill of materials copy, shaped like a structure row
 * (bom_rows): a component with its net quantity in thousandths of its
 * stock unit and its loss, or an operation with its minutes for the batch.
 *
 * @property int $id
 * @property int $company_id
 * @property int $row_structure_id
 * @property int $position
 * @property ?int $component_article_id
 * @property int $quantity_milli
 * @property int $loss_bp
 * @property ?string $entered_quantity
 * @property ?string $entered_unit
 * @property ?int $operation_id
 * @property ?int $minutes
 * @property ?string $note
 */
class RowStructureLine extends Model
{
    use BelongsToCompany;

    protected $table = 'sales_document_row_structure_lines';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'row_structure_id',
        'position',
        'component_article_id',
        'quantity_milli',
        'loss_bp',
        'entered_quantity',
        'entered_unit',
        'operation_id',
        'minutes',
        'note',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'quantity_milli' => 'integer',
            'loss_bp' => 'integer',
            'minutes' => 'integer',
        ];
    }

    /** @return BelongsTo<Article, $this> */
    public function component(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'component_article_id');
    }

    /** @return BelongsTo<Operation, $this> */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function isOperation(): bool
    {
        return $this->operation_id !== null;
    }

    /** RS-C2: what is taken from stock for the batch, loss included. */
    public function grossMilli(): int
    {
        return StructureMath::gross($this->quantity_milli, $this->loss_bp);
    }
}
