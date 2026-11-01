<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hours reported for a day and pay code. An approved report with the hourly
 * pay code is paid by the payroll run for its month, which claims it in
 * payroll_run_id; once that run is approved the report is frozen.
 *
 * @property int $id
 * @property int $company_id
 * @property int $employee_id
 * @property CarbonImmutable $date
 * @property string $hours
 * @property int $pay_code_id
 * @property string $status
 * @property ?int $payroll_run_id
 */
class TimeReport extends Model
{
    use BelongsToCompany;

    protected $table = 'time_reports';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'employee_id',
        'date',
        'hours',
        'pay_code_id',
        'project_id',
        'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
