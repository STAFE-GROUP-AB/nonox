<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An employee's closed semesterår.
 *
 * @property int $id
 * @property int $company_id
 * @property int $employee_id
 * @property CarbonImmutable $year_start
 * @property CarbonImmutable $year_end
 * @property int $days_earned
 * @property int $days_taken
 * @property int $saved_days_used
 * @property int $days_saved
 * @property int $days_paid_out
 * @property int $payout_ore
 * @property int $variable_basis_ore
 * @property int $variable_pay_ore
 * @property int $closed_by
 * @property ?int $payroll_run_id
 */
class VacationYearClosing extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'employee_id',
        'year_start',
        'year_end',
        'days_earned',
        'days_taken',
        'saved_days_used',
        'days_saved',
        'days_paid_out',
        'payout_ore',
        'variable_basis_ore',
        'variable_pay_ore',
        'closed_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'year_start' => 'immutable_date',
            'year_end' => 'immutable_date',
            'days_earned' => 'integer',
            'days_taken' => 'integer',
            'saved_days_used' => 'integer',
            'days_saved' => 'integer',
            'days_paid_out' => 'integer',
            'payout_ore' => 'integer',
            'variable_basis_ore' => 'integer',
            'variable_pay_ore' => 'integer',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
