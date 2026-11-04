<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Domain\Accounting\StaleOpeningBalances;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\OpeningBalance;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * "Uppdatera ingående balanser": carries the closing balances forward again
 * into every open year whose opening balances have gone stale
 * (StaleOpeningBalances), oldest first, so a chain N → N+1 → N+2 ends up
 * consistent. All or nothing, in one transaction.
 *
 * Only on a person's decision, never automatically: new opening balances
 * change the reported figures of the later year (balance sheet, general
 * ledger, VAT and closing work already done), so someone who may manage
 * fiscal years decides when (ADR-010, "Stale opening balances").
 *
 * Refused when the period lock covers the first day of a year to change.
 * Opening balances are not vouchers (ADR-008): no voucher, number or hash is
 * touched. Each year is recorded in the audit log with every account's
 * balance before and after, the after read back from what was written.
 *
 * Locks, in the order every other path takes them (ADR-010): the company's
 * year-creation advisory lock, every fiscal year of the company FOR UPDATE
 * ordered by start date, then the company row FOR UPDATE. Nothing can be
 * posted, carried forward, locked or imported meanwhile, so what is compared
 * is what is changed.
 */
class UpdateOpeningBalances
{
    public function __construct(private readonly CarryForwardBalances $carryForward) {}

    /** @return list<string> labels of the years whose opening balances were updated */
    public function __invoke(User $user, Company $company): array
    {
        if (! $user->hasTeamPermission($company, 'fiscal-years:manage')) {
            throw new AuthorizationException;
        }

        return CurrentCompany::run($company, fn (): array => DB::transaction(function () use ($user, $company): array {
            CreateNextFiscalYear::lock($company->id);
            $loaded = FiscalYear::query()->with('company')->orderBy('start_date')->lockForUpdate()->get()->keyBy('id');
            /** @var Company $company */
            $company = Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $year = fn (int $id): FiscalYear => $loaded->get($id) ?? throw new LogicException("Fiscal year {$id} is not in the company.");
            $stale = StaleOpeningBalances::compute($company);

            if ($stale === []) {
                throw ValidationException::withMessages(['opening_balances' => __('De ingående balanserna stämmer redan med förra årets utgående balanser.')]);
            }

            foreach ($stale as $pair) {
                if ($pair['locked']) {
                    throw ValidationException::withMessages(['opening_balances' => CarryForwardBalances::lockedMessage($company, $year($pair['from_id']), $year($pair['to_id']))]);
                }
            }

            foreach ($stale as $pair) {
                ($this->carryForward)($user, $year($pair['from_id']));

                $written = OpeningBalance::query()
                    ->join('accounts', 'accounts.id', '=', 'opening_balances.account_id')
                    ->where('opening_balances.fiscal_year_id', $pair['to_id'])
                    ->whereIn('accounts.number', array_column($pair['accounts'], 'number'))
                    ->toBase()
                    ->pluck('opening_balances.amount_ore', 'accounts.number');

                Audit::record('fiscal_year.opening_balances_updated', $year($pair['to_id']), [
                    'from_id' => $pair['from_id'],
                    'to_id' => $pair['to_id'],
                    'from' => $pair['from'],
                    'to' => $pair['to'],
                    'accounts' => count($pair['accounts']),
                    'changes' => array_map(fn (array $account): array => [
                        'account' => $account['number'],
                        'before_ore' => $account['stored'],
                        'after_ore' => (int) ($written[$account['number']] ?? 0),
                    ], $pair['accounts']),
                ], $company, $user);
            }

            return array_column($stale, 'to');
        }));
    }
}
