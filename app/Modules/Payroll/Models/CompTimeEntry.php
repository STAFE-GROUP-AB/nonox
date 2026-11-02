<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change of an employee's comp-time balance (komptid), in minutes of
 * leave: EARNED (+), TAKEN (−), PAID_OUT (−) or ADJUSTMENT (±). TAKEN by an
 * hourly-paid employee and every PAID_OUT are paid by the regular payroll
 * run of the date's month (CompTimeLedger::payInputs).
 *
 * @property int $id
 * @property int $company_id
 * @property int $employee_id
 * @property CarbonImmutable $date
 * @property string $kind
 * @property int $minutes
 * @property ?int $worked_minutes
 * @property ?string $factor
 * @property ?string $pay_code
 * @property ?string $source_type
 * @property ?int $source_id
 * @property ?string $note
 * @property ?int $created_by
 */
class CompTimeEntry extends Model
{
    use BelongsToCompany;

    public const string EARNED = 'EARNED';

    public const string TAKEN = 'TAKEN';

    public const string PAID_OUT = 'PAID_OUT';

    public const string ADJUSTMENT = 'ADJUSTMENT';

    public const array KINDS = [self::EARNED, self::TAKEN, self::PAID_OUT, self::ADJUSTMENT];

    /** Source of an EARNED entry written when Planering sends a period to payroll. */
    public const string SOURCE_PLANNING = 'planning_attestation';

    protected $table = 'comp_time_entries';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'employee_id',
        'date',
        'kind',
        'minutes',
        'worked_minutes',
        'factor',
        'pay_code',
        'source_type',
        'source_id',
        'note',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'minutes' => 'integer',
            'worked_minutes' => 'integer',
            'source_id' => 'integer',
            'note' => 'encrypted',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            self::EARNED => __('Intjänad'),
            self::TAKEN => __('Uttagen ledighet'),
            self::PAID_OUT => __('Utbetald'),
            default => __('Justering'),
        };
    }
}
