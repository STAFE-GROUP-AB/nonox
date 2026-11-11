<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Units\UnitDimension;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * A unit in a company's catalogue (ADR-071 §12.1): kept as printed (`m²`),
 * with its dimension, an optional fixed size in the dimension's base unit,
 * an optional Peppol Rec 20 code and the spellings that match it on import.
 * A unit in use is archived, never deleted.
 *
 * @property int $id
 * @property int $company_id
 * @property string $code
 * @property string $name
 * @property UnitDimension $dimension
 * @property ?int $base_numerator
 * @property ?int $base_denominator
 * @property ?string $rec20_code
 * @property list<string> $aliases
 * @property bool $is_default
 * @property ?CarbonInterface $archived_at
 */
class Unit extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = ['company_id', 'code', 'name', 'dimension', 'base_numerator', 'base_denominator', 'rec20_code', 'aliases', 'is_default', 'archived_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'dimension' => UnitDimension::class,
            'base_numerator' => 'integer',
            'base_denominator' => 'integer',
            'aliases' => 'array',
            'is_default' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
