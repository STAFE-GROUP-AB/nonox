<?php

declare(strict_types=1);

namespace App\Domain\Guide;

use App\Domain\Accounting\VoucherStatus;
use App\Domain\Deadlines\CompanyDeadlines;
use App\Domain\Deadlines\Deadline;
use App\Domain\Deadlines\DeadlineCompletion;
use App\Domain\Deadlines\DeadlineType;
use App\Domain\Overview\OverviewCache;
use App\Domain\Vat\OssRates;
use App\Domain\Vat\VatBoxes;
use App\Domain\Vat\VatReturn;
use App\Models\Account;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\OpeningBalance;
use App\Models\Voucher;
use App\Models\VoucherRow;
use App\Support\Guide\Guide;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;

/**
 * Momsbufferten (ADR-051, stage 3): how much of the money in the bank is not
 * the company's, read from the books only. The balances of the company's own
 * money in BAS group 19 (BANK; special accounts and redovisningsmedel are
 * listed apart, not counted), the VAT accounts the settlement moves to 2650
 * (VatBoxes::isSettled, "ännu inte redovisad"), 2650 itself ("redovisad men
 * inte betald"), OSS VAT on 2619, the employer's liabilities on 2710, 2730
 * and 2731, and the tax account 1630, all from posted vouchers dated up to
 * today, plus the opening balances.
 *
 * Debited preliminary tax is never computed: only when the company has
 * entered the monthly amount of its decision (preliminary_tax_monthly_ore)
 * are the coming payments up to the next VAT date shown, dated by the
 * deadline engine, and never one already booked.
 *
 * An account the chart does not have is left out, never shown as 0. Read
 * only; it changes nothing. Six queries, however many years and vouchers,
 * plus one, and the deadline engine unless the caller passes its dates, when
 * a monthly amount is entered.
 */
/** @phpstan-import-type DeadlineData from Deadline */
final class MoneyNotYours
{
    /**
     * The company's own money in BAS kontogrupp 19 (BAS kontoplan 2026,
     * bas.se, BAS_kontoplan_2026_v2): 1910 Kassa, 1920 PlusGiro, 1930
     * Företagskonto, 1940 Övriga bankkonton, 1950 Bankcertifikat, 1960
     * Koncernkonto moderföretag and 1980 Valutakonton.
     *
     * @var list<array{0: int, 1: int}>
     */
    public const array BANK = [[1910, 1969], [1980, 1989]];

    /** 1970 Särskilda bankkonton (1972 Upphovsmannakonto, 1973 Skogskonto, 1974 Spärrade bankmedel, 1979 Övriga särskilda bankkonton): not free to use. */
    public const array SPECIAL = [1970, 1979];

    /** 1990 Redovisningsmedel: money held for someone else, never the company's. */
    public const array HELD_FOR_OTHERS = [1990, 1999];

    public const int TAX_ACCOUNT = 1630;

    /** BAS: personalskatt, lagstadgade sociala avgifter, and their avräkning account. */
    public const array EMPLOYER = [2710, 2730, 2731];

    public function __construct(private readonly CompanyDeadlines $deadlines) {}

    /**
     * The figures and the VAT per coming declaration, as Översikt and Mina
     * datum show them: one entry in Översikt's cache (ADR-027), so both pages
     * show the same figures, the deadline engine runs once on a miss, and a
     * posting, a change of the company's details or the reload arrow makes it
     * stale.
     *
     * @return array{figures: ?MoneyNotYoursFigures, vat: list<array{deadline: Deadline, amount: int, running: bool}>}
     */
    public function summary(Company $company, CarbonImmutable $today): array
    {
        /** @var array{figures: array<string, mixed>|null, vat: list<array{deadline: DeadlineData, amount: int, running: bool}>} $cached */
        $cached = OverviewCache::remember($company->id, 'money-not-yours:'.$today->toDateString().':'.Guide::version(), function () use ($company, $today): array {
            $deadlines = $this->deadlines->upcoming($company, $today);
            $figures = $this->for($company, $today, $deadlines);

            return [
                'figures' => $figures?->toArray(),
                'vat' => $figures === null ? [] : array_map(fn (array $item): array => [...$item, 'deadline' => $item['deadline']->toArray()], $this->vatByDeadline($company, $deadlines, $today)),
            ];
        });

        return [
            'figures' => $cached['figures'] === null ? null : MoneyNotYoursFigures::fromArray($cached['figures']),
            'vat' => array_map(fn (array $item): array => [...$item, 'deadline' => Deadline::fromArray($item['deadline'])], $cached['vat']),
        ];
    }

