<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Actions\SaveEmployee;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\PayrollRuleVersion;
use App\Modules\Payroll\PayrollModule;
use App\Modules\Payroll\Support\Tax\TaxTableImporter;
use App\Support\Luhn;
use App\Support\Tenancy\CurrentCompany;

/**
 * Payroll test setup: the module on, Skatteverket's 2026 table 33, and the
 * rules the engine needs, verified only inside the test.
 */
final class Payroll
{
    public const array RULES = [
        'employer_contribution.full' => ['bp' => 3142],
        'employer_contribution.reduced_by_birthyear' => ['bands' => [['born_to' => 1937, 'bp' => 0], ['born_from' => 1938, 'born_to' => 1958, 'bp' => 1021]]],
        'employer_contribution.youth_reduction' => ['applies' => true, 'born_from' => 2003, 'born_to' => 2007, 'bp' => 2081, 'monthly_cap_ore' => 2_500_000],
        'employer_contribution.min_annual_basis' => ['ore' => 100_000],
        'sink.rate' => ['bp' => 2250],
        'tax.rounding' => ['mode' => 'DOWN_TO_KRONA'],
        'sick_pay.percentage' => ['bp' => 8000],
        'sick_pay.karensavdrag_percent_of_weekly' => ['bp' => 2000, 'max_occasions_per_12_months' => 10],
        'sick_pay.employer_period_days' => ['days' => 14],
        'sick_pay.reinfection_window_days' => ['days' => 5],
        'a_sink.rate' => ['bp' => 1500],
        'tax.one_off_without_regular_salary' => ['bp' => 3000],
        'tax.one_off' => ['columns' => ['1' => [[0, 25041, 0], [25042, 82800, 1000], [82801, 192000, 2100], [192001, 477600, 2600], [477601, 660000, 3400], [660001, null, 5400]], '2' => [[0, 65800, 0], [65801, 477600, 2600], [477601, 660000, 3400], [660001, null, 5500]], '3' => [[0, 25041, 0], [25042, 331200, 1000], [331201, 477600, 2600], [477601, 660000, 3400], [660001, null, 5500]], '4' => [[0, 25041, 0], [25042, 54000, 300], [54001, 192000, 2200], [192001, 660000, 2600], [660001, null, 4600]], '5' => [[0, 25041, 0], [25042, 32400, 1000], [32401, 160800, 2900], [160801, 184800, 3400], [184801, 660000, 3800], [660001, null, 5400]], '6' => [[0, 25041, 0], [25042, 160800, 2900], [160801, 184800, 3400], [184801, 660000, 3800], [660001, null, 5400]]], 'note' => '[from, to, rate bp] on the expected annual income in whole kronor (SKV 433 avsnitt 10).'],
        'benefit.meal.free_day' => ['ore' => 31000],
        'benefit.meal.lunch_or_dinner' => ['ore' => 12400],
        'benefit.meal.breakfast' => ['ore' => 6200],
        'mileage.own_car_tax_free_per_mil' => ['ore' => 2500],
        'per_diem.domestic.full' => ['ore' => 30000, 'after_three_months_ore' => 21000, 'after_two_years_ore' => 15000],
        'per_diem.domestic.half' => ['ore' => 15000],
        'per_diem.domestic.night' => ['ore' => 15000],
        'inkomstbasbelopp' => ['ore' => 8340000],
        'special_payroll_tax.pension' => ['bp' => 2426],
        'vacation.minimum_days' => ['days' => 25],
        'vacation.supplement_sammaloneregeln' => ['bp_of_monthly_salary_per_day' => 43, 'weekly_bp' => 182, 'variable_pay_bp' => 1200],
        'vacation.percent_rule' => ['bp' => 1200],
        'vacation.max_saved_days' => ['days_that_must_be_taken' => 20],
        'vacation.qualifying_absence_days' => ['SICK' => 180, 'PARENTAL' => 120, 'VAB' => 120, 'MILITARY' => 60],
    ];

    public static function setUp(Company $company, User $owner): void
    {
        app(ModuleManager::class)->enable(PayrollModule::KEY, $company, $owner);
        app(TaxTableImporter::class)->import(2026, 'tabell-33.txt', (string) file_get_contents(base_path('tests/Fixtures/payroll/skattetabell-2026-manad-tabell-33.txt')));

        foreach (self::RULES as $key => $value) {
            PayrollRuleVersion::create(['key' => $key, 'valid_from' => '2026-01-01', 'value' => $value, 'source_url' => 'https://example.test'])
                ->forceFill(['verified_by' => 'Testperson', 'verified_at' => now()])->save();
        }
    }

    /**
     * The agreement pension and insurance premiums NONOX Avtal points at
     * (ADR-033 A10), 2026 values from rules.json, verified only inside the
     * test. Not part of setUp: only runs with a collective agreement need them.
     */
    public const array AGREEMENT_RULES = [
        'prisbasbelopp' => ['ore' => 5_920_000],
        'pension.saf_lo' => ['name' => 'Avtalspension SAF-LO', 'below_bp' => 450, 'above_bp' => 3000, 'threshold_ibb' => '7.5', 'from_age' => 22, 'to_age_exclusive' => 65],
        'pension.itp1' => ['name' => 'ITP 1', 'below_bp' => 450, 'above_bp' => 3000, 'threshold_ibb' => '7.5', 'ceiling_ibb' => '30', 'from_age' => 25],
        'insurance.fora.arbetare' => ['components' => [
            ['key' => 'AGS', 'name' => 'AGS', 'bp' => 0, 'cap_pbb' => '7.5'],
            ['key' => 'TFA', 'name' => 'TFA', 'bp' => 1],
            ['key' => 'TGL', 'name' => 'TGL', 'bp' => 10, 'cap_pbb' => '7.5'],
            ['key' => 'TSL', 'name' => 'Omställningsstöd (TSL)', 'bp' => 34, 'hangavtal_bp' => 74, 'cap_pbb' => '7.5'],
        ]],
        'insurance.fora.tjansteman' => ['components' => [
            ['key' => 'TFA', 'name' => 'TFA', 'bp' => 2],
            ['key' => 'TRR', 'name' => 'Omställning (TRR)', 'bp' => 40, 'hangavtal_bp' => 80, 'to_age_exclusive' => 67],
        ]],
    ];

    public static function agreementPremiums(): void
    {
        foreach (self::AGREEMENT_RULES as $key => $value) {
            PayrollRuleVersion::create(['key' => $key, 'valid_from' => '2026-01-01', 'value' => $value, 'source_url' => 'https://example.test'])
                ->forceFill(['verified_by' => 'Testperson', 'verified_at' => now()])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function employee(User $user, Company $company, array $overrides = []): Employee
    {
        $tenDigits = '900615123';

        return CurrentCompany::run($company, fn () => app(SaveEmployee::class)($user, $company, [
            'first_name' => 'Anna', 'last_name' => 'Kock', 'personal_number' => '19'.$tenDigits.Luhn::checkDigit($tenDigits),
            'tax_residence' => 'SE', 'tax_table' => 33, 'tax_column' => 1,
            'start_date' => '2025-01-01', 'employment_type' => 'TILLSVIDARE', 'salary_form' => 'MONTHLY', 'salary' => '32 000', 'employment_rate' => 100, 'weekly_hours' => '40',
            ...$overrides,
        ]));
    }
}
