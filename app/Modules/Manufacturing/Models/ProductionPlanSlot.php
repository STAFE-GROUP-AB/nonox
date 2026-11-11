<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Models;

use App\Modules\Payroll\Models\Employee;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An operation placed in a run (ADR-071 §13.8): the person, the workstation,
 * the first and last minute, and the stretches of working time it fills.
 *
 * @property int $id
 * @property int $company_id
 * @property int $run_id
 * @property int $production_order_id
 * @property int $production_order_operation_id
 * @property ?int $employee_id
 * @property ?int $workstation_id
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property int $minutes
 * @property list<array{0: int, 1: int}> $segments
 * @property bool $locked
 * @property int $part
 */
class ProductionPlanSlot extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['company_id', 'run_id', 'production_order_id', 'production_order_operation_id', 'employee_id', 'workstation_id', 'starts_at', 'ends_at', 'minutes', 'segments', 'locked', 'part'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'minutes' => 'integer',
            'segments' => 'array',
            'locked' => 'boolean',
            'part' => 'integer',
        ];
    }

    /** @return BelongsTo<ProductionOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    /** @return BelongsTo<ProductionOrderOperation, $this> */
    public function orderOperation(): BelongsTo
    {
        return $this->belongsTo(ProductionOrderOperation::class, 'production_order_operation_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<Workstation, $this> */
    public function workstation(): BelongsTo
    {
        return $this->belongsTo(Workstation::class);
    }
}
