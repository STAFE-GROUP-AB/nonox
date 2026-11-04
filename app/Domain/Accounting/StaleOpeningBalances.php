<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use App\Actions\Accounting\CarryForwardBalances;
use App\Domain\Closing\ClosingAccounts;
use App\Domain\Overview\OverviewCache;
use App\Models\Account;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\OpeningBalance;
use App\Models\VoucherRow;
use App\Support\Tenancy\CurrentCompany;

/**
 * Finds opening balances that no longer agree with the closing balances of
 * the year before (ÅRL 2 kap. 4 § första stycket 7): something was posted
 * in the old year, or corrected, after its balances were carried forward.
 *
 * A year is compared only when the year right before it is in NONOX and has
 * something booked, and when the year itself already has opening balances.
 * The first year in NONOX (opening balances from an SIE file or typed in) has
 * nothing to compare with; a year whose balances were never carried forward
 * is not stale, it waits for the closing.
 *
 * The expected balances are computed exactly as CarryForwardBalances writes
 * them (CarriedBalances), from the corrected balances of the year before, so
 * a chain N → N+1 → N+2 shows every year that an update would change.
 *
 * Four queries for the whole company, however many years and accounts, and
 * the answer is cached with Översikt's per-company version, which changes
 * after every posting, correction, SIE import and change of opening balances
 * (OverviewCache).
 */
final class StaleOpeningBalances
{
    /**
     * @return list<array{from_id: int, to_id: int, from: string, to: string, to_open: bool, locked: bool, accounts: list<array{number: int, name: string, stored: int, expected: int}>}>
     */
    public static function for(Company $company): array
    {
        return OverviewCache::remember($company->id, 'stale-opening-balances', fn (): array => self::compute($company));
    }

    /**
     * The stale pair whose later year is the given one, if any.
     *
     * @return ?array{from_id: int, to_id: int, from: string, to: string, to_open: bool, locked: bool, accounts: list<array{number: int, name: string, stored: int, expected: int}>}
     */
    public static function forYear(Company $company, FiscalYear $year): ?array
    {
        foreach (self::for($company) as $stale) {
            if ($stale['to_id'] === $year->id) {
                return $stale;
            }
        }

        return null;
    }

    /**
     * Uncached, for the update itself.
     *
     * @return list<array{from_id: int, to_id: int, from: string, to: string, to_open: bool, locked: bool, accounts: list<array{number: int, name: string, stored: int, expected: int}>}>
     */
    public static function compute(Company $company): array
    {
        return CurrentCompany::run($company, function () use ($company): array {
            $years = FiscalYear::query()->orderBy('start_date')->get();

            if ($years->count() < 2) {
                return [];
            }

            $accounts = Account::query()->get(['id', 'number', 'name', 'type']);
            $numbers = $accounts->pluck('number', 'id')->map(fn (mixed $number): int => (int) $number)->all();
            $names = $accounts->pluck('name', 'number')->all();
            $types = $accounts->mapWithKeys(fn (Account $account): array => [$account->number => $account->type])->all();

            $stored = [];

            foreach (OpeningBalance::query()->toBase()->get(['fiscal_year_id', 'account_id', 'amount_ore']) as $row) {
                $stored[(int) $row->fiscal_year_id][$numbers[(int) $row->account_id]] = (int) $row->amount_ore;
            }

            $movements = [];
            $rows = VoucherRow::query()
                ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
                ->where('vouchers.status', VoucherStatus::Posted->value)
                ->groupBy('vouchers.fiscal_year_id', 'voucher_rows.account_id')
                ->selectRaw('vouchers.fiscal_year_id, voucher_rows.account_id, SUM(voucher_rows.debit_ore - voucher_rows.credit_ore) AS movement')
                ->toBase()
                ->get();

            foreach ($rows as $row) {
                $movements[(int) $row->fiscal_year_id][$numbers[(int) $row->account_id]] = (int) $row->movement;
            }

            $resultNumber = ClosingAccounts::resultNumber($company);
            $byEnd = $years->keyBy(fn (FiscalYear $year): string => $year->end_date->toDateString());
            $corrected = [];
            $stale = [];

            foreach ($years as $year) {
                $own = $stored[$year->id] ?? [];
                $corrected[$year->id] = $own;
                $previous = $byEnd->get($year->start_date->subDay()->toDateString());

                if (! $previous instanceof FiscalYear || $own === []) {
                    continue;
                }

                $previousOpening = $corrected[$previous->id];
                $previousMovements = $movements[$previous->id] ?? [];

                if ($previousOpening === [] && $previousMovements === []) {
                    continue;
                }

                $expected = CarriedBalances::closing($types, $previousOpening, $previousMovements, $resultNumber);
                $differing = [];

                foreach (array_unique([...array_keys($expected), ...array_keys($own)]) as $number) {
                    if (($expected[$number] ?? 0) !== ($own[$number] ?? 0)) {
                        $differing[] = [
                            'number' => (int) $number,
                            'name' => (string) ($names[$number] ?? __('Årets resultat')),
                            'stored' => $own[$number] ?? 0,
                            'expected' => $expected[$number] ?? 0,
                        ];
                    }
                }

                if ($differing === []) {
                    continue;
                }

                usort($differing, fn (array $a, array $b): int => $a['number'] <=> $b['number']);
                $corrected[$year->id] = $expected;
                $stale[] = [
                    'from_id' => $previous->id,
                    'to_id' => $year->id,
                    'from' => $previous->label(),
                    'to' => $year->label(),
                    'to_open' => $year->isOpen(),
                    'locked' => CarryForwardBalances::lockedFirstDay($company, $year),
                    'accounts' => $differing,
                ];
            }

            return $stale;
        });
    }
}
