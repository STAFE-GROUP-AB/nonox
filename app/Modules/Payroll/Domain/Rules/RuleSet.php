<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Rules;

use App\Modules\Payroll\Models\PayrollRuleVerification;
use App\Modules\Payroll\Models\PayrollRuleVersion;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The payroll rules valid on one date (normally the payment date), loaded in
 * two queries. Only verified versions count. A missing or unverified rule
 * throws MissingPayrollRuleException; there is no default.
 */
final class RuleSet
{
    /**
     * Every key a payroll run may need (PAYROLL_MODULE.md B.6).
     */
    public const array REQUIRED_KEYS = [
        'employer_contribution.full',
        'employer_contribution.reduced_by_birthyear',
        'employer_contribution.youth_reduction',
        'employer_contribution.min_annual_basis',
        'employer_contribution.regional_support',
        'special_payroll_tax.pension',
        'sink.rate',
        'prisbasbelopp',
        'inkomstbasbelopp',
        'benefit.meal.free_day',
        'benefit.meal.breakfast',
        'benefit.meal.lunch_or_dinner',
        'benefit.car.formula',
        'per_diem.domestic.full',
        'per_diem.domestic.half',
        'per_diem.domestic.night',
        'per_diem.domestic.meal_reductions',
        'per_diem.foreign.by_country',
        'mileage.own_car_tax_free_per_mil',
        'mileage.company_car_fossil',
        'mileage.company_car_electric',
        'sick_pay.percentage',
        'sick_pay.karensavdrag_percent_of_weekly',
        'sick_pay.employer_period_days',
        'sick_pay.reinfection_window_days',
        'sick_pay.certificate_from_day',
        'vacation.minimum_days',
        'vacation.supplement_sammaloneregeln',
        'vacation.percent_rule',
        'vacation.max_saved_days',
        'vacation.save_years',
        'gift.tax_free_limits',
        'wellness.tax_free_limit',
        'agi.field_codes',
        'agi.deadlines',
        'agi.schema_version',
        'tax.rounding',
        'tax.one_off',
        'tax.one_off_without_regular_salary',
        'a_sink.rate',
    ];

    /**
     * @param  Collection<string, PayrollRuleVersion>  $verified  by key
     * @param  list<string>  $unverified  keys that exist only unverified
     */
    private function __construct(
        public readonly CarbonImmutable $date,
        private readonly Collection $verified,
        private readonly array $unverified,
    ) {}

    /**
     * The rules for a date. A version counts when it is verified for the
     * whole installation, or by the current company.
     */
    public static function for(CarbonImmutable $date): self
    {
        $companyId = CurrentCompany::id();
        $companyChecked = $companyId === null ? [] : PayrollRuleVerification::query()->pluck('payroll_rule_version_id')->all();

        $versions = PayrollRuleVersion::query()
            ->whereDate('valid_from', '<=', $date->toDateString())
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date->toDateString()))
            ->orderBy('valid_from')
            ->get();

        $verified = $versions->filter(fn (PayrollRuleVersion $version): bool => $version->isVerified() || in_array($version->id, $companyChecked, true))->keyBy('key');
        $unverified = array_values(array_diff($versions->pluck('key')->unique()->all(), $verified->keys()->all()));

        return new self($date, $verified, $unverified);
    }

    /**
     * @throws MissingPayrollRuleException
     */
    public function get(string $key): mixed
    {
        return $this->version($key)->value;
    }

    /**
     * @throws MissingPayrollRuleException
     */
    public function version(string $key): PayrollRuleVersion
    {
        return $this->verified->get($key)
            ?? throw new MissingPayrollRuleException($key, $this->date, in_array($key, $this->unverified, true));
    }

    /**
     * The required keys that cannot be used on this date, for the run's
     * checklist.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        return array_values(array_filter(self::REQUIRED_KEYS, fn (string $key): bool => ! $this->verified->has($key)));
    }

    /**
     * A fingerprint of the rule versions used, stored on the run so the same
     * inputs and versions can be shown to give the same result.
     */
    public function snapshotHash(): string
    {
        return hash('sha256', (string) json_encode($this->verified->sortKeys()->map(fn (PayrollRuleVersion $version): array => [
            'id' => $version->id,
            'value' => $version->value,
        ])->all()));
    }
}
