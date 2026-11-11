<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Models;

use App\Models\Article;
use App\Models\SalesDocument;
use App\Modules\Inventory\Models\BillOfMaterials;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A quote or order row's own bill of materials (ADR-071 §4): copied from a
 * version when it is chosen on the row, then changed on the row without
 * touching the template. It hangs on the row's line_key, so it survives
 * the document being saved again, and it follows a quote into the order.
 * Changed only while the document is open (SaveRowStructure).
 *
 * @property int $id
 * @property int $company_id
 * @property string $sales_document_id
 * @property string $line_key
 * @property int $output_article_id
 * @property ?int $bill_of_materials_id
 * @property string $name
 * @property int $yield_milli
 * @property bool $changed
 * @property ?int $created_by
 * @property ?int $updated_by
 */
class RowStructure extends Model
{
    use BelongsToCompany;

    protected $table = 'sales_document_row_structures';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'sales_document_id',
        'line_key',
        'output_article_id',
        'bill_of_materials_id',
        'name',
        'yield_milli',
        'changed',
        'created_by',
        'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'yield_milli' => 'integer',
            'changed' => 'boolean',
        ];
    }

    /** @return BelongsTo<SalesDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(SalesDocument::class, 'sales_document_id');
    }

    /** @return BelongsTo<Article, $this> */
    public function outputArticle(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'output_article_id');
    }

    /** @return BelongsTo<BillOfMaterials, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(BillOfMaterials::class, 'bill_of_materials_id');
    }

    /** @return HasMany<RowStructureLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(RowStructureLine::class, 'row_structure_id')->orderBy('position')->orderBy('id');
    }
}
