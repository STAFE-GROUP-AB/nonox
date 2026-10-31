<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Models;

use App\Modules\Payroll\Models\Employee;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hours per day worked out from the staff ledger, proposed for a time report
 * in payroll. A manager approves it, which writes time reports; the ledger
 * and the time reports stay in separate tables.
 *
 * @property int $id
 * @property int $company_id
 * @property int $employee_id
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property array<string, float> $days
 * @property string $status
 * @property int $proposed_by
 * @property ?int $decided_by
 * @property ?CarbonImmutable $decided_at
 */
class TimeProposal extends Model
{
    use BelongsToCompany;

    public const string PROPOSED = 'PROPOSED';

    public const string APPROVED = 'APPROVED';

    public const string REJECTED = 'REJECTED';

    protected $table = 'staff_ledger_time_proposals';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'employee_id',
        'period_start',
        'period_end',
        'days',
        'status',
        'proposed_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'days' => 'array',
            'decided_at' => 'immutable_datetime',
        ];
    }

    public function totalHours(): float
    {
        return round(array_sum($this->days), 2);
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
