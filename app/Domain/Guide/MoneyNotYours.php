<?php

declare(strict_types=1);

namespace App\Domain\Guide;

use App\Domain\Accounting\VoucherStatus;
use App\Domain\Deadlines\CompanyDeadlines;
use App\Domain\Deadlines\Deadline;
use App\Domain\Deadlines\DeadlineType;
use App\Domain\Vat\VatBoxes;
use App\Domain\Vat\VatReturn;
use App\Models\Account;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\OpeningBalance;
use App\Models\Voucher;
use App\Models\VoucherRow;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;

/**
 * Momsbufferten (ADR-051, stage 3): how much of the money in the bank is not
 * the company's, read from the books only. The balances of the bank
 * accounts (1900–1999, as on Översikt), the VAT accounts the settlement
 * moves to 2650 (VatBoxes::isSettled, "ännu inte redovisad") and 2650 itself
 * ("redovisad men inte betald"), the employer's liabilities on 2710, 2730
 * and 2731 when the company is an employer, and the tax account 1630, all
 * from posted vouchers dated up to today, plus the opening balances.
 *
 * Debited preliminary tax is not in the books until it is booked, so it is
 * never computed: only when the company has entered the monthly amount of
 * its decision (preliminary_tax_monthly_ore) are the payments up to the next
 * VAT date shown, dated by the deadline engine.
 *
 * An account the chart does not have is left out, never shown as 0. Read
 * only; it changes nothing. Six queries, however many years and vouchers,
 * plus the deadline engine when a monthly amount is entered.
 */
final class MoneyNotYours
{
    /** The bank and cash accounts, as Översikt's "Pengar på bank och kassa". */
    public const array BANK = [1900, 1999];

    public const int TAX_ACCOUNT = 1630;

    /** BAS: personalskatt, lagstadgade sociala avgifter, and their avräkning account. */
    public const array EMPLOYER = [2710, 2730, 2731];

    public function __construct(private readonly CompanyDeadlines $deadlines) {}

    /** The figures for the company today, or null when it has no fiscal year that has begun. */
    public function for(Company $company, CarbonImmutable $today): ?MoneyNotYoursFigures
    {
        $today = $today->startOfDay();

        return CurrentCompany::run($company, function () use ($company, $today): ?MoneyNotYoursFigures {
            $years = FiscalYear::query()->whereDate('start_date', '<=', $today->toDateString())->orderBy('start_date')->get(['id', 'start_date']);

            if ($years->isEmpty()) {
                return null;
            }

            $accounts = Account::query()
                ->where(fn ($query) => $query->whereBetween('number', self::BANK)
                    ->orWhere('number', self::TAX_ACCOUNT)
                    ->orWhereBetween('number', VatBoxes::VAT_ACCOUNTS)
                    ->orWhere('number', VatBoxes::SETTLEMENT_ACCOUNT)
                    ->orWhereIn('number', self::EMPLOYER))
                ->orderBy('number')
                ->get(['id', 'number', 'name'])
                ->keyBy('id');

            $yearIds = array_values(array_map(intval(...), $years->pluck('id')->all()));
            $accountIds = array_values(array_map(intval(...), $accounts->keys()->all()));
            $balances = $this->balances($yearIds, $accountIds, $today);
            $line = fn (Account $account, int $sign): array => ['number' => $account->number, 'name' => (string) $account->name, 'amount' => $sign * ($balances[$account->id] ?? 0)];
            $byNumber = $accounts->keyBy('number');

            $bank = $accounts->filter(fn (Account $account): bool => $account->number >= self::BANK[0] && $account->number <= self::BANK[1]);
            $bankLines = $bank->filter(fn (Account $account): bool => ($balances[$account->id] ?? 0) !== 0)->map(fn (Account $account): array => $line($account, 1))->values()->all();

            if ($bank->isNotEmpty() && $bankLines === []) {
                // A bank account with nothing on it still says where the money would be.
                $first = $byNumber->get(1930) ?? $bank->first();
                $bankLines = [$line($first, 1)];
            }

            // VAT is a liability: credit balances are positive here, what the company owes.
            $unreported = $accounts->filter(fn (Account $account): bool => VatBoxes::isSettled($account->number) && ($balances[$account->id] ?? 0) !== 0)
                ->map(fn (Account $account): array => $line($account, -1))->values()->all();
            $settlement = $byNumber->get(VatBoxes::SETTLEMENT_ACCOUNT);
            $employer = $company->employer_registered
                ? collect(self::EMPLOYER)->map(fn (int $number): ?Account => $byNumber->get($number))->filter()->map(fn (Account $account): array => $line($account, -1))->values()->all()
                : [];
            $tax = $byNumber->get(self::TAX_ACCOUNT);

            /** @var list<array{number: int, name: string, amount: int}> $bankLines */
            /** @var list<array{number: int, name: string, amount: int}> $unreported */
            /** @var list<array{number: int, name: string, amount: int}> $employer */
            return new MoneyNotYoursFigures(
                asOf: $today,
                lastVoucher: $this->lastVoucher($today),
                bank: $bank->isEmpty() ? null : $bankLines,
                vatUnreported: $accounts->contains(fn (Account $account): bool => VatBoxes::isSettled($account->number)) ? $unreported : null,
                vatReported: $settlement === null ? null : $line($settlement, -1),
                employer: $company->employer_registered && $employer !== [] ? $employer : null,
                taxAccount: $tax === null ? null : $line($tax, 1),
                preliminaryTax: $this->preliminaryTax($company, $today),
            );
        });
    }

