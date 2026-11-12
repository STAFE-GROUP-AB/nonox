<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Models;

use App\Modules\Manufacturing\Domain\PlanTrigger;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One computed plan (ADR-071 §13.4): the moment it was made from, the
 * horizon, and its slots and forecasts. The latest 30 are kept; older ones
 * are pruned unless a deviation or a lock refers to them.
 *
 * @property int $id
 * @property int $company_id
 * @property PlanTrigger $trigger
 * @property CarbonImmutable $planned_from
 * @property CarbonImmutable $horizon_until
 * @property int $order_count
 * @property int $slot_count
 * @property int $duration_ms
 * @property CarbonImmutable $created_at
 */
class ProductionPlanRun extends Model
{
    use BelongsToCompany;

    public const int KEEP = 30;

    public const ?string UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['company_id', 'trigger', 'planned_from', 'horizon_until', 'order_count', 'slot_count', 'duration_ms'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'trigger' => PlanTrigger::class,
            'planned_from' => 'immutable_datetime',
            'horizon_until' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'order_count' => 'integer',
            'slot_count' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    /** @return HasMany<ProductionPlanSlot, $this> */
    public function slots(): HasMany
    {
        return $this->hasMany(ProductionPlanSlot::class, 'run_id');
    }

    /** @return HasMany<ProductionPlanForecast, $this> */
    public function forecasts(): HasMany
    {
        return $this->hasMany(ProductionPlanForecast::class, 'run_id');
    }
}
