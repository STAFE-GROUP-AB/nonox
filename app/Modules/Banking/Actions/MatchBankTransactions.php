<?php

declare(strict_types=1);

namespace App\Modules\Banking\Actions;

use App\Domain\Accounting\VoucherStatus;
use App\Models\Company;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherRow;
use App\Modules\Banking\Models\BankFeedAccount;
use App\Modules\Banking\Models\BankFeedTransaction;
use App\Modules\Banking\Support\BankingGate;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Matches bank transactions to rows already booked on the bank account's
 * ledger account (ADR-034). NONOX proposes: the same amount, in or out,
 * booked within a week of the bank's date, each row used once. A person
 * confirms. Nothing is booked here; a transaction without a row is
 * something to book (or explain) by hand.
 */
class MatchBankTransactions
{
    public const int WINDOW_DAYS = 7;

    /**
     * Proposals for the unmatched transactions given: transaction id =>
     * ledger row. Two queries, whatever the number of transactions.
     *
     * @param  Collection<int, BankFeedTransaction>  $transactions
     * @return array<int, VoucherRow>
     */
    public function proposals(BankFeedAccount $account, Collection $transactions): array
    {
        $open = $transactions->filter(fn (BankFeedTransaction $transaction): bool => ! $transaction->isMatched())->sortBy('booking_date');

        if ($account->account_id === null || $open->isEmpty()) {
            return [];
        }

        /** @var BankFeedTransaction $first */
        $first = $open->first();
        /** @var BankFeedTransaction $last */
        $last = $open->last();

        $rows = VoucherRow::query()->select('voucher_rows.*', 'vouchers.voucher_date as booked_on')
            ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
            ->where('vouchers.status', VoucherStatus::Posted->value)
            ->where('voucher_rows.account_id', $account->account_id)
            ->whereBetween('vouchers.voucher_date', [$first->booking_date->subDays(self::WINDOW_DAYS)->toDateString(), $last->booking_date->addDays(self::WINDOW_DAYS)->toDateString()])
            ->whereNotIn('voucher_rows.id', BankFeedTransaction::query()->whereNotNull('voucher_row_id')->select('voucher_row_id'))
            ->with('voucher.series')
            ->get();

        $proposals = [];
        $taken = [];

        foreach ($open as $transaction) {
            $best = null;
            $bestDistance = PHP_INT_MAX;

            foreach ($rows as $row) {
                if (isset($taken[$row->id]) || $row->debit_ore->ore - $row->credit_ore->ore !== $transaction->amount_ore->ore) {
                    continue;
                }

                $distance = abs((int) $transaction->booking_date->diffInDays(CarbonImmutable::parse((string) $row->getAttribute('booked_on')), false));

                if ($distance <= self::WINDOW_DAYS && $distance < $bestDistance) {
                    $best = $row;
                    $bestDistance = $distance;
                }
            }

            if ($best !== null) {
                $proposals[$transaction->id] = $best;
                $taken[$best->id] = true;
            }
        }

        return $proposals;
    }

    public function confirm(User $user, Company $company, BankFeedTransaction $transaction, int $voucherRowId): void
    {
        BankingGate::match($user, $company);

        CurrentCompany::run($company, fn () => DB::transaction(function () use ($user, $company, $transaction, $voucherRowId): void {
            $transaction = BankFeedTransaction::query()->lockForUpdate()->findOrFail($transaction->id);
            $account = BankFeedAccount::query()->findOrFail($transaction->bank_feed_account_id);
            $row = VoucherRow::query()->findOrFail($voucherRowId);
            $voucher = Voucher::query()->findOrFail($row->voucher_id);

            if ($transaction->isMatched()) {
                throw ValidationException::withMessages(['transaction' => __('Transaktionen är redan matchad.')]);
            }

            if ($voucher->status !== VoucherStatus::Posted || $row->account_id !== $account->account_id) {
                throw ValidationException::withMessages(['transaction' => __('Raden är inte bokförd på bankkontots konto.')]);
            }

            if ($row->debit_ore->ore - $row->credit_ore->ore !== $transaction->amount_ore->ore) {
                throw ValidationException::withMessages(['transaction' => __('Beloppen är inte lika.')]);
            }

            if (BankFeedTransaction::query()->where('voucher_row_id', $row->id)->exists()) {
                throw ValidationException::withMessages(['transaction' => __('Raden är redan matchad mot en annan banktransaktion.')]);
            }

            $transaction->forceFill(['voucher_row_id' => $row->id, 'matched_by' => $user->id, 'matched_at' => now()])->save();

            Audit::record('banking.matched', $transaction, [
                'date' => $transaction->booking_date->toDateString(),
                'amount_ore' => $transaction->amount_ore->ore,
                'voucher_row_id' => $row->id,
                'voucher_id' => $row->voucher_id,
            ], $company, $user);
        }));
    }

    public function unmatch(User $user, Company $company, BankFeedTransaction $transaction): void
    {
        BankingGate::match($user, $company);

        CurrentCompany::run($company, function () use ($user, $company, $transaction): void {
            if (! $transaction->isMatched()) {
                return;
            }

            $row = $transaction->voucher_row_id;
            $transaction->forceFill(['voucher_row_id' => null, 'matched_by' => null, 'matched_at' => null])->save();

            Audit::record('banking.unmatched', $transaction, ['voucher_row_id' => $row], $company, $user);
        });
    }
}
