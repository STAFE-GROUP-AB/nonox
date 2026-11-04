<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Domain\Accounting\StaleOpeningBalances;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * "Uppdatera ingående balanser": carries the closing balances forward again
 * into every year whose opening balances have gone stale
 * (StaleOpeningBalances), oldest first, so a chain N → N+1 → N+2 ends up
 * consistent. All or nothing, in one transaction.
 *
 * Only on a person's decision, never automatically: new opening balances
 * change the reported figures of the later year (balance sheet, general
 * ledger, VAT and closing work already done), so someone who may manage
 * fiscal years decides when (ADR-010, "Stale opening balances").
 *
 * Refused when a year to change is closed, or when the period lock covers
 * its first day. Opening balances are not vouchers (ADR-008): no voucher,
 * number or hash is touched. Each year is recorded in the audit log with
 * every account's balance before and after.
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
            $company = Company::query()->findOrFail($company->id);
            $stale = StaleOpeningBalances::compute($company);

            if ($stale === []) {
                throw ValidationException::withMessages(['opening_balances' => __('De ingående balanserna stämmer redan med förra årets utgående balanser.')]);
            }

            $loaded = FiscalYear::query()->with('company')->whereIn('id', [...array_column($stale, 'from_id'), ...array_column($stale, 'to_id')])->get()->keyBy('id');
            $year = fn (int $id): FiscalYear => $loaded->get($id) ?? throw new LogicException("Fiscal year {$id} disappeared.");

            foreach ($stale as $pair) {
                if (! $pair['to_open']) {
                    throw ValidationException::withMessages(['opening_balances' => __('Räkenskapsåret :label är stängt, så dess ingående balanser kan inte ändras.', ['label' => $pair['to']])]);
                }

                if ($pair['locked']) {
                    throw ValidationException::withMessages(['opening_balances' => CarryForwardBalances::lockedMessage($company, $year($pair['from_id']), $year($pair['to_id']))]);
                }
            }

            foreach ($stale as $pair) {
                ($this->carryForward)($user, $year($pair['from_id']));

                Audit::record('fiscal_year.opening_balances_updated', $year($pair['to_id']), [
                    'from_id' => $pair['from_id'],
                    'to_id' => $pair['to_id'],
                    'from' => $pair['from'],
                    'to' => $pair['to'],
                    'accounts' => count($pair['accounts']),
                    'changes' => array_map(fn (array $account): array => [
                        'account' => $account['number'],
                        'before_ore' => $account['stored'],
                        'after_ore' => $account['expected'],
                    ], $pair['accounts']),
                ], $company, $user);
            }

            return array_column($stale, 'to');
        }));
    }
}
