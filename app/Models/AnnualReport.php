<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * What a person writes in an annual report; the figures are read from the
 * books every time the report is shown. The k3_* columns belong to a K3
 * report only (ADR-059 A.2.7, SaveK3ReportInputs); the K2 report ignores them.
 *
 * @property int $id
 * @property int $company_id
 * @property int $fiscal_year_id
 * @property ?string $business_description
 * @property ?string $significant_events
 * @property Money $dividend_ore
 * @property int $average_employees
 * @property ?string $average_employees_fte full-time equivalents with two decimals, for the size test (ADR-059 A.2.5)
 * @property ?string $signing_place
 * @property ?CarbonImmutable $signing_date
 * @property list<array{name: string, role: string}> $signatories
 * @property ?CarbonImmutable $adoption_date
 * @property ?string $adoption_decision
 * @property ?string $certifier_name
 * @property ?string $certifier_role
 * @property ?array<string, int> $pledged_assets öre per PledgedAssetKind value
 * @property ?string $pledged_other_text
 * @property ?int $contingent_liabilities_ore
 * @property ?string $contingent_liabilities_text
 * @property ?string $group_commitments
 * @property ?string $events_after
 * @property bool $events_effect_unknown
 * @property ?string $net_sales_comment
 * @property ?string $k3_external_factors K3 3.7, the K3 management report only (ADR-059 A.2.7)
 * @property ?string $k3_special_circumstances
 * @property ?string $k3_ownership_changes
 * @property ?list<array{name: string, org_number: ?string, birth_year: ?int, votes_bp: int, capital_bp: int}> $k3_major_owners null until answered; [] means no owner above ten per cent
 * @property ?int $k3_long_term_liabilities_ore liabilities due after five years (ÅRL 5 kap. 13 §)
 * @property ?string $k3_off_balance_arrangements
 * @property ?string $k3_loans_to_executives
 * @property ?list<array{description: string, amount_ore: int}> $k3_exceptional_items
 * @property ?string $k3_parent_company
 * @property ?string $k3_related_party_transactions
 * @property ?string $k3_departures
 * @property ?string $k3_goodwill_note
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
        'average_employees_fte',
        'signing_place',
        'signing_date',
        'signatories',
        'adoption_date',
        'adoption_decision',
        'certifier_name',
        'certifier_role',
        'pledged_assets',
        'pledged_other_text',
        'contingent_liabilities_ore',
        'contingent_liabilities_text',
        'group_commitments',
        'events_after',
        'events_effect_unknown',
        'net_sales_comment',
        'k3_external_factors',
        'k3_special_circumstances',
        'k3_ownership_changes',
        'k3_major_owners',
        'k3_long_term_liabilities_ore',
        'k3_off_balance_arrangements',
        'k3_loans_to_executives',
        'k3_exceptional_items',
        'k3_parent_company',
        'k3_related_party_transactions',
        'k3_departures',
        'k3_goodwill_note',
        'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'dividend_ore' => MoneyCast::class,
            'average_employees' => 'integer',
            'average_employees_fte' => 'decimal:2',
            'signing_date' => 'immutable_date',
            'signatories' => 'array',
            'adoption_date' => 'immutable_date',
            'pledged_assets' => 'array',
            'contingent_liabilities_ore' => 'integer',
            'events_effect_unknown' => 'boolean',
            'k3_major_owners' => 'array',
            'k3_long_term_liabilities_ore' => 'integer',
            'k3_exceptional_items' => 'array',
        ];
    }
}