    /**
     * The VAT of each period whose date is still ahead and that has begun, as
     * the VAT report computes it (VatReturn, box 49), for "Till
     * momsdeklarationen 12 februari: ca 18 400 kr" on Mina datum. A period
     * still running counts what is booked so far. Three queries a period.
     *
     * @param  list<Deadline>  $deadlines  the company's dates from the deadline engine
     * @return list<array{deadline: Deadline, amount: int, running: bool}> amount in öre, positive to pay
     */
    public function vatByDeadline(Company $company, array $deadlines, CarbonImmutable $today): array
    {
        $today = $today->startOfDay();
        $periods = array_values(array_filter($deadlines, fn (Deadline $deadline): bool => $deadline->type === DeadlineType::Vat
            && $deadline->periodStart !== null && $deadline->periodStart->lessThanOrEqualTo($today) && $deadline->daysLeft($today) >= 0));

        if ($periods === []) {
            return [];
        }

        return CurrentCompany::run($company, function () use ($periods, $today): array {
            $year = FiscalYear::query()->orderBy('start_date')->first();

            if (! $year instanceof FiscalYear) {
                return [];
            }

            return array_map(function (Deadline $deadline) use ($year, $today): array {
                // The key is vat:<last day of the period> (VatDeadlineProvider).
                $end = CarbonImmutable::parse(substr($deadline->key, 4))->startOfDay();

                return [
                    'deadline' => $deadline,
                    'amount' => VatReturn::calculate($year, $deadline->periodStart ?? $end, $end)->payable() * 100,
                    'running' => $end->greaterThanOrEqualTo($today),
                ];
            }, $periods);
        });
    }

    /**
     * Each account's balance at the end of today: from the latest fiscal
     * year with opening balances (or the first one), its opening balances
     * plus every posted voucher of it and the years after, so a year whose
     * balances are not carried forward yet still shows the money.
     *
     * @param  list<int>  $yearIds  the years that have begun, oldest first
     * @param  list<int>  $accountIds
     * @return array<int, int> account id => signed öre, debit positive
     */
    private function balances(array $yearIds, array $accountIds, CarbonImmutable $today): array
    {
        if ($accountIds === []) {
            return [];
        }

        $withOpening = OpeningBalance::query()->whereIn('fiscal_year_id', $yearIds)->distinct()->pluck('fiscal_year_id')->map(fn (mixed $id): int => (int) $id)->all();
        $base = $yearIds[0];

        foreach ($yearIds as $id) {
            if (in_array($id, $withOpening, true)) {
                $base = $id;
            }
        }

        $from = array_slice($yearIds, (int) array_search($base, $yearIds, true));

        $opening = OpeningBalance::query()->where('fiscal_year_id', $base)->whereIn('account_id', $accountIds)
            ->toBase()->groupBy('account_id')->selectRaw('account_id, SUM(amount_ore) AS amount')->pluck('amount', 'account_id');
        $posted = VoucherRow::query()
            ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
            ->whereIn('vouchers.fiscal_year_id', $from)
            ->where('vouchers.status', VoucherStatus::Posted->value)
            ->whereDate('vouchers.voucher_date', '<=', $today->toDateString())
            ->whereIn('voucher_rows.account_id', $accountIds)
            ->toBase()->groupBy('voucher_rows.account_id')
            ->selectRaw('voucher_rows.account_id, SUM(voucher_rows.debit_ore - voucher_rows.credit_ore) AS amount')
            ->pluck('amount', 'account_id');

        $balances = [];

        foreach ($accountIds as $id) {
            $balances[$id] = (int) ($opening[$id] ?? 0) + (int) ($posted[$id] ?? 0);
        }

        return $balances;
    }

    private function lastVoucher(CarbonImmutable $today): ?CarbonImmutable
    {
        $date = Voucher::query()->where('status', VoucherStatus::Posted->value)->whereDate('voucher_date', '<=', $today->toDateString())->max('voucher_date');

        return $date === null ? null : CarbonImmutable::parse((string) $date)->startOfDay();
    }

    /**
     * The monthly amount the company has entered from its decision, times the
     * payment dates from today up to and including the next VAT date (or the
     * next payment only, without VAT). Null without an amount or F-skatt.
     *
     * @return array{monthly: int, count: int, total: int, until: ?string}|null
     */
    private function preliminaryTax(Company $company, CarbonImmutable $today): ?array
    {
        $monthly = $company->preliminary_tax_monthly_ore;

        if (! $company->f_skatt || $monthly === null || $monthly <= 0) {
            return null;
        }

        $upcoming = $this->deadlines->upcoming($company, $today);
        $vat = collect($upcoming)->first(fn (Deadline $deadline): bool => $deadline->type === DeadlineType::Vat);
        $payments = collect($upcoming)->filter(fn (Deadline $deadline): bool => $deadline->type === DeadlineType::PreliminaryTax && ! $deadline->isRecommendation);
        $payments = $vat === null
            ? $payments->take(1)
            : $payments->filter(fn (Deadline $deadline): bool => $deadline->date->lessThanOrEqualTo($vat->date));

        return [
            'monthly' => $monthly,
            'count' => $payments->count(),
            'total' => $monthly * $payments->count(),
            'until' => $vat?->date->toDateString(),
        ];
    }
}
