<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Models;

use App\Modules\Manufacturing\Domain\LockKind;
use App\Modules\Payroll\Models\Employee;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A planner's hold on an operation (ADR-071 §13.6): locked in place, moved
 * to a person, or split with a second person.
 *
 * @property int $id
 * @property int $company_id
 * @property int $production_order_operation_id
 * @property LockKind $kind
 * @property int $employee_id
 * @property ?int $workstation_id
 * @property ?CarbonImmutable $starts_at
 * @property ?int $minutes
 * @property ?int $run_id
 * @property ?int $created_by
 */
class ProductionPlanLock extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = ['company_id', 'production_order_operation_id', 'kind', 'employee_id', 'workstation_id', 'starts_at', 'minutes', 'run_id', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => LockKind::class,
            'starts_at' => 'immutable_datetime',
            'minutes' => 'integer',
        ];
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
}
