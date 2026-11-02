<?php

declare(strict_types=1);

namespace App\Modules\Agreements\Domain;

use App\Modules\Payroll\Domain\Engine\PayInput;
use App\Modules\Payroll\Domain\Engine\PayrollBlockedException;
use App\Modules\Payroll\Domain\Engine\PayrollContext;
use App\Modules\Payroll\Domain\Engine\Steps\Additions;
use App\Modules\Payroll\Domain\PayCodeCatalog;
use App\Modules\Payroll\Domain\Rules\MissingPayrollRuleException;
use App\Modules\Payroll\Domain\Units;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * The agreement's terms that are not hours at a rate (ADR-033 A9–A11),
 * applied to the context before the calculation:
 *
 * - vacation (A9): the agreement's percentage for procentregeln and
 *   semesterersättning, its semestertillägg under sammalöneregeln, and its
 *   lowest vacation pay per day, handed to payroll's own Vacation step;
 * - pension (A10): when the employment has no pension plan of its own, the
 *   agreement's plan (SAF-LO, ITP1) from the verified payroll rule, with
 *   its ages and ceiling, handed to payroll's own Pension step;
 * - insurances (A10): the premiums from the verified payroll rule, for
 *   AgreementCostsStep;
 * - commission with guarantee (A11): the turnover entered for the period
 *   becomes the commission above the guaranteed salary;
 * - meal deduction (A11): the meals entered become the employee's own
 *   payment (a net deduction) and the meal benefit at Skatteverket's value,
 *   which payroll's Allowances step reduces by that payment.
 *
 * The values come only from the verified agreement version (with the
 * company's verified local overrides) and verified payroll rules. Pure.
 */
final class AgreementPayTerms
{
    /** Code kinds the pricer leaves for this class. */
    public const array TRANSFORMED_KINDS = ['COMMISSION', 'MEAL'];

    /**
     * @throws PayrollBlockedException
     */
    public function apply(PayrollContext $context, AgreementContext $agreement): PayrollContext
    {
        $version = $agreement->versionOn($context->periodEnd) ?? $agreement->versionOn($context->periodStart);

        if ($version === null) {
            return $context;
        }

        $rules = $version['rules'];
        $about = ['version_id' => $version['id'], 'key' => $agreement->agreementKey, 'variant' => $agreement->variant, 'name' => $rules->shortName(), 'version_valid_from' => $version['valid_from']->toDateString()];
        $warnings = [];

        $context = $context->withInputs($this->inputs($context, $agreement));
        $context = $this->vacation($context, $agreement, $rules, $about, $warnings);
        $context = $this->pension($context, $rules, $about);

        return $context->withExtension(AgreementCosts::KEY, $this->insurances($context, $agreement, $rules, $about, $warnings));
    }

    /**
     * Commission and meal inputs become pay lines; every other input is
     * left as it is.
     *
     * @return list<PayInput>
     */
    private function inputs(PayrollContext $context, AgreementContext $agreement): array
    {
        $kept = [];
        $commission = [];

        foreach ($context->inputs as $input) {
            $version = ($input->explanation[Additions::AGREEMENT_PRICED] ?? false) === true ? $agreement->versionOn($this->day($context, $input)) : null;
            $code = $version === null ? null : $version['rules']->code($input->code);

            match ($code['kind'] ?? null) {
                'COMMISSION' => $commission[$input->code][] = $input,
                'MEAL' => array_push($kept, ...$this->meal($context, $agreement, $input)),
                default => $kept[] = $input,
            };
        }

        foreach ($commission as $inputs) {
            $kept[] = $this->commission($context, $agreement, $inputs);
        }

        return $kept;
    }

    /**
     * The commission on the turnover of the period, paid as far as it
     * exceeds the guaranteed salary when the agreement's guarantee is the
     * salary, or in full when there is no guarantee.
     *
     * @param  non-empty-list<PayInput>  $inputs  the period's turnover lines of one commission code
     */
    private function commission(PayrollContext $context, AgreementContext $agreement, array $inputs): PayInput
    {
        $first = $inputs[0];
        $commission = 0;
        $turnover = 0;
        $steps = [];
        $firstDay = $this->day($context, $first);
        $rules = ($agreement->versionOn($firstDay) ?? throw new PayrollBlockedException(__(':item: ingen kontrollerad version av avtalet gäller :date.', ['item' => $first->description, 'date' => $firstDay->toDateString()])))['rules'];

        foreach ($inputs as $input) {
            $day = $this->day($context, $input);
            $version = $agreement->versionOn($day) ?? throw new PayrollBlockedException(__(':item: ingen kontrollerad version av avtalet gäller :date.', ['item' => $input->description, 'date' => $day->toDateString()]));
            $stepRules = $version['rules'];
            $step = $stepRules->rate($input->code, $day) ?? throw new PayrollBlockedException(__(':item: avtalet har ingen provisionssats för :date.', ['item' => $input->description, 'date' => $day->toDateString()]));
            $amount = BigDecimal::of($input->quantity ?? '0')->multipliedBy(Units::ORE)->toScale(0, RoundingMode::HalfUp)->toInt();
            $turnover += $amount;
            $commission += BigDecimal::of($amount)->multipliedBy((int) $step['rate']['bp'])->dividedBy(Units::BASIS_POINTS, 0, RoundingMode::HalfUp)->toInt();
            $steps[] = ['rate_from' => $step['valid_from'], 'bp' => (int) $step['rate']['bp']];
        }

        $code = (array) $rules->code($first->code);
        $guarantee = ($code['guarantee'] ?? 'SALARY') === 'SALARY' ? $this->guaranteedSalary($context) : 0;
        $paid = max(0, $commission - $guarantee);
        $source = (string) ($code['source'] ?? '');
        $document = $rules->source($source);

        return new PayInput($first->code, $first->description, $first->category, $first->taxable, $first->taxMethod, $first->contributionBasis, $first->vacationBasis,
            null, null, $paid,
            ['agreement' => array_filter([
                'key' => $agreement->agreementKey,
                'variant' => $agreement->variant,
                'name' => $rules->shortName(),
                'code' => $first->code,
                'code_name' => (string) ($code['name'] ?? $first->code),
                'commission' => ['turnover_ore' => $turnover, 'steps' => $steps, 'commission_ore' => $commission, 'guarantee' => (string) ($code['guarantee'] ?? 'SALARY'), 'guaranteed_ore' => $guarantee],
                'source' => $source,
                'source_title' => $document['title'] ?? null,
                'source_url' => $document['url'] ?? null,
                'local_override' => isset($rules->rules['local_overrides']["codes.{$first->code}.rate"]) ? true : null,
            ], fn (mixed $value): bool => $value !== null)],
        );
    }

    /**
     * The salary the commission is guaranteed against: the monthly salary
     * at the employment rate, or for hourly pay the hours paid in the run.
     * TODO(legal-verify): what the guarantee is measured against when the
     * employee was absent part of the month.
     */
    private function guaranteedSalary(PayrollContext $context): int
    {
        if ($context->salaryForm === 'MONTHLY') {
            return BigDecimal::of((int) $context->monthlySalaryOre)->multipliedBy($context->employmentRateBp)->dividedBy(Units::BASIS_POINTS, 0, RoundingMode::HalfUp)->toInt();
        }

        return array_sum(array_map(
            fn (PayInput $input): int => BigDecimal::of($input->quantity ?? '0')->multipliedBy($input->rateOre ?? $context->hourlyRateOre ?? 0)->toScale(0, RoundingMode::HalfUp)->toInt(),
            array_values(array_filter($context->inputs, fn (PayInput $input): bool => $input->code === 'TIMLON' && $input->amountOre === null)),
        ));
    }

    /**
     * Meals under the agreement: the employee pays the agreement's amount
     * per day through a net deduction, and the meal benefit is valued at
     * Skatteverket's value less that payment (Allowances).
     * TODO(legal-verify): which of Skatteverket's meal values applies to the
     * agreement's meal per working day (benefit_code in the data).
     *
     * @return list<PayInput>
     */
    private function meal(PayrollContext $context, AgreementContext $agreement, PayInput $input): array
    {
        $day = $this->day($context, $input);
        $version = $agreement->versionOn($day) ?? throw new PayrollBlockedException(__(':item: ingen kontrollerad version av avtalet gäller :date.', ['item' => $input->description, 'date' => $day->toDateString()]));
        $rules = $version['rules'];
        $code = (array) $rules->code($input->code);
        $step = $rules->rate($input->code, $day) ?? throw new PayrollBlockedException(__(':item: avtalet har inget kostavdrag för :date.', ['item' => $input->description, 'date' => $day->toDateString()]));
        $days = $input->quantity ?? '0';
        $deduction = BigDecimal::of($days)->multipliedBy((int) $step['rate']['ore'])->toScale(0, RoundingMode::HalfUp)->toInt();
        $benefitCode = (string) $code['benefit_code'];
        $benefit = PayCodeCatalog::CODES[$benefitCode];
        $payment = PayCodeCatalog::CODES['FORMAN_EGENBETALNING'];
        $source = (string) ($code['source'] ?? '');
        $about = ['agreement' => ['key' => $agreement->agreementKey, 'variant' => $agreement->variant, 'name' => $rules->shortName(), 'code' => $input->code, 'rate_from' => $step['valid_from'], 'ore_per_day' => (int) $step['rate']['ore'], 'days' => $days, 'source' => $source, 'source_title' => $rules->source($source)['title'] ?? null]];

        return [
            new PayInput($benefitCode, $benefit['name_sv'], $benefit['category'], $benefit['taxable'], $benefit['tax_method'], $benefit['contribution'], $benefit['vacation'], $days),
            new PayInput('FORMAN_EGENBETALNING', __('Kostavdrag enligt :agreement', ['agreement' => $rules->shortName()]), $payment['category'], $payment['taxable'], $payment['tax_method'], $payment['contribution'], $payment['vacation'],
                $days, (int) $step['rate']['ore'], $deduction, $about),
        ];
    }

    /**
     * @param  array<string, mixed>  $about
     * @param  list<string>  $warnings
     */
    private function vacation(PayrollContext $context, AgreementContext $agreement, AgreementRules $rules, array $about, array &$warnings): PayrollContext
    {
        $terms = $rules->vacation();

        if ($context->vacation === null || $terms === []) {
            return $context;
        }

        $expected = $terms['rule_monthly'] ?? null;

        if ($context->salaryForm === 'MONTHLY' && is_string($expected) && $expected !== $context->vacation->rule) {
            $warnings[] = self::text(__(':agreement anger :expected för månadsavlönade, men anställningen har :actual. Kontrollera semesterregeln under Lön → Anställda.', [
                'agreement' => $rules->shortName(),
                'expected' => $expected === 'SAMMALONEREGELN' ? __('sammalöneregeln') : __('procentregeln'),
                'actual' => $context->vacation->rule === 'SAMMALONEREGELN' ? __('sammalöneregeln') : __('procentregeln'),
            ]));
        }

        $minimum = null;
        $minimumTerms = (array) ($terms['minimum_daily'] ?? []);

        if ($minimumTerms !== [] && $this->employedMonths($context) >= (int) ($minimumTerms['min_employment_months'] ?? 0)) {
            $age = (int) $context->birthDate->diffInYears($context->periodEnd);
            $experience = MinimumWageCheck::experienceMonths($agreement->experienceFrom, $context->birthDate, $context->periodEnd, null);
            $row = $rules->minimumDailyVacationPay($age, $context->periodEnd, $experience);

            if ($row !== null && ($minimumTerms['part_time'] ?? null) === 'FULL_TIME_ONLY' && $context->employmentRateBp < Units::BASIS_POINTS) {
                $warnings[] = self::text(__(':agreement har ett garantibelopp för semesterlön som för deltid räknas på arbetade timmar. NONOX räknar inte det ännu; kontrollera semesterlönen för deltid.', ['agreement' => $rules->shortName()]));
            } elseif ($row !== null) {
                $minimum = BigDecimal::of($row['ore'])->multipliedBy($context->employmentRateBp)->dividedBy(Units::BASIS_POINTS, 0, RoundingMode::HalfUp)->toInt();
            }
        }

        $source = (string) ($terms['source'] ?? '');
        $local = array_keys(array_filter((array) ($rules->rules['local_overrides'] ?? []), fn (mixed $value, string $path): bool => str_starts_with($path, 'vacation.'), ARRAY_FILTER_USE_BOTH));

        return $context->withVacation($context->vacation->withAgreementTerms(
            isset($terms['percent_bp']) ? (int) $terms['percent_bp'] : null,
            isset($terms['supplement_bp_per_day']) ? (int) $terms['supplement_bp_per_day'] : null,
            $minimum,
            array_filter([...$about, 'source' => $source, 'source_title' => $rules->source($source)['title'] ?? null, 'local_override' => $local === [] ? null : $local], fn (mixed $value): bool => $value !== null),
        ));
    }

    /**
     * @param  array<string, mixed>  $about
     *
     * @throws PayrollBlockedException
     */
    private function pension(PayrollContext $context, AgreementRules $rules, array $about): PayrollContext
    {
        $key = $rules->pension()['rule_key'] ?? null;

        if (! is_string($key) || $context->pension !== null) {
            return $context;
        }

        [$value, $versionId] = $this->rule($context, $key, $rules->shortName());

        return $context->withPension([
            'name' => (string) ($value['name'] ?? $key),
            'below_bp' => (int) $value['below_bp'],
            'above_bp' => (int) $value['above_bp'],
            'threshold_ibb' => (string) ($value['threshold_ibb'] ?? '7.5'),
            'ceiling_ibb' => isset($value['ceiling_ibb']) ? (string) $value['ceiling_ibb'] : null,
            'from_age' => isset($value['from_age']) ? (int) $value['from_age'] : null,
            'to_age_exclusive' => isset($value['to_age_exclusive']) ? (int) $value['to_age_exclusive'] : null,
            'rule_version_id' => $versionId,
            'agreement' => [...$about, 'rule_key' => $key, 'source' => (string) ($rules->pension()['source'] ?? '')],
        ]);
    }

    /**
     * @param  array<string, mixed>  $about
     * @param  list<string>  $warnings
     *
     * @throws PayrollBlockedException
     */
    private function insurances(PayrollContext $context, AgreementContext $agreement, AgreementRules $rules, array $about, array $warnings): AgreementCosts
    {
        $terms = $rules->insurances();
        $key = $terms['rule_key'] ?? null;

        if (! is_string($key)) {
            return new AgreementCosts(warnings: $warnings);
        }

        [$value, $versionId] = $this->rule($context, $key, $rules->shortName());
        $components = [];

        foreach ((array) ($value['components'] ?? []) as $component) {
            $component = (array) $component;
            $bp = $agreement->variant === 'HANGAVTAL' && isset($component['hangavtal_bp']) ? (int) $component['hangavtal_bp'] : (int) ($component['bp'] ?? 0);

            if ($bp <= 0) {
                continue;
            }

            $cap = null;

            if (isset($component['cap_pbb'])) {
                [$pbb] = $this->rule($context, 'prisbasbelopp', $rules->shortName());
                $cap = BigDecimal::of((int) $pbb['ore'])->multipliedBy((string) $component['cap_pbb'])->dividedBy(Units::MONTHS_PER_YEAR, 0, RoundingMode::HalfUp)->toInt();
            }

            $components[] = [
                'key' => (string) $component['key'],
                'name' => (string) ($component['name'] ?? $component['key']),
                'bp' => $bp,
                'cap_ore' => $cap,
                'from_age' => isset($component['from_age']) ? (int) $component['from_age'] : null,
                'to_age_exclusive' => isset($component['to_age_exclusive']) ? (int) $component['to_age_exclusive'] : null,
            ];
        }

        $fee = isset($terms['admin_fee_bp']) ? ['bp' => (int) $terms['admin_fee_bp'], 'source' => (string) ($terms['admin_fee_source'] ?? $terms['source'] ?? '')] : null;

        return new AgreementCosts($components, $versionId, $fee, [...$about, 'rule_key' => $key, 'source' => (string) ($terms['source'] ?? '')], $warnings);
    }

    /**
     * A verified payroll rule the agreement points at, or a block that says
     * which one is missing.
     *
     * @return array{array<string, mixed>, int}
     *
     * @throws PayrollBlockedException
     */
    private function rule(PayrollContext $context, string $key, string $agreement): array
    {
        try {
            return [(array) $context->rules->get($key), $context->rules->version($key)->id];
        } catch (MissingPayrollRuleException) {
            throw new PayrollBlockedException(__('Regeln :rule som :agreement hänvisar till är inte kontrollerad för :date. Öppna Lön → Regler, jämför värdet med källan och markera det som kontrollerat.', [
                'rule' => $key,
                'agreement' => $agreement,
                'date' => $context->rules->date->toDateString(),
            ]));
        }
    }

    /**
     * A translated message as text (the translator can return an array for
     * a key that names a group).
     *
     * @param  array<mixed>|string  $text
     */
    private static function text(array|string $text): string
    {
        return is_string($text) ? $text : '';
    }

    private function employedMonths(PayrollContext $context): int
    {
        return $context->employmentStart->greaterThan($context->periodEnd) ? 0 : (int) $context->employmentStart->diffInMonths($context->periodEnd->addDay());
    }

    private function day(PayrollContext $context, PayInput $input): CarbonImmutable
    {
        return isset($input->explanation['date_from']) ? CarbonImmutable::parse((string) $input->explanation['date_from']) : $context->periodStart;
    }
}
