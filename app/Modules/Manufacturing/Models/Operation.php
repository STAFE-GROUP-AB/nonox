<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Models;

use App\Modules\Inventory\Models\BomRow;
use App\Support\Tenancy\BelongsToCompany;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An operation (arbetssteg, ADR-071 §3): Kapning, Hyvling, Montering. Its
 * hourly cost, set by the company, prices the minutes of a bill of
 * materials (§6); its daily capacity is for the delivery estimate later.
 * Archived, never deleted, once a bill of materials uses it.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property int $hourly_cost_ore
 * @property ?int $daily_capacity_minutes
 * @property int $position
 * @property ?CarbonImmutable $archived_at
 */
class Operation extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'name',
        'hourly_cost_ore',
        'daily_capacity_minutes',
        'position',
        'archived_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'hourly_cost_ore' => 'integer',
            'daily_capacity_minutes' => 'integer',
            'position' => 'integer',
            'archived_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /** @return HasMany<BomRow, $this> the bill of materials rows that use the step */
    public function bomRows(): HasMany
    {
        return $this->hasMany(BomRow::class);
    }

    /** The cost of a number of minutes at this step's hourly cost, rounded half up to öre. */
    public static function labourOre(int $minutes, int $hourlyCostOre): int
    {
        return BigDecimal::of($minutes)->multipliedBy($hourlyCostOre)->dividedBy(60, 0, RoundingMode::HalfUp)->toInt();
    }
}
