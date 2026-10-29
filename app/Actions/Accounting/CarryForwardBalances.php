<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Domain\Accounting\AccountType;
use App\Domain\Accounting\FiscalYearRules;
use App\Domain\Accounting\FiscalYearStatus;
use App\Domain\Accounting\LedgerBalances;
use App\Domain\Accounting\VoucherStatus;
use App\Domain\Company\LegalForm;
use App\Models\Account;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\OpeningBalance;
use App\Models\User;
use App\Models\Voucher;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Year-end rollover (årsskifte, AGENTS.md §7): the closing balance of every
 * balance-sheet account becomes its opening balance in the next fiscal year,
 * which is created if needed. A result not yet booked to equity is carried
 * to the year's result account, so the opening balances always balance.
 *
 * Can be run again whenever the year changes (a late voucher, a correction):
 * the next year's opening balances are replaced, as long as it is open.
 */
class CarryForwardBalances
{
    public function __invoke(User $user, FiscalYear $fiscalYear): FiscalYear
    {
        Gate::forUser($user)->authorize('update', $fiscalYear);

        return CurrentCompany::run($fiscalYear->company_id, fn (): FiscalYear => DB::transaction(function () use ($user, $fiscalYear): FiscalYear {
            $company = Company::query()->findOrFail($fiscalYear->company_id);
            $opening = LedgerBalances::opening($fiscalYear);
            $movements = LedgerBalances::movements($fiscalYear);

            if ($opening->isEmpty() && ! Voucher::query()->where('fiscal_year_id', $fiscalYear->id)->where('status', VoucherStatus::Posted->value)->exists()) {
                throw ValidationException::withMessages(['fiscal_year' => __('Räkenskapsåret :label har inget bokfört att föra över.', ['label' => $fiscalYear->label()])]);
            }

            $next = $this->nextYear($fiscalYear, $company, $user);

            if (! $next->isOpen()) {
                throw ValidationException::withMessages(['fiscal_year' => __('Räkenskapsåret :label är stängt, så dess ingående balanser kan inte ändras.', ['label' => $next->label()])]);
            }

            $balances = [];

            foreach (Account::query()->get() as $account) {
                if (! LedgerBalances::isBalanceSheet($account->type)) {
                    continue;
                }

                $closing = (int) ($opening[$account->id] ?? 0) + (int) ($movements[$account->id] ?? 0);

                if ($closing !== 0) {
                    $balances[$account->id] = $closing;
                }
            }

            $unbooked = array_sum($balances);

            if ($unbooked !== 0) {
                $resultAccount = $this->resultAccount($company);
                $balances[$resultAccount->id] = ($balances[$resultAccount->id] ?? 0) - $unbooked;
            }

            $before = OpeningBalance::query()->where('fiscal_year_id', $next->id)->count();
            OpeningBalance::query()->where('fiscal_year_id', $next->id)->delete();

            foreach (array_filter($balances) as $accountId => $amount) {
                OpeningBalance::create(['fiscal_year_id' => $next->id, 'account_id' => $accountId, 'amount_ore' => $amount]);
            }

            Audit::record('fiscal_year.balances_carried_forward', $next, [
                'from' => $fiscalYear->label(),
                'to' => $next->label(),
                'accounts' => count(array_filter($balances)),
                'replaced' => $before,
                'unbooked_result_ore' => -$unbooked,
            ], $company, $user);

            return $next;
        }));
    }

    private function nextYear(FiscalYear $fiscalYear, Company $company, User $user): FiscalYear
    {
        $start = $fiscalYear->end_date->addDay();
        $next = FiscalYear::query()->whereDate('start_date', $start)->lockForUpdate()->first();

        if ($next instanceof FiscalYear) {
            return $next;
        }

        $next = FiscalYear::create([
            'start_date' => $start,
            'end_date' => $start->addMonths(FiscalYearRules::NORMAL_MONTHS)->subDay(),
            'status' => FiscalYearStatus::Open,
        ]);

        Audit::record('fiscal_year.created', $next, [
            'start_date' => $next->start_date->toDateString(),
            'end_date' => $next->end_date->toDateString(),
            'source' => 'carry_forward',
        ], $company, $user);

        return $next;
    }

    /**
     * Where an unbooked result goes: 2099 Årets resultat for companies and
     * associations, 2019 for sole traders and partnerships.
     *
     * TODO(legal-verify): confirm the BAS result account per legal form,
     * especially for partnerships (HB/KB), where equity is kept per partner.
     */
    private function resultAccount(Company $company): Account
    {
        $number = in_array($company->legal_form, [LegalForm::Ef, LegalForm::Hb, LegalForm::Kb], true) ? 2019 : 2099;

        return Account::query()->firstOrCreate(['number' => $number], [
            'name' => __('Årets resultat'),
            'type' => AccountType::LiabilityEquity,
        ]);
    }
}
