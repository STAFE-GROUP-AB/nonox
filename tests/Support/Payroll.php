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
