<?php

declare(strict_types=1);

use App\Modules\Agreements\Domain\AgreementContext;
use App\Modules\Agreements\Domain\AgreementFileValidator;
use App\Modules\Agreements\Domain\AgreementRules;
use App\Modules\Agreements\Support\AgreementContextExtension;
use App\Modules\Agreements\Support\AgreementSickPayExtension;
use App\Modules\Payroll\Domain\Engine\AbsenceInput;
use App\Modules\Payroll\Domain\Engine\PayInput;
use App\Modules\Payroll\Domain\Engine\PayrollBlockedException;
use App\Modules\Payroll\Domain\Engine\PayrollCalculator;
use App\Modules\Payroll\Domain\Engine\PayrollContext;
use App\Modules\Payroll\Domain\Engine\PayslipLineResult;
use App\Modules\Payroll\Domain\Engine\Steps\Additions;
use App\Modules\Payroll\Domain\Engine\VacationInput;
use App\Modules\Payroll\Domain\PayCodeCatalog;
use App\Modules\Payroll\Domain\Rules\RuleSet;
use App\Modules\Payroll\Models\PayrollRuleVersion;
use App\Modules\Payroll\Support\Tax\TaxTableImporter;
use Carbon\CarbonImmutable;
use Tests\Support\Payroll;

/*
 * Golden payslips per agreement (ADR-033 A12): each case in
 * tests/Fixtures/agreements/payslips gives an employment, a month's inputs
 * and the amounts worked out by hand from the shipped agreement file (or a
 * synthetic agreement where no public value exists, as for the
 * hairdressers' commission) and the 2026 premium rules. The engine reads the
 * agreement exactly as a run does (AgreementContextExtension::applyAgreement,
 * then the payroll calculator with the agreement's steps). Sick pay goes
 * through AgreementSickPayExtension::applyTerms as a run does without
 * Planering: the agreement's hour value, karens in time and deduction from
 * day 15, with the sick history (sick_history) the run would read and the
 * facts it would find (sick_facts). Overtime and mertid supplements for
 * hourly pay and the vacation-pay basis of overtime per agreement are covered
 * too. Shifts, mertid and sick pay with OB from the schedule (A5–A8) are
 * covered in AgreementTimeTest (enligt AI-utredning 2026-11-02,
 * juristgranskning återstår).
 */

beforeEach(function () {
    app(TaxTableImporter::class)->import(2026, 'tabell-33.txt', (string) file_get_contents(base_path('tests/Fixtures/payroll/skattetabell-2026-manad-tabell-33.txt')));

    foreach ([...Payroll::RULES, ...Payroll::AGREEMENT_RULES] as $key => $value) {
        PayrollRuleVersion::create(['key' => $key, 'valid_from' => '2026-01-01', 'value' => $value, 'source_url' => 'https://example.test'])
            ->forceFill(['verified_by' => 'Testperson', 'verified_at' => now()])->save();
    }
});

