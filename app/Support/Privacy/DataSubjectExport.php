<?php

declare(strict_types=1);

namespace App\Support\Privacy;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Modules\Payroll\Models\Absence;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\Employment;
use App\Modules\Payroll\Models\Expense;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\SalaryChange;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;

/**
 * Registerutdrag (GDPR art. 15 and 20): everything NONOX holds about one
 * person, as a machine-readable array. For a user it covers the account
 * across companies; for a customer, supplier or employee, what one company
 * holds (the company is the controller and answers the request).
 */
final class DataSubjectExport
{
    /** @return array<string, mixed> */
    public static function user(User $user): array
    {
        $companies = $user->companies();

        return [
            'utdrag' => self::meta('Användarkonto i NONOX'),
            'konto' => [
                'namn' => $user->name,
                'e_post' => $user->email,
                'skapat' => $user->created_at?->toIso8601String(),
                'aktuellt_foretag' => $user->current_team_id,
            ],
            'foretag' => $companies->map(fn (Company $company): array => [
                'namn' => $company->name,
                'organisationsnummer' => $company->org_number,
                'roll' => $company->user_id === $user->id ? 'owner' : DB::table('team_user')->where('team_id', $company->id)->where('user_id', $user->id)->value('role'),
            ])->values()->all(),
            'anstalld_i' => CompanyScope::bypass(fn (): array => Employee::query()->where('user_id', $user->id)->get()
                ->map(fn (Employee $employee): array => CurrentCompany::run($employee->company_id, fn (): array => self::employee($employee)))
                ->all()),
            'behandlingshistorik' => AuditLog::query()->where('user_id', $user->id)->latest('id')->limit(5000)->get(['action', 'subject_type', 'created_at', 'ip_address', 'user_agent'])
                ->map(fn (AuditLog $entry): array => [
                    'tid' => $entry->created_at->toIso8601String(),
                    'atgard' => $entry->action,
                    'avser' => class_basename((string) $entry->subject_type),
                    'ip_adress' => $entry->getAttribute('ip_address'),
                    'webblasare' => $entry->getAttribute('user_agent'),
                ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    public static function customer(Customer $customer): array
    {
        return [
            'utdrag' => self::meta('Kund'),
            'kund' => [...$customer->only(['number', 'name', 'org_number', 'vat_number', 'email', 'phone', 'address_line1', 'address_line2', 'postal_code', 'city', 'country']), 'personal_number' => $customer->personal_number],
            'fakturor' => Invoice::query()->where('customer_id', $customer->id)->orderBy('invoice_date')->get()
                ->map(fn (Invoice $invoice): array => ['nummer' => $invoice->number, 'datum' => $invoice->invoice_date->toDateString(), 'belopp_ore' => $invoice->total_ore->ore, 'status' => $invoice->status->value])->all(),
        ];
    }

    /** @return array<string, mixed> */
    public static function supplier(Supplier $supplier): array
    {
        return [
            'utdrag' => self::meta('Leverantör'),
            'leverantor' => $supplier->only(['number', 'name', 'org_number', 'vat_number', 'email', 'phone', 'address_line1', 'postal_code', 'city', 'country', 'bankgiro', 'plusgiro', 'iban', 'bic']),
            'leverantorsfakturor' => SupplierInvoice::query()->where('supplier_id', $supplier->id)->orderBy('invoice_date')->get()
                ->map(fn (SupplierInvoice $invoice): array => ['nummer' => $invoice->invoice_number, 'datum' => $invoice->invoice_date->toDateString(), 'belopp_ore' => $invoice->total_ore->ore, 'status' => $invoice->status->value])->all(),
        ];
    }

    /** @return array<string, mixed> */
    public static function employee(Employee $employee): array
    {
        $periods = PayrollRun::query()->pluck('period', 'id');

        return [
            'utdrag' => self::meta('Anställd'),
            'anstalld' => [
                ...$employee->only(['employee_number', 'first_name', 'last_name', 'email', 'phone', 'address', 'postal_code', 'city', 'country', 'tax_residence', 'tax_table', 'tax_column', 'church_member', 'status']),
                'personnummer' => $employee->personal_number,
                'clearingnummer' => $employee->bank_clearing,
                'kontonummer' => $employee->bank_account,
                'iban' => $employee->getAttribute('iban'),
                'anhorig' => $employee->getAttribute('emergency_contact'),
            ],
            'anstallningar' => Employment::query()->where('employee_id', $employee->id)->orderBy('start_date')->get()
                ->map(fn (Employment $employment): array => [
                    'fran' => $employment->start_date->toDateString(),
                    'till' => $employment->end_date?->toDateString(),
                    'form' => $employment->employment_type,
                    'befattning' => $employment->position_title,
                ])->all(),
            'loneandringar' => SalaryChange::query()->where('employee_id', $employee->id)->orderBy('valid_from')->get()
                ->map(fn (SalaryChange $change): array => ['fran' => $change->valid_from->toDateString(), 'manadslon_ore' => $change->monthly_salary_ore?->ore, 'timlon_ore' => $change->hourly_rate_ore?->ore, 'skal' => $change->reason])->all(),
            'lonebesked' => Payslip::query()->where('employee_id', $employee->id)->orderBy('id')->get()
                ->map(fn (Payslip $payslip): array => ['period' => $periods[$payslip->payroll_run_id] ?? null, 'brutto_ore' => $payslip->gross_ore->ore, 'skatt_ore' => $payslip->tax_ore->ore, 'netto_ore' => $payslip->net_ore->ore])->all(),
            'franvaro' => Absence::query()->where('employee_id', $employee->id)->orderBy('date_from')->get()
                ->map(fn (Absence $absence): array => ['typ' => $absence->type, 'fran' => $absence->date_from->toDateString(), 'till' => $absence->date_to->toDateString(), 'status' => $absence->status, 'anteckning' => $absence->note])->all(),
            'utlagg' => Expense::query()->where('employee_id', $employee->id)->orderBy('date')->get()
                ->map(fn (Expense $expense): array => ['datum' => $expense->date->toDateString(), 'beskrivning' => $expense->description, 'belopp_ore' => $expense->amount_ore->ore, 'status' => $expense->status])->all(),
        ];
    }

    /** @return array{typ: string, skapat: string, rattslig_grund: string} */
    private static function meta(string $type): array
    {
        return [
            'typ' => $type,
            'skapat' => now()->toIso8601String(),
            'rattslig_grund' => 'Registerutdrag enligt artikel 15 och dataportabilitet enligt artikel 20 i dataskyddsförordningen (GDPR).',
        ];
    }
}
