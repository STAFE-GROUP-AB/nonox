<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * An employee's vacation balance for a semesterår as the earlier payroll
 * system had it: the paid days earned, the days already taken there, the
 * saved days brought in and, for procentregeln, the vacation-pay basis.
 *
 * @property int $id
 * @property int $company_id
 * @property int $employee_id
 * @property CarbonImmutable $year_start
 * @property int $days_earned
 * @property int $days_taken
 * @property int $days_saved
 * @property ?int $basis_ore
 */
class VacationOpeningBalance extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'employee_id',
        'year_start',
        'days_earned',
        'days_taken',
        'days_saved',
        'basis_ore',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'year_start' => 'immutable_date',
            'days_earned' => 'integer',
            'days_taken' => 'integer',
            'days_saved' => 'integer',
            'basis_ore' => 'integer',
        ];
    }
}
