<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one payslip did in one sjuklöneperiod (sjuklönelagen 6–7 §§): the
 * karensavdrag the period is due, the part this payslip deducted, and the
 * days paid under the collective agreement's deduction from day 15. The
 * next run reads the approved rows to carry the rest of a karensavdrag, to
 * count the occasions with a karensavdrag in the last twelve months and the
 * agreement's days from day 15. Frozen with its payroll run.
 *
 * @property int $id
 * @property int $company_id
 * @property int $payroll_run_id
 * @property int $payslip_id
 * @property int $employee_id
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $last_day
 * @property int $day_count
 * @property int $karens_full_ore
 * @property int $karens_deducted_ore
 * @property list<string> $long_sick_dates
 */
class SickPayPeriod extends Model
{
    use BelongsToCompany;

    protected $table = 'sick_pay_periods';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'payroll_run_id',
        'payslip_id',
        'employee_id',
        'period_start',
        'last_day',
        'day_count',
        'karens_full_ore',
        'karens_deducted_ore',
        'long_sick_dates',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_date',
            'last_day' => 'immutable_date',
            'day_count' => 'integer',
            'karens_full_ore' => 'integer',
            'karens_deducted_ore' => 'integer',
            'long_sick_dates' => 'array',
        ];
    }

    /** @return BelongsTo<PayrollRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    /** @return BelongsTo<Payslip, $this> */
    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }
}
