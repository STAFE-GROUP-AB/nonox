<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A salary change from a date; a past date drives retroactive pay.
 *
 * @property int $id
 * @property int $company_id
 * @property int $employee_id
 * @property int $employment_id
 * @property CarbonImmutable $valid_from
 * @property ?Money $monthly_salary_ore
 * @property ?Money $hourly_rate_ore
 * @property ?string $reason
 */
class SalaryChange extends Model
{
    use BelongsToCompany;

    protected $table = 'salary_changes';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'employee_id',
        'employment_id',
        'valid_from',
        'monthly_salary_ore',
        'hourly_rate_ore',
        'reason',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'reason' => 'encrypted',
            'valid_from' => 'immutable_date',
            'monthly_salary_ore' => MoneyCast::class,
            'hourly_rate_ore' => MoneyCast::class,
        ];
    }
}
