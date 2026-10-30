<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Domain\Accounting\AccountType;
use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Domain\PayCodeCatalog;
use App\Modules\Payroll\Models\PayCode;
use App\Modules\Payroll\PayrollModule;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds the BAS accounts payroll books on that the company's chart lacks,
 * after the user has confirmed it in the payroll checklist
 * (PAYROLL_MODULE.md B.2), and links the system pay codes to them.
 */
class AddPayrollAccounts
{
    /** @return list<int> the account numbers the chart lacks */
    public static function missing(Company $company): array
    {
        return CurrentCompany::run($company, fn (): array => array_values(array_diff(
            array_keys(PayCodeCatalog::ACCOUNTS),
            Account::query()->whereIn('number', array_keys(PayCodeCatalog::ACCOUNTS))->pluck('number')->all(),
        )));
    }

    /** @return list<int> the accounts added */
    public function __invoke(User $user, Company $company): array
    {
        app(ModuleManager::class)->ensureEnabled(PayrollModule::KEY, $company);

        if (! $user->hasTeamPermission($company, 'payroll:settings') || ! $user->hasTeamPermission($company, 'accounts:manage')) {
            throw ValidationException::withMessages(['accounts' => __('Du behöver behörighet till kontoplanen och löneinställningarna för att lägga till konton.')]);
        }

        return CurrentCompany::run($company, fn (): array => DB::transaction(function () use ($user, $company): array {
            $added = self::missing($company);

            foreach ($added as $number) {
                Account::create(['number' => $number, 'name' => PayCodeCatalog::ACCOUNTS[$number], 'type' => AccountType::forNumber($number)]);
            }

            $accounts = Account::query()->whereIn('number', array_keys(PayCodeCatalog::ACCOUNTS))->pluck('id', 'number');

            foreach (PayCodeCatalog::CODES as $code => $definition) {
                $payCode = PayCode::query()->where('code', $code)->where('is_system', true)->first();

                if ($payCode === null) {
                    continue;
                }

                $payCode->forceFill([
                    'debit_account_id' => $payCode->debit_account_id ?? ($definition['debit'] === null ? null : ($accounts[$definition['debit']] ?? null)),
                    'credit_account_id' => $payCode->credit_account_id ?? ($definition['credit'] === null ? null : ($accounts[$definition['credit']] ?? null)),
                ])->save();
            }

            Audit::record('payroll.accounts_added', null, ['accounts' => $added], $company, $user);

            return $added;
        }));
    }
}
