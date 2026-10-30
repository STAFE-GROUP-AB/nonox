<?php

declare(strict_types=1);

use App\Modules\Payroll\Domain\Engine\PayInput;
use App\Modules\Payroll\Domain\Engine\PayrollBlockedException;
use App\Modules\Payroll\Domain\Engine\PayrollCalculator;
use App\Modules\Payroll\Domain\Engine\PayrollContext;
use App\Modules\Payroll\Domain\Rules\RuleSet;
use App\Modules\Payroll\Models\PayrollRuleVersion;
use App\Modules\Payroll\Support\Tax\TaxTableImporter;
use Carbon\CarbonImmutable;

/*
 * Golden payslips (PAYROLL_MODULE.md B.17): each fixture in
 * tests/Fixtures/payroll/payslips gives a context and the amounts worked out
 * by hand. Tax comes from Skatteverket's 2026 table 33. The rules seeded here
 * carry the researched 2026 values but are test data, marked verified only
 * inside the test.
 */

beforeEach(function () {
    app(TaxTableImporter::class)->import(2026, 'tabell-33.txt', (string) file_get_contents(base_path('tests/Fixtures/payroll/skattetabell-2026-manad-tabell-33.txt')));

    foreach ([
        'employer_contribution.full' => ['bp' => 3142],
        'employer_contribution.reduced_by_birthyear' => ['bands' => [['born_to' => 1937, 'bp' => 0], ['born_from' => 1938, 'born_to' => 1958, 'bp' => 1021]]],
        'employer_contribution.youth_reduction' => ['applies' => true, 'born_from' => 2003, 'born_to' => 2007, 'bp' => 2081, 'monthly_cap_ore' => 2_500_000],
        'employer_contribution.min_annual_basis' => ['ore' => 100_000],
        'sink.rate' => ['bp' => 2250],
        'tax.rounding' => ['mode' => 'DOWN_TO_KRONA'],
    ] as $key => $value) {
        PayrollRuleVersion::create(['key' => $key, 'valid_from' => '2026-01-01', 'value' => $value, 'source_url' => 'https://example.test'])
            ->forceFill(['verified_by' => 'Testperson', 'verified_at' => now()])->save();
    }
});

/** @param  array<string, mixed>  $data */
function payrollContextFrom(array $data): PayrollContext
{
    $date = fn (?string $value): ?CarbonImmutable => $value === null ? null : CarbonImmutable::parse($value);

    return new PayrollContext(
        birthDate: CarbonImmutable::parse($data['birth_date']),
        taxResidence: $data['tax_residence'],
        taxTable: $data['tax_table'],
        taxColumn: $data['tax_column'],
        taxAdjustmentPercentBp: $data['tax_adjustment_percent_bp'],
        salaryForm: $data['salary_form'],
        monthlySalaryOre: $data['monthly_salary_ore'],
        hourlyRateOre: $data['hourly_rate_ore'],
        employmentRateBp: $data['employment_rate_bp'],
        employmentStart: CarbonImmutable::parse($data['employment_start']),
        employmentEnd: $date($data['employment_end']),
        periodStart: CarbonImmutable::parse($data['period_start']),
        periodEnd: CarbonImmutable::parse($data['period_end']),
        paymentDate: CarbonImmutable::parse($data['payment_date']),
        inputs: array_map(fn (array $input): PayInput => new PayInput(
            $input['code'], $input['description'], $input['category'], $input['taxable'], $input['tax_method'], $input['contribution_basis'], $input['vacation_basis'],
            $input['quantity'] ?? null, $input['rate_ore'] ?? null, $input['amount_ore'] ?? null,
        ), $data['inputs']),
        accumulators: $data['accumulators'],
        settings: $data['settings'],
        rules: RuleSet::for(CarbonImmutable::parse($data['payment_date'])),
    );
}

dataset('golden payslips', function (): array {
    $cases = [];

    foreach (glob(dirname(__DIR__, 2).'/Fixtures/payroll/payslips/*.json') ?: [] as $file) {
        $cases[basename($file, '.json')] = [$file];
    }

    return $cases;
});

it('calculates the golden payslip', function (string $file) {
    $fixture = json_decode((string) file_get_contents($file), true);
    $result = app(PayrollCalculator::class)->calculate(payrollContextFrom($fixture['context']));
    $expected = $fixture['expected'];

    expect([
        'gross_ore' => $result->gross(),
        'tax_ore' => $result->taxOre,
        'net_ore' => $result->netOre,
        'employer_contribution_ore' => $result->employerContributionOre,
    ])->toBe(array_intersect_key($expected, array_flip(['gross_ore', 'tax_ore', 'net_ore', 'employer_contribution_ore'])));

    if (isset($expected['carry_ore'])) {
        expect($result->accumulatorDeltas['negative_net_carry_ore'])->toBe($expected['carry_ore'])
            ->and($result->warnings)->not->toBeEmpty();
    }
})->with('golden payslips');

it('gives the same result for the same context', function () {
    $context = payrollContextFrom(json_decode((string) file_get_contents(base_path('tests/Fixtures/payroll/payslips/05-youth-reduction.json')), true)['context']);

    expect(serialize(app(PayrollCalculator::class)->calculate($context)))->toBe(serialize(app(PayrollCalculator::class)->calculate($context)));
});

it('blocks instead of guessing', function (array $change, string $message) {
    $fixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/payroll/payslips/01-full-month.json')), true);

    expect(fn () => app(PayrollCalculator::class)->calculate(payrollContextFrom([...$fixture['context'], ...$change])))
        ->toThrow(PayrollBlockedException::class, $message);
})->with([
    'no tax table' => [['tax_table' => null], 'Skattetabell och kolumn saknas'],
    'part month by schedule' => [['employment_start' => '2026-04-16', 'settings' => ['proration_method' => 'WORK_SCHEDULE']], 'arbetsschema'],
    'one-off payment' => [['inputs' => [['code' => 'BONUS', 'description' => 'Bonus', 'category' => 'ADDITION', 'taxable' => true, 'tax_method' => 'ONE_OFF', 'contribution_basis' => true, 'vacation_basis' => false, 'amount_ore' => 500_000]]], 'engångsbelopp'],
]);

it('explains tax and contributions', function () {
    $result = app(PayrollCalculator::class)->calculate(payrollContextFrom(json_decode((string) file_get_contents(base_path('tests/Fixtures/payroll/payslips/05-youth-reduction.json')), true)['context']));

    expect($result->taxExplanation)->toMatchArray(['method' => 'table', 'table' => 33, 'column' => 1, 'amount_kronor' => 6381])
        ->and($result->contributionExplanation['rule'])->toBe('youth_reduction')
        ->and($result->contributionExplanation['parts'])->toBe([['basis_ore' => 2_500_000, 'rate_bp' => 2081], ['basis_ore' => 700_000, 'rate_bp' => 3142]]);
});
