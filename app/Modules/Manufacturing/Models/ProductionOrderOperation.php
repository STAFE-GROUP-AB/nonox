<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An operation of a production order's copy (ADR-071 §8): the standard
 * minutes for the batch and the planned minutes for the whole order. The
 * actual minutes are the sum of its labour entries (finish now, the
 * workshop terminal in M5); the planning calendar (M6) adds its own
 * columns here: a workstation, the latest estimate of the minutes that
 * remain, and the waiting time after it (drying), each overriding the
 * operation's own.
 *
 * @property int $id
 * @property int $company_id
 * @property int $production_order_id
 * @property int $position
 * @property int $operation_id
 * @property int $minutes
 * @property int $planned_minutes
 * @property ?int $workstation_id
 * @property ?int $remaining_minutes_override
 * @property ?int $wait_minutes
 * @property ?string $note
 */
class ProductionOrderOperation extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'production_order_id',
        'position',
        'operation_id',
        'minutes',
        'planned_minutes',
        'workstation_id',
        'remaining_minutes_override',
        'wait_minutes',
        'note',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'minutes' => 'integer',
            'planned_minutes' => 'integer',
            'remaining_minutes_override' => 'integer',
            'wait_minutes' => 'integer',
        ];
    }

    /** @return BelongsTo<ProductionOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    /** @return BelongsTo<Operation, $this> */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    /** @return BelongsTo<Workstation, $this> */
    public function workstation(): BelongsTo
    {
        return $this->belongsTo(Workstation::class);
    }

    /** @return HasMany<ProductionLabourEntry, $this> */
    public function labourEntries(): HasMany
    {
        return $this->hasMany(ProductionLabourEntry::class)->orderBy('id');
    }
}
