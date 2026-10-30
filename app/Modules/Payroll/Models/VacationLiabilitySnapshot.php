<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * The vacation liability booked with a posted payroll run.
 *
 * @property int $id
 * @property int $company_id
 * @property int $payroll_run_id
 * @property CarbonImmutable $as_of
 * @property int $liability_ore
 * @property int $contributions_ore
 * @property array<int|string, array<string, mixed>> $employees
 */
class VacationLiabilitySnapshot extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'payroll_run_id',
        'as_of',
        'liability_ore',
        'contributions_ore',
        'employees',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'as_of' => 'immutable_date',
            'liability_ore' => 'integer',
            'contributions_ore' => 'integer',
            'employees' => 'array',
        ];
    }
}
