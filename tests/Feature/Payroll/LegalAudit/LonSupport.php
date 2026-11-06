<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll\LegalAudit;

use App\Actions\Payables\SavePaymentBankSettings;
use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Actions\AddPayrollAccounts;
use App\Modules\Payroll\Actions\ImportPayrollRules;
use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\PayCode;
use App\Modules\Payroll\Models\PayrollInput;
use App\Modules\Payroll\Models\PayrollRuleVersion;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\PayrollModule;
use App\Modules\Payroll\Support\Tax\TaxTableImporter;
use App\Support\Luhn;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Tests\Support\Payroll;

/**
 * Setup for the legal audit of payroll (granska-lon, 2026-11-02). Unlike
 * Tests\Support\Payroll it loads the SHIPPED rule file
 * app/Modules/Payroll/database/data/2026/rules.json, so the golden payslips
 * test the researched 2026 values themselves, not copies typed into a test.
 * The versions are marked verified only inside the test transaction.
 */
final class LonSupport
{
    public static function setUp(Company $company, User $owner): void
    {
        app(ModuleManager::class)->enable(PayrollModule::KEY, $company, $owner);
        app(TaxTableImporter::class)->import(2026, 'tabell-33.txt', (string) file_get_contents(base_path('tests/Fixtures/payroll/skattetabell-2026-manad-tabell-33.txt')));
        app(ImportPayrollRules::class)(2026);
        PayrollRuleVersion::query()->whereNull('verified_at')->get()->each(fn (PayrollRuleVersion $version) => $version->forceFill(['verified_by' => 'Granskning', 'verified_at' => now()])->save());
        app(AddPayrollAccounts::class)($owner, $company);
        // The salary voucher on its own: the vacation liability is booked at year end in these scenarios.
        app(ModuleManager::class)->updateSettings(PayrollModule::KEY, $company, $owner, ['vacation_liability_booking' => 'YEAR_END']);
        app(SavePaymentBankSettings::class)($owner, $company, ['payment_bank' => 'HANDELSBANKEN', 'iban' => 'SE45 5000 0000 0583 9825 7466', 'bank_agreement_id' => '5566778899']);
    }

    /** A synthetic, Luhn-valid personal number for a birth date (YYYYMMDD) and a three-digit serial. */
    public static function personalNumber(string $birth, string $serial = '123'): string
    {
        $nine = substr($birth, 2).$serial;

        return substr($birth, 0, 2).$nine.Luhn::checkDigit($nine);
    }

    /** @param array<string, mixed> $overrides */
    public static function employee(User $owner, Company $company, string $birth, array $overrides = []): Employee
    {
        return Payroll::employee($owner, $company, [
            'personal_number' => self::personalNumber($birth),
            'bank_clearing' => '8327',
            'bank_account' => '9934567890',
            ...$overrides,
        ]);
    }

    /** @param array<string, string> $input */
    public static function input(User $owner, PayrollRun $run, Employee $employee, string $code, array $input): void
    {
        app(PayrollRuns::class)->addInput($owner, $run->fresh(), [
            'employee_id' => $employee->id,
            'pay_code_id' => CurrentCompany::run(Company::query()->findOrFail($run->company_id), fn () => PayCode::query()->where('code', $code)->value('id')),
            ...$input,
        ]);
    }

    /**
     * Adds an input straight to the run, past the checks in PayrollRuns::addInput (for tests of the engine alone).
     *
     * @param  array{amount?: string}  $input
     */
    public static function rawInput(PayrollRun $run, Employee $employee, string $code, array $input): void
    {
        PayrollInput::create([
            'payroll_run_id' => $run->id,
            'employee_id' => $employee->id,
            'pay_code_id' => CurrentCompany::run(Company::query()->findOrFail($run->company_id), fn () => PayCode::query()->where('code', $code)->value('id')),
            'amount_ore' => Money::parse($input['amount'])->ore,
            'source' => 'MANUAL',
        ]);
    }

    /**
     * Calculates, approves (by a second person), marks paid and posts a run.
     */
    public static function postRun(User $owner, User $approver, PayrollRun $run): PayrollRun
    {
        $runs = app(PayrollRuns::class);
        $runs->calculate($owner, $run->fresh());
        $runs->approve($approver, $run->fresh());
        $runs->markPaid($owner, $run->fresh());

        return $runs->post($owner, $run->fresh());
    }

    /**
     * The salary voucher as account => debit − credit in öre.
     *
     * @return array<int, int>
     */
    public static function voucherLines(PayrollRun $run): array
    {
        $lines = [];

        foreach ($run->voucher->load('rows.account')->rows as $row) {
            $lines[$row->account->number] = ($lines[$row->account->number] ?? 0) + $row->debit_ore->ore - $row->credit_ore->ore;
        }

        ksort($lines);

        return $lines;
    }
}
