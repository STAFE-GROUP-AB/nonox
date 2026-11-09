<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Models\Article;
use App\Modules\Inventory\Domain\StructureKind;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One version of a structure (ADR-073 §2): a recipe or a bill of materials
 * for an output article, valid from a date. Saved once and never changed,
 * except to switch it off; a change is a new version with a new
 * valid_from, so sales on an earlier date keep the version they were made
 * with. Written only through StructureWriter.
 *
 * @property int $id
 * @property int $company_id
 * @property StructureKind $kind
 * @property int $output_article_id
 * @property string $name
 * @property int $version
 * @property CarbonImmutable $valid_from
 * @property bool $is_active
 * @property int $yield_milli
 * @property string $yield_unit
 * @property ?string $method
 * @property ?list<string> $allergens
 * @property ?int $created_by
 */
class BillOfMaterials extends Model
{
    use BelongsToCompany;

    protected $table = 'bills_of_materials';

    /** The yield is a number of portions (a dish). */
    public const string PORTIONS = 'PORTIONS';

    /** The yield is in the output article's own unit (a sauce in kg). */
    public const string UNIT = 'UNIT';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'kind',
        'output_article_id',
        'name',
        'version',
        'valid_from',
        'is_active',
        'yield_milli',
        'yield_unit',
        'method',
        'allergens',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => StructureKind::class,
            'version' => 'integer',
            'valid_from' => 'immutable_date',
            'is_active' => 'boolean',
            'yield_milli' => 'integer',
            'allergens' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $structure): void {
            if (array_diff(array_keys($structure->getDirty()), ['is_active', 'updated_at']) !== []) {
                throw new LogicException('A saved structure version cannot be changed; save a new version.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('A structure version cannot be deleted; switch it off.');
        });
    }

    /** @return BelongsTo<Article, $this> */
    public function outputArticle(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'output_article_id');
    }

    /** @return HasMany<BomRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(BomRow::class, 'bill_of_materials_id')->orderBy('position')->orderBy('id');
    }

    public function isPortions(): bool
    {
        return $this->yield_unit === self::PORTIONS;
    }
}
