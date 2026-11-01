<?php

declare(strict_types=1);

namespace App\Modules\Banking\Support;

use App\Models\Account;
use App\Models\Company;
use App\Models\VoucherRow;
use App\Modules\Banking\BankingModule;
use App\Modules\Banking\Models\BankFeedAccount;
use App\Modules\Banking\Models\BankFeedBalance;
use App\Modules\Banking\Models\BankFeedTransaction;
use App\Modules\ModuleManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * What the bank feed can tell the bank reconciliation (Bokföring →
 * Bankavstämning) about a ledger account on a date: the bank's balance, and
 * which ledger rows have no matching bank transaction yet, as a proposal
 * for "not at the bank yet". The person still checks and saves; nothing is
 * saved from here.
 */
final class BankFeedReconciliation
{
    /**
     * The balance at the end of the date. Exact when the bank reported that
     * day; otherwise worked out from the nearest reported balance and the
     * transactions in between (calculated: true).
     *
     * @return array{bank_account: BankFeedAccount, balance_ore: int, reported_on: CarbonImmutable, calculated: bool}|null
     */
    public static function balance(Company $company, Account $account, CarbonImmutable $date): ?array
    {
        $feed = self::feedAccount($company, $account);

        if ($feed === null) {
            return null;
        }

        $balances = BankFeedBalance::query()->where('bank_feed_account_id', $feed->id)->whereIn('balance_type', BankFeedBalance::PREFERRED)->get();
        $byDate = $balances->groupBy(fn (BankFeedBalance $balance): string => $balance->balance_date->toDateString())
            ->map(fn (Collection $day): BankFeedBalance => $day->sortBy(fn (BankFeedBalance $balance): int => (int) array_search($balance->balance_type, BankFeedBalance::PREFERRED, true))->firstOrFail());

        if ($byDate->isEmpty()) {
            return null;
        }

        $day = $date->toDateString();

        $exact = $byDate->get($day);

        if ($exact instanceof BankFeedBalance) {
            return ['bank_account' => $feed, 'balance_ore' => $exact->amount_ore->ore, 'reported_on' => $date, 'calculated' => false];
        }

        $before = $byDate->keys()->filter(fn (string $key): bool => $key < $day)->sort()->last();
        $after = $byDate->keys()->filter(fn (string $key): bool => $key > $day)->sort()->first();
        $beforeBalance = $before === null ? null : $byDate->get($before);
        $afterBalance = $after === null ? null : $byDate->get($after);

        if ($before !== null && $beforeBalance instanceof BankFeedBalance) {
            $movement = (int) BankFeedTransaction::query()->where('bank_feed_account_id', $feed->id)
                ->where('booking_date', '>', $before)->where('booking_date', '<=', $day)->sum('amount_ore');

            return ['bank_account' => $feed, 'balance_ore' => $beforeBalance->amount_ore->ore + $movement, 'reported_on' => CarbonImmutable::parse($before), 'calculated' => true];
        }

        if ($after !== null && $afterBalance instanceof BankFeedBalance) {
            $movement = (int) BankFeedTransaction::query()->where('bank_feed_account_id', $feed->id)
                ->where('booking_date', '>', $day)->where('booking_date', '<=', $after)->sum('amount_ore');

            return ['bank_account' => $feed, 'balance_ore' => $afterBalance->amount_ore->ore - $movement, 'reported_on' => CarbonImmutable::parse($after), 'calculated' => true];
        }

        return null;
    }

    /**
     * The rows to check that no bank transaction booked by the date is
     * matched to, from the day the feed starts. Rows from before the feed
     * are left as the person marked them.
     *
     * @param  EloquentCollection<int, VoucherRow>  $rows
     * @param  list<int>  $carried  rows already marked outstanding
     * @return list<int>
     */
    public static function outstanding(Company $company, Account $account, CarbonImmutable $date, EloquentCollection $rows, array $carried): array
    {
        $feed = self::feedAccount($company, $account);

        if ($feed === null) {
            return $carried;
        }

        $start = BankFeedTransaction::query()->where('bank_feed_account_id', $feed->id)->min('booking_date');
        $matched = BankFeedTransaction::query()->where('bank_feed_account_id', $feed->id)
            ->whereNotNull('voucher_row_id')->where('booking_date', '<=', $date->toDateString())
            ->whereIn('voucher_row_id', $rows->modelKeys())->pluck('voucher_row_id')->map(fn (mixed $id): int => (int) $id)->all();

        $outstanding = [];

        foreach ($rows as $row) {
            $inFeed = $start !== null && ($row->voucher?->voucher_date->toDateString() ?? '') >= (string) $start;

            if ($inFeed ? ! in_array($row->id, $matched, true) : in_array($row->id, $carried, true)) {
                $outstanding[] = $row->id;
            }
        }

        return $outstanding;
    }

    private static function feedAccount(Company $company, Account $account): ?BankFeedAccount
    {
        if (! app(ModuleManager::class)->isEnabled(BankingModule::KEY, $company)) {
            return null;
        }

        return BankFeedAccount::query()->where('account_id', $account->id)->where('is_active', true)->first();
    }
}
