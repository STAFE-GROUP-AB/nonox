<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * The size figures of a fiscal year before the company's first year in
 * NONOX (ADR-059, addendum 2026-11-07, A.2.5), entered by a person for the
 * size test (ÅRL 1 kap. 3 §, BFNAR 2006:11).
 *
 * @property int $id
 * @property int $company_id
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable $end_date
 * @property ?string $average_employees_fte full-time equivalents, two decimals
 * @property Money $balance_sheet_total_ore
 * @property Money $net_turnover_ore
 * @property ?int $entered_by
 */
class PriorYearSizeFigure extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'start_date',
        'end_date',
        'average_employees_fte',
        'balance_sheet_total_ore',
        'net_turnover_ore',
        'entered_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'start_date' => 'immutable_date',
            'end_date' => 'immutable_date',
            'average_employees_fte' => 'decimal:2',
            'balance_sheet_total_ore' => MoneyCast::class,
            'net_turnover_ore' => MoneyCast::class,
        ];
    }
}
