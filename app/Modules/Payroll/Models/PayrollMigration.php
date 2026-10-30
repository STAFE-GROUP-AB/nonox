<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A company's move of payroll data from an earlier system. While it is in
 * progress no payroll run can be calculated, so the year-to-date totals are
 * in place before the first run (PAYROLL_MODULE.md B.16).
 *
 * @property int $id
 * @property int $company_id
 * @property string $status
 * @property int $year
 * @property array<string, mixed> $report
 * @property int $started_by
 * @property ?int $completed_by
 * @property ?CarbonImmutable $completed_at
 */
class PayrollMigration extends Model
{
    use BelongsToCompany;

    public const string IN_PROGRESS = 'IN_PROGRESS';

    public const string COMPLETE = 'COMPLETE';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'year',
        'report',
        'started_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::IN_PROGRESS,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'report' => 'array',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public static function inProgress(): bool
    {
        return self::query()->where('status', self::IN_PROGRESS)->exists();
    }
}
