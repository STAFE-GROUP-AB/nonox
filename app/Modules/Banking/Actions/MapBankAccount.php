<?php

declare(strict_types=1);

namespace App\Modules\Banking\Actions;

use App\Actions\Banking\BankReconciliations;
use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use App\Modules\Banking\BankingModule;
use App\Modules\Banking\Models\BankFeedAccount;
use App\Modules\Banking\Support\BankingGate;
use App\Modules\ModuleManager;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Validation\ValidationException;

/**
 * Chooses the ledger account (1900–1999, usually 1930) a bank account is
 * reconciled against, or none. Only accounts with a ledger account are
 * fetched, so an account nobody maps is never read (data minimisation).
 * One bank account per ledger account, so a reconciliation compares one
 * statement with one account.
 */
class MapBankAccount
{
    public function __invoke(User $user, Company $company, BankFeedAccount $account, ?int $accountNumber): void
    {
        BankingGate::manage($user, $company);
        app(ModuleManager::class)->ensureEnabled(BankingModule::KEY, $company);

        CurrentCompany::run($company, function () use ($user, $company, $account, $accountNumber): void {
            $ledger = null;

            if ($accountNumber !== null) {
                $ledger = Account::query()->where('number', $accountNumber)->where('is_active', true)->first();

                if ($ledger === null || $ledger->number < BankReconciliations::FIRST_ACCOUNT || $ledger->number > BankReconciliations::LAST_ACCOUNT) {
                    throw ValidationException::withMessages(['account' => __('Välj ett bankkonto i kontoplanen (1900–1999).')]);
                }

                if ($account->currency !== ($company->base_currency ?? 'SEK')) {
                    throw ValidationException::withMessages(['account' => __('Kontot är i :currency. Bara konton i :base kan kopplas till bokföringen än så länge.', ['currency' => $account->currency, 'base' => $company->base_currency ?? 'SEK'])]);
                }

                $taken = BankFeedAccount::query()->where('account_id', $ledger->id)->where('is_active', true)->whereKeyNot($account->id)->exists();

                if ($taken) {
                    throw ValidationException::withMessages(['account' => __('Konto :number är redan kopplat till ett annat bankkonto.', ['number' => $ledger->number])]);
                }
            }

            $before = $account->account_id === null ? null : Account::query()->whereKey($account->account_id)->value('number');
            $account->forceFill(['account_id' => $ledger?->id])->save();

            Audit::record('banking.account_mapped', $account, ['bank_account' => $account->label(), 'before' => $before, 'after' => $ledger?->number], $company, $user);
        });
    }
}