/** @return array<string, mixed> the agreement file a case names, shipped or synthetic */
function goldenAgreementFile(string $name): array
{
    $path = str_starts_with($name, 'synthetic:')
        ? base_path('tests/Fixtures/agreements/'.substr($name, strlen('synthetic:')))
        : base_path('app/Modules/Agreements/database/data/agreements/'.$name);

    return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * @param  array<string, mixed>  $case
 * @return array{PayrollContext, AgreementContext}
 */
function goldenAgreementContext(array $case): array
{
    $file = goldenAgreementFile($case['agreement']);
    $sources = $file['sources'];
    unset($file['sources']);
    $rules = new AgreementRules($file, $sources);

    if (isset($case['local_overrides'])) {
        $rules = $rules->withOverrides($case['local_overrides']);
    }

    $employment = $case['employment'];
    $start = CarbonImmutable::createFromFormat('!Y-m', $case['period']) ?: throw new LogicException('Bad period.');
    $agreement = new AgreementContext(
        agreementKey: $rules->key(),
        variant: (string) $file['agreement']['variant'],
        versions: [['id' => 1, 'valid_from' => CarbonImmutable::parse($file['valid_from']), 'valid_to' => CarbonImmutable::parse($file['valid_to']), 'rules' => $rules]],
        group: $employment['group'],
        experienceFrom: $employment['experience_from'] === null ? null : CarbonImmutable::parse($employment['experience_from']),
        employmentStart: CarbonImmutable::parse($employment['start']),
        overtimeCompensation: null,
        obCompensation: null,
        sickPayDirectTransfer: (bool) ($employment['sick_pay_direct_transfer'] ?? false),
        fixedSupplementsOre: $employment['fixed_supplements_ore'] ?? null,
    );

    $inputs = array_map(function (array $input) use ($rules): PayInput {
        $definition = $rules->code($input['code']);

        if ($definition === null) {
            $base = PayCodeCatalog::CODES[$input['code']];

            return new PayInput($input['code'], $base['name_sv'], $base['category'], $base['taxable'], $base['tax_method'], $base['contribution'], $base['vacation'], $input['quantity']);
        }

        $base = PayCodeCatalog::CODES[$definition['kind'] === 'MEAL' ? 'AVTALSTILLAGG' : $definition['pay_code']];
        $vacation = $definition['kind'] === 'MEAL' ? false : ($definition['vacation_basis'] ?? $base['vacation']);

        return new PayInput($input['code'], $definition['name'], $base['category'], $base['taxable'], $base['tax_method'], $base['contribution'], $vacation, $input['quantity'], null, null,
            [Additions::AGREEMENT_PRICED => true, 'date_from' => $input['date'], 'date_to' => $input['date']]);
    }, $case['inputs']);

    $vacation = isset($case['vacation']) ? new VacationInput($case['vacation']['rule'], $case['vacation']['paid_days_earned'], 0, $case['vacation']['basis_ore'], $case['vacation']['payout_with_salary'] ?? false) : null;

    $context = new PayrollContext(
        birthDate: CarbonImmutable::parse($employment['birth_date']),
        taxResidence: 'SE',
        taxTable: 33,
        taxColumn: 1,
        taxAdjustmentPercentBp: null,
        salaryForm: $employment['salary_form'],
        monthlySalaryOre: $employment['monthly_salary_ore'],
        hourlyRateOre: $employment['hourly_rate_ore'],
        employmentRateBp: $employment['employment_rate_bp'],
        employmentStart: CarbonImmutable::parse($employment['start']),
        employmentEnd: null,
        periodStart: $start,
        periodEnd: $start->endOfMonth()->startOfDay(),
        paymentDate: CarbonImmutable::parse($case['payment_date']),
        inputs: $inputs,
        accumulators: [],
        settings: [],
        rules: RuleSet::for(CarbonImmutable::parse($case['payment_date'])),
        weeklyHours: $employment['weekly_hours'],
        absences: array_map(fn (array $absence): AbsenceInput => new AbsenceInput($absence['type'], CarbonImmutable::parse($absence['date']), $absence['hours'], extentBp: $absence['extent_bp'] ?? 10000), $case['absences'] ?? []),
        sickHistory: $case['sick_history'] ?? [],
        weekPattern: $employment['week_pattern'] ?? null,
        vacation: $vacation,
    );

    return [$context, $agreement];
}

dataset('agreement golden payslips', function (): array {
    $cases = [];

    foreach (glob(dirname(__DIR__, 2).'/Fixtures/agreements/payslips/*.json') ?: [] as $file) {
        $fixture = json_decode((string) file_get_contents($file), true);

        foreach ($fixture['cases'] as $case) {
            $cases["{$fixture['agreement']}: {$case['name']}"] = [$case];
        }
    }

    return $cases;
});

it('calculates the agreement\'s golden payslip from the data file', function (array $case) {
    [$context, $agreement] = goldenAgreementContext($case);
    $context = app(AgreementContextExtension::class)->applyAgreement($context, $agreement);
    $draft = app(PayrollCalculator::class)->calculate(app(AgreementSickPayExtension::class)->applyTerms($context, $agreement, $case['sick_facts'] ?? []));
    $sum = fn (string $code): int => array_sum(array_map(fn (PayslipLineResult $line): int => $line->amountOre, array_values(array_filter($draft->lines, fn (PayslipLineResult $line): bool => $line->code === $code))));
    $expected = $case['expected'];

    foreach ($expected['lines'] as $code => $amount) {
        expect($sum($code))->toBe($amount, "line {$code}");
    }

    expect($draft->gross())->toBe($expected['gross_ore'], 'gross');

    foreach ($expected['employer'] as $code => $amount) {
        expect($sum($code))->toBe($amount, "employer cost {$code}");
    }

    expect($draft->warnings)->toHaveCount(count($expected['warnings']), implode(' | ', $draft->warnings));

    foreach ($expected['warnings'] as $index => $warning) {
        expect($draft->warnings[$index])->toContain($warning);
    }

    foreach ($expected['sick_periods'] ?? [] as $index => $period) {
        expect(array_intersect_key($draft->sickPeriods[$index] ?? [], $period))->toBe($period, "sick period {$index}");
    }
})->with('agreement golden payslips');

it('has at least ten golden payslips per agreement in wave 1, and valid synthetic agreements', function () {
    foreach (glob(dirname(__DIR__, 2).'/Fixtures/agreements/payslips/*.json') ?: [] as $file) {
        $fixture = json_decode((string) file_get_contents($file), true);

        if ($fixture['agreement'] !== 'frisor-syntetiskt') {
            expect(count($fixture['cases']))->toBeGreaterThanOrEqual(10, $fixture['agreement']);
        }
    }

    foreach (['synthetic-frisor.json', 'synthetic-frisor-none.json'] as $synthetic) {
        $file = goldenAgreementFile("synthetic:{$synthetic}");

        expect(app(AgreementFileValidator::class)->validate($file))->toBe([]);
    }
});

it('blocks instead of guessing when a premium rule the agreement points at is not verified for the payment date', function () {
    $fixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/agreements/payslips/visita-hrf-grona-riks.json')), true);
    [$context, $agreement] = goldenAgreementContext([...$fixture['cases'][0], 'period' => '2025-12', 'payment_date' => '2025-12-23']);

    expect(fn () => app(AgreementContextExtension::class)->applyAgreement($context, $agreement))
        ->toThrow(PayrollBlockedException::class, 'Regeln pension.saf_lo som Gröna Riks hänvisar till är inte kontrollerad för 2025-12-23');
});
