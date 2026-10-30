<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Models\CostCenter;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An employment of an employee: form, salary, extent, schedule and dimensions.
 *
 * @property int $employee_id
 * @property CarbonImmutable $start_date
 * @property ?CarbonImmutable $end_date
 * @property string $employment_type
 * @property string $salary_form
 * @property ?Money $monthly_salary_ore
 * @property ?Money $hourly_rate_ore
 * @property int $employment_rate_bp
 * @property ?string $position_title
 * @property ?int $cost_center_id
 * @property ?string $weekly_hours
 * @property ?int $work_schedule_id
 * @property ?int $pension_plan_id
 * @property ?string $vacation_rule
 * @property ?int $vacation_days_per_year
 * @property bool $vacation_payout_with_salary
 */
class Employment extends Model
{
    use BelongsToCompany;

    protected $table = 'employments';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'employee_id',
        'start_date',
        'end_date',
        'employment_type',
        'salary_form',
        'monthly_salary_ore',
        'hourly_rate_ore',
        'employment_rate_bp',
        'weekly_hours',
        'work_schedule_id',
        'position_title',
        'cost_center_id',
        'project_id',
        'collective_agreement_id',
        'pension_plan_id',
        'vacation_rule',
        'vacation_days_per_year',
        'vacation_payout_with_salary',
        'payment_day',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'start_date' => 'immutable_date',
            'end_date' => 'immutable_date',
            'monthly_salary_ore' => MoneyCast::class,
            'hourly_rate_ore' => MoneyCast::class,
            'employment_rate_bp' => 'integer',
            'vacation_days_per_year' => 'integer',
            'vacation_payout_with_salary' => 'boolean',
            'payment_day' => 'integer',
        ];
    }

    /** @return BelongsTo<CostCenter, $this> */
    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
