<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Domain\Payables\PaymentBank;
use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\PayrollModule;
use App\Support\Audit\Audit;
use App\Support\Pain001\CreditTransfer;
use App\Support\Pain001\CreditTransferFile;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The payment file for an approved payroll run (PAYROLL_MODULE.md B.11):
 * ISO 20022 pain.001.001.03 with category purpose SALA, one transfer per
 * employee's net pay to their bank account, from the company's bank as set
 * up for supplier payments. Every employee with pay must have a bank account.
 *
 * TODO(legal-verify): each bank's requirements for salary files (some need
 * a separate salary agreement, Swedbank's five-digit clearing numbers).
 */
class SalaryPaymentFile
{
    public const string CATEGORY_PURPOSE = 'SALA';

    public function __construct(private readonly ModuleManager $modules) {}

    /** @return array{xml: string, filename: string} */
    public function __invoke(User $user, PayrollRun $run): array
    {
        $company = Company::query()->findOrFail($run->company_id);
        $this->modules->ensureEnabled(PayrollModule::KEY, $company);
        Gate::forUser($user)->authorize('pay', $run);

        if (! $company->payment_bank instanceof PaymentBank || blank($company->iban) || blank($company->bank_agreement_id)) {
            throw ValidationException::withMessages(['run' => [__('Välj bank och fyll i IBAN och avtals-id under Leverantörer → Betalningar först. Lönefilen använder samma bankuppgifter.')]]);
        }

        return CurrentCompany::run($company, function () use ($user, $company, $run): array {
            $payslips = Payslip::query()->with('employee')->where('payroll_run_id', $run->id)->where('net_ore', '>', 0)->get()->sortBy(fn (Payslip $payslip): int => (int) $payslip->employee?->employee_number);
            $transfers = [];
            $missing = [];

            foreach ($payslips as $payslip) {
                /** @var Employee $employee */
                $employee = $payslip->employee;
                $clearing = (string) preg_replace('/\D/', '', (string) $employee->bank_clearing);
                $account = (string) preg_replace('/\D/', '', (string) $employee->bank_account);

                if ($clearing === '' || $account === '') {
                    $missing[] = "{$employee->first_name} {$employee->last_name}";

                    continue;
                }

                $transfers[] = new CreditTransfer(
                    endToEndId: "LON-{$run->period}-{$employee->employee_number}",
                    amountOre: $payslip->net_ore->ore,
                    creditorName: "{$employee->first_name} {$employee->last_name}",
                    scheme: CreditTransfer::BANK_ACCOUNT,
                    accountNumber: $clearing.$account,
                    message: __('Lön :period', ['period' => $run->period]),
                    clearing: $clearing,
                );
            }

            if ($missing !== []) {
                throw ValidationException::withMessages(['run' => [__('Bankkonto saknas för :names. Fyll i clearingnummer och kontonummer under Lön → Anställda.', ['names' => implode(', ', $missing)])]]);
            }

            if ($transfers === []) {
                throw ValidationException::withMessages(['run' => [__('Ingen i lönekörningen har något att få utbetalt.')]]);
            }

            /** @var PaymentBank $bank */
            $bank = $company->payment_bank;
            $messageId = 'NONOX-LON-'.$run->period.'-'.Str::upper(Str::random(8));
            $xml = CreditTransferFile::write(
                $bank,
                $messageId,
                CarbonImmutable::now(),
                (string) $company->bank_agreement_id,
                $company->legal_name ?: $company->name,
                (string) $company->iban,
                $run->payment_date,
                $transfers,
                preg_replace('/\D/', '', (string) $company->bankgiro),
                self::CATEGORY_PURPOSE,
            );

            // Who took a file with whose salaries, and how much in all; never the accounts.
            Audit::record('payroll.payment_file_created', $run, [
                'message_id' => $messageId,
                'payments' => count($transfers),
                'total_ore' => array_sum(array_map(fn (CreditTransfer $transfer): int => $transfer->amountOre, $transfers)),
                'sha256' => hash('sha256', $xml),
            ], $company, $user);

            return ['xml' => $xml, 'filename' => "Lonefil-{$run->period}.xml"];
        });
    }
}
