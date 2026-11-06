<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * What a company's K10 underlag holds for all owners in one inkomstår
 * (ADR-062): all shares at the start of the year, the cash pay covered by
 * state wage grants (IL 57 kap. 18 §), the year's dividend when a person
 * enters it instead of the books' proposal, and the person's confirmation
 * that the company is a fåmansföretag with qualified shares.
 *
 * @property int $id
 * @property int $company_id
 * @property int $income_year
 * @property ?int $shares_total
 * @property ?int $wage_subsidy_kronor
 * @property ?int $dividend_total_kronor
 * @property bool $qualified_confirmed
 * @property int $updated_by
 */
class K10Year extends Model
{
    use BelongsToCompany;

    protected $table = 'k10_years';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'income_year',
        'shares_total',
        'wage_subsidy_kronor',
        'dividend_total_kronor',
        'qualified_confirmed',
        'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'income_year' => 'integer',
            'shares_total' => 'integer',
            'wage_subsidy_kronor' => 'integer',
            'dividend_total_kronor' => 'integer',
            'qualified_confirmed' => 'boolean',
        ];
    }
}