    /**
     * The figures for the company today, or null when it has no fiscal year
     * that has begun. The deadlines, when the caller has them, save the
     * engine a second run for the coming preliminary tax.
     *
     * @param  list<Deadline>|null  $deadlines  the company's dates from the deadline engine, today included
     */
    public function for(Company $company, CarbonImmutable $today, ?array $deadlines = null): ?MoneyNotYoursFigures
    {
        $today = $today->startOfDay();

        return CurrentCompany::run($company, function () use ($company, $today, $deadlines): ?MoneyNotYoursFigures {
            $years = FiscalYear::query()->whereDate('start_date', '<=', $today->toDateString())->orderBy('start_date')->get(['id', 'start_date']);

            if ($years->isEmpty()) {
                return null;
            }

            $accounts = Account::query()
                ->where(fn ($query) => $query->whereBetween('number', [1900, 1999])
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
            $hasBalance = fn (Account $account): bool => ($balances[$account->id] ?? 0) !== 0;
            $byNumber = $accounts->keyBy('number');
            $within = fn (Account $account, array $range): bool => $account->number >= $range[0] && $account->number <= $range[1];

            $bank = $accounts->filter(fn (Account $account): bool => $within($account, self::BANK[0]) || $within($account, self::BANK[1]));
            $bankLines = $bank->filter($hasBalance)->map(fn (Account $account): array => $line($account, 1))->values()->all();

            if ($bank->isNotEmpty() && $bankLines === []) {
                // A bank account with nothing on it still says where the money would be.
                $first = $byNumber->get(1930) ?? $bank->first();
                $bankLines = [$line($first, 1)];
            }

            $excluded = $accounts->filter(fn (Account $account): bool => ($within($account, self::SPECIAL) || $within($account, self::HELD_FOR_OTHERS)) && $hasBalance($account))
                ->map(fn (Account $account): array => [...$line($account, 1), 'reason' => $within($account, self::HELD_FOR_OTHERS) ? 'held_for_others' : 'special'])->values()->all();

            // VAT is a liability: credit balances are positive here, what the company owes.
            $vatAccounts = $accounts->filter(fn (Account $account): bool => VatBoxes::isSettled($account->number));
            $unreported = $vatAccounts->filter($hasBalance)->map(fn (Account $account): array => $line($account, -1))->values()->all();
            $settlement = $byNumber->get(VatBoxes::SETTLEMENT_ACCOUNT);
            // A company that is not registered for VAT and has nothing on the VAT accounts has no VAT line at all.
            $noVat = ! $company->isVatRegistered() && $unreported === [] && ($settlement === null || ! $hasBalance($settlement));
            // OSS VAT is declared in the OSS return, not on the skattekonto, but it is just as much not the company's (OssRates).
            $oss = $byNumber->get(OssRates::VAT_ACCOUNT);
            $employerAccounts = collect(self::EMPLOYER)->map(fn (int $number): ?Account => $byNumber->get($number))->filter();
            // Liabilities booked on 2710/2730/2731 count even when the company is not marked as an employer.
            $employer = $company->employer_registered || $employerAccounts->contains($hasBalance)
                ? $employerAccounts->map(fn (Account $account): array => $line($account, -1))->values()->all()
                : [];
            $tax = $byNumber->get(self::TAX_ACCOUNT);

            /** @var list<array{number: int, name: string, amount: int}> $bankLines */
            /** @var list<array{number: int, name: string, amount: int, reason: string}> $excluded */
            /** @var list<array{number: int, name: string, amount: int}> $unreported */
            /** @var list<array{number: int, name: string, amount: int}> $employer */
            return new MoneyNotYoursFigures(
                asOf: $today,
                lastVoucher: $this->lastVoucher($today),
                bank: $bank->isEmpty() ? null : $bankLines,
                vatUnreported: $noVat || $vatAccounts->isEmpty() ? null : $unreported,
                vatReported: $noVat || $settlement === null ? null : $line($settlement, -1),
                employer: $employer === [] ? null : $employer,
                taxAccount: $tax === null ? null : $line($tax, 1),
                preliminaryTax: $this->preliminaryTax($company, $today, $deadlines, $tax?->id),
                vatOss: $oss === null || ! $hasBalance($oss) ? null : $line($oss, -1),
                excluded: $excluded,
            );
        });
    }

    /**
     * The VAT of each period whose date is still ahead and that has begun, as
     * the VAT report computes it (VatReturn, box 49), for "Till
     * momsdeklarationen 12 februari: ca 18 400 kr" on Mina datum. A period
     * still running counts what is booked so far, and a period already reported
     * is left out. Three queries a period.
     *
     * @param  list<Deadline>  $deadlines  the company's dates from the deadline engine
     * @param  list<string>|null  $done  the keys DeadlineCompletion::done already found, when the caller has them
     * @return list<array{deadline: Deadline, amount: int, running: bool}> amount in öre, positive to pay
     */
    public function vatByDeadline(Company $company, array $deadlines, CarbonImmutable $today, ?array $done = null): array
    {
        $today = $today->startOfDay();
        $periods = array_values(array_filter($deadlines, fn (Deadline $deadline): bool => $deadline->type === DeadlineType::Vat
            && $deadline->periodStart !== null && $deadline->periodStart->lessThanOrEqualTo($today) && $deadline->daysLeft($today) >= 0));
        // A period already reported in NONOX is on 2650 now, in "redovisad men inte betald", not here.
        $done ??= DeadlineCompletion::done($company, $periods);
        $periods = array_values(array_filter($periods, fn (Deadline $deadline): bool => ! in_array($deadline->key, $done, true)));

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
                $running = $end->greaterThanOrEqualTo($today);

                return [
                    'deadline' => $deadline,
                    // A running period counts what is booked up to today, as the balances do; never a voucher dated later.
                    'amount' => VatReturn::calculate($year, $deadline->periodStart ?? $end, $running ? $today : $end)->payable() * 100,
                    'running' => $running,
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
     * payment dates after today up to and including the next VAT date (or the
     * next payment only, without VAT). Null without an amount or F-skatt.
     *
     * Never twice: a payment due today or earlier is already in the bank or
     * on 1630, and a month whose debit is already booked on 1630 (a credit of
     * the same amount, dated in the month and up to today) is in the tax
     * account's balance. TODO(legal-verify): the day Skatteverket debits the
     * preliminary tax on the skattekonto, which decides when a company books it.
     *
     * @param  list<Deadline>|null  $deadlines
     * @return array{monthly: int, count: int, total: int, until: ?string}|null
     */
    private function preliminaryTax(Company $company, CarbonImmutable $today, ?array $deadlines, ?int $taxAccountId): ?array
    {
        $monthly = $company->preliminary_tax_monthly_ore;

        if (! $company->f_skatt || $monthly === null || $monthly <= 0) {
            return null;
        }

        $upcoming = collect($deadlines ?? $this->deadlines->upcoming($company, $today));
        $vat = $upcoming->first(fn (Deadline $deadline): bool => $deadline->type === DeadlineType::Vat && $deadline->daysLeft($today) >= 0);
        $payments = $upcoming->filter(fn (Deadline $deadline): bool => $deadline->type === DeadlineType::PreliminaryTax && ! $deadline->isRecommendation && $deadline->daysLeft($today) > 0)->values();
        $payments = $vat === null
            ? $payments->take(1)
            : $payments->filter(fn (Deadline $deadline): bool => $deadline->date->lessThanOrEqualTo($vat->date));

        if ($payments->isNotEmpty() && $taxAccountId !== null) {
            $booked = VoucherRow::query()
                ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
                ->where('voucher_rows.account_id', $taxAccountId)
                ->where('voucher_rows.credit_ore', $monthly)
                ->where('vouchers.status', VoucherStatus::Posted->value)
                ->whereDate('vouchers.voucher_date', '>=', $payments->first()->date->startOfMonth()->toDateString())
                ->whereDate('vouchers.voucher_date', '<=', $today->toDateString())
                ->pluck('vouchers.voucher_date')
                ->map(fn (mixed $date): string => substr((string) $date, 0, 7))
                ->all();
            $payments = $payments->reject(fn (Deadline $deadline): bool => in_array($deadline->date->format('Y-m'), $booked, true));
        }

        return [
            'monthly' => $monthly,
            'count' => $payments->count(),
            'total' => $monthly * $payments->count(),
            'until' => $vat?->date->toDateString(),
        ];
    }
}
