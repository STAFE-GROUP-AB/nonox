<?php

declare(strict_types=1);

namespace App\Actions\Banking;

use App\Domain\Accounting\LedgerBalances;
use App\Domain\Accounting\VoucherStatus;
use App\Models\Account;
use App\Models\BankReconciliation;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Models\VoucherRow;
use App\Support\Audit\Audit;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Bank reconciliation per account (avstämning): the balance on the bank's
 * statement is compared with the ledger on the same day. Rows booked in the
 * ledger but not yet on the statement (a payment the bank has not cleared)
 * are marked outstanding; they are carried to the next reconciliation until
 * they show up. What is left is the difference, which needs an explanation.
 * Reconciling is bookkeeping work, so it needs vouchers:post.
 */
class BankReconciliations
{
    public const int FIRST_ACCOUNT = 1900;

    public const int LAST_ACCOUNT = 1999;

    /**
     * The accounts for cash and bank, with their latest reconciliation.
     *
     * @return list<array{account: Account, latest: ?BankReconciliation}>
     */
    public static function accounts(Company $company): array
    {
        return CurrentCompany::run($company, function (): array {
            $accounts = Account::query()->whereBetween('number', [self::FIRST_ACCOUNT, self::LAST_ACCOUNT])->where('is_active', true)->orderBy('number')->get();
            $latest = BankReconciliation::query()->whereIn('account_id', $accounts->modelKeys())
                ->orderByDesc('statement_date')->orderByDesc('id')->get()->unique('account_id')->keyBy('account_id');

            return array_values($accounts->map(fn (Account $account): array => ['account' => $account, 'latest' => $latest->get($account->id)])->all());
        });
    }

    /**
     * What a reconciliation of the account on the date starts from: the
     * fiscal year, the ledger balance, the previous reconciliation, and the
     * rows to check, which are the rows since the previous reconciliation
     * and the rows it left outstanding.
     *
     * @return array{fiscal_year: FiscalYear, ledger: int, previous: ?BankReconciliation, rows: Collection<int, VoucherRow>}
     */
    public function prepare(Company $company, Account $account, CarbonImmutable $date): array
    {
        return CurrentCompany::run($company, function () use ($account, $date): array {
            $fiscalYear = FiscalYear::query()->whereDate('start_date', '<=', $date->toDateString())->whereDate('end_date', '>=', $date->toDateString())->first()
                ?? throw ValidationException::withMessages(['date' => __('Datumet ligger inte i något räkenskapsår.')]);

            $previous = BankReconciliation::query()->where('account_id', $account->id)->whereDate('statement_date', '<', $date->toDateString())
                ->orderByDesc('statement_date')->orderByDesc('id')->first();
            $from = $previous !== null && $previous->statement_date->greaterThanOrEqualTo($fiscalYear->start_date)
                ? $previous->statement_date->addDay()
                : $fiscalYear->start_date;
            $carried = $previous->outstanding_row_ids ?? [];

            $rows = VoucherRow::query()->select('voucher_rows.*')
                ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
                ->where('vouchers.status', VoucherStatus::Posted->value)
                ->where('voucher_rows.account_id', $account->id)
                ->whereDate('vouchers.voucher_date', '<=', $date->toDateString())
                ->where(fn (Builder $query) => $query
                    ->whereDate('vouchers.voucher_date', '>=', $from->toDateString())
                    ->when($carried !== [], fn (Builder $query) => $query->orWhereIn('voucher_rows.id', $carried)))
                ->orderBy('vouchers.voucher_date')->orderBy('voucher_rows.id')
                ->with('voucher.series')
                ->get();

            $movement = (int) VoucherRow::query()
                ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
                ->where('vouchers.fiscal_year_id', $fiscalYear->id)
                ->where('vouchers.status', VoucherStatus::Posted->value)
                ->where('voucher_rows.account_id', $account->id)
                ->whereDate('vouchers.voucher_date', '<=', $date->toDateString())
                ->sum(DB::raw('voucher_rows.debit_ore - voucher_rows.credit_ore'));

            return [
                'fiscal_year' => $fiscalYear,
                'ledger' => (int) (LedgerBalances::opening($fiscalYear)[$account->id] ?? 0) + $movement,
                'previous' => $previous,
                'rows' => $rows,
            ];
        });
    }

    /**
     * Saves a reconciliation. The outstanding rows must be among the rows
     * to check; a difference needs an explanation.
     *
     * @param  list<int>  $outstandingRowIds
     */
    public function save(User $user, Company $company, Account $account, string $date, string $statementBalance, array $outstandingRowIds, ?string $explanation): BankReconciliation
    {
        $this->authorize($user, $company);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw ValidationException::withMessages(['date' => __('Välj datum.')]);
        }

        if ($account->number < self::FIRST_ACCOUNT || $account->number > self::LAST_ACCOUNT) {
            throw ValidationException::withMessages(['account' => __('Välj ett bank- eller kassakonto.')]);
        }

        try {
            $statement = Money::parse($statementBalance)->ore;
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['statement' => __('Saldot går inte att läsa. Skriv till exempel 125 000 eller -1 250,50.')]);
        }

        return CurrentCompany::run($company, fn (): BankReconciliation => DB::transaction(function () use ($user, $company, $account, $date, $statement, $outstandingRowIds, $explanation): BankReconciliation {
            $prepared = $this->prepare($company, $account, CarbonImmutable::parse($date));
            $outstanding = $prepared['rows']->whereIn('id', $outstandingRowIds);
            $outstandingOre = (int) $outstanding->sum(fn (VoucherRow $row): int => $row->debit_ore->ore - $row->credit_ore->ore);
            $difference = $statement - ($prepared['ledger'] - $outstandingOre);

            if ($difference !== 0 && blank($explanation)) {
                throw ValidationException::withMessages(['explanation' => __('Förklara skillnaden på :amount innan avstämningen sparas.', ['amount' => Money::ofOre($difference)->format()])]);
            }

            $reconciliation = BankReconciliation::create([
                'fiscal_year_id' => $prepared['fiscal_year']->id,
                'account_id' => $account->id,
                'statement_date' => $date,
                'statement_balance_ore' => $statement,
                'ledger_balance_ore' => $prepared['ledger'],
                'outstanding_ore' => $outstandingOre,
                'difference_ore' => $difference,
                'outstanding_row_ids' => array_values($outstanding->modelKeys()),
                'explanation' => blank($explanation) ? null : $explanation,
                'reconciled_by' => $user->id,
            ]);

            Audit::record('bank.reconciled', $reconciliation, [
                'account' => $account->number,
                'date' => $date,
                'statement_ore' => $statement,
                'ledger_ore' => $prepared['ledger'],
                'difference_ore' => $difference,
            ], $company, $user);

            return $reconciliation;
        }));
    }

    private function authorize(User $user, Company $company): void
    {
        if (! $user->hasTeamPermission($company, 'vouchers:post')) {
            throw new AuthorizationException;
        }
    }
}
