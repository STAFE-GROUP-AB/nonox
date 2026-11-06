<?php

declare(strict_types=1);

namespace App\Modules\Planning\Models;

use App\Modules\Payroll\Models\Employee;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An employee's day in "Tid att attestera" (PLANNING_MODULE.md §4, §8).
 * OPEN: only the manager's own actual time is kept. ATTESTED: planned and
 * actual minutes, the payroll basis and the deviations are frozen as the
 * manager saw them. SENT: the basis is written as approved time reports
 * (time_report_ids) and the day is locked until ReopenAttestation.
 *
 * @property int $id
 * @property int $company_id
 * @property int $employee_id
 * @property CarbonImmutable $date
 * @property string $status
 * @property int $planned_minutes
 * @property int $actual_minutes
 * @property ?list<array{start: string, end: string, break_minutes: int}> $actual_override
 * @property ?array<string, int> $basis
 * @property ?list<array{code: string, message: string, blocking: bool, informational: bool}> $deviations
 * @property ?array{planned?: list<array{start: ?string, end: ?string, break_minutes: int}>, actual?: list<array{start: ?string, end: ?string, break_minutes: int}>, source?: string, ob?: array<string, int>, week?: array{from: string, overtime_minutes: int, extra_time_minutes: int, minutes: array<string, int>, time_report_ids: list<int>, comp_time_entry_ids?: list<int>, comp_time_minutes?: int, comp_time?: list<array<string, mixed>>, take_backs_not_sent?: ?list<array{code: string, date: string, minutes: int, reason: string}>}, week_choice?: string} $details
 * @property ?list<int> $time_report_ids
 * @property ?string $note
 * @property ?int $attested_by
 * @property ?CarbonImmutable $attested_at
 * @property ?int $sent_by
 * @property ?CarbonImmutable $sent_at
 * @property ?int $updated_by
 */
class Attestation extends Model
{
    use BelongsToCompany;

    public const string OPEN = 'OPEN';

    public const string ATTESTED = 'ATTESTED';

    public const string SENT = 'SENT';

    protected $table = 'planning_attestations';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'employee_id',
        'date',
        'status',
        'actual_override',
        'note',
        'updated_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::OPEN,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'date' => 'immutable_date',
            'planned_minutes' => 'integer',
            'actual_minutes' => 'integer',
            'actual_override' => 'array',
            'basis' => 'array',
            'deviations' => 'array',
            'details' => 'array',
            'time_report_ids' => 'array',
            'attested_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function isLocked(): bool
    {
        return $this->status !== self::OPEN;
    }
}
