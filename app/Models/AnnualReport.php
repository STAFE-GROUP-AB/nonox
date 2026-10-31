<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * What a person writes in a K2 annual report; the figures are read from the
 * books every time the report is shown.
 *
 * @property int $id
 * @property int $company_id
 * @property int $fiscal_year_id
 * @property ?string $business_description
 * @property ?string $significant_events
 * @property Money $dividend_ore
 * @property int $average_employees
 * @property ?string $signing_place
 * @property ?CarbonImmutable $signing_date
 * @property list<array{name: string, role: string}> $signatories
 * @property int $updated_by
 */
class AnnualReport extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'fiscal_year_id',
        'business_description',
        'significant_events',
        'dividend_ore',
        'average_employees',
        'signing_place',
        'signing_date',
        'signatories',
        'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'dividend_ore' => MoneyCast::class,
            'average_employees' => 'integer',
            'signing_date' => 'immutable_date',
            'signatories' => 'array',
        ];
    }
}
