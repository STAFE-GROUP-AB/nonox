<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Models;

use App\Modules\Payroll\Models\Employee;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A meal-benefit line proposed to payroll for one employee and month
 * (ADR-073 §7, Lead decision O5): the payroll administrator approves it
 * into a payroll run, where it becomes an ordinary input, or rejects it.
 * Never added to pay without that approval.
 *
 * @property int $id
 * @property int $company_id
 * @property string $period
 * @property int $employee_id
 * @property string $pay_code
 * @property ?string $quantity
 * @property ?int $amount_ore
 * @property array<string, mixed> $details
 * @property string $status
 * @property ?int $payroll_run_id
 * @property ?int $payroll_input_id
 * @property ?int $decided_by
 * @property ?int $created_by
 */
class MealPayrollProposal extends Model
{
    use BelongsToCompany;

    public const string PROPOSED = 'PROPOSED';

    public const string APPROVED = 'APPROVED';

    public const string REJECTED = 'REJECTED';

    /** Approved, then taken back out of a still open run because the meals changed. */
    public const string REPLACED = 'REPLACED';

    protected $table = 'kitchen_payroll_proposals';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'period',
        'employee_id',
        'pay_code',
        'quantity',
        'amount_ore',
        'details',
        'status',
        'payroll_run_id',
        'payroll_input_id',
        'decided_by',
        'decided_at',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_ore' => 'integer',
            'details' => 'array',
            'decided_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
