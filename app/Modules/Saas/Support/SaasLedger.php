<?php

declare(strict_types=1);

namespace App\Modules\Saas\Support;

use App\Models\Company;
use App\Models\Invoice;
use App\Modules\Saas\Domain\Arithmetic;
use App\Modules\Saas\Domain\ContractVersion;
use App\Modules\Saas\Domain\CustomerMonth;
use App\Modules\Saas\Domain\CustomerState;
use App\Modules\Saas\Domain\LineMonth;
use App\Modules\Saas\Domain\MonthResult;
use App\Modules\Saas\Domain\Months;
use App\Modules\Saas\Domain\Movement;
use App\Modules\Saas\Domain\RecurringRevenue;
use App\Modules\Saas\Domain\RevenueHistory;
use App\Modules\Saas\Models\SaasContractVersion;
use App\Modules\Saas\Models\SaasCorrection;
use App\Modules\Saas\Models\SaasCustomerMonth;
use App\Modules\Saas\Models\SaasEstimate;
use App\Modules\Saas\Models\SaasMonth;
use App\Modules\Saas\SaasSettings;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The recurring-revenue ledger (ADR-080 §3–§4): writes what the pure
 * calculation (RecurringRevenue) returns, and nothing else.
 *
 * - recompute(): every open month from the first ledger month (history_from)
 *   to the current one is replaced by the calculation over every version
 *   known now. Closed months are never touched; what changed in them since
 *   they were closed is written as corrections in the first open month
 *   (SA-R22), so that month's bridge starts from the closed month as it was
 *   reported: MRR = reported MRR + Rättelse + Valutaeffekt + movements.
 * - verify(): recomputes every closed month from the versions known when it
 *   was closed and its frozen rates, and compares row by row.
 *
 * Corrections, per customer and closed month M up to the last closed month B:
 * gap(M) = the MRR known now − (the MRR reported for M + the corrections
 * already written for M or earlier in a later month). A correction for M is
 * gap(M) − gap(M−1): the change that starts in M. Together they bring the
 * last closed month to what is known now, and its "med rättelser" figure
 * becomes the reported figure plus the corrections, without changing it.
 */
final class SaasLedger
{
    /** How far before the first ledger month the estimate from issued invoices reaches. */
    public const int ESTIMATE_MONTHS = 24;

    public function __construct(
        private readonly RecurringRevenue $revenue,
        private readonly MonthEndRates $rates,
    ) {}

    /**
     * @return ?int the last version id the open months were computed with, or null when the module has no history yet
     */
    public function recompute(Company $company, ?CarbonImmutable $today = null): ?int
    {
        $today ??= CarbonImmutable::today();

        return CurrentCompany::run($company, fn (): ?int => DB::transaction(function () use ($company, $today): ?int {
            // One recompute per company at a time; the version read below is then the one closed with.
            DB::select('select pg_advisory_xact_lock(80080, ?)', [$company->id]);

            $from = SaasSettings::historyFrom($company);

            if ($from === null) {
                return null;
            }

            $current = Months::of($today->toDateString());
            [$versions, $knownUntil] = self::versions();
            $months = SaasMonth::query()->orderBy('month')->get()->keyBy(fn (SaasMonth $month): string => $month->key());
            $closed = $months->filter(fn (SaasMonth $month): bool => $month->isClosed());
            $lastClosed = $closed->last();
            $firstOpen = $lastClosed === null ? $from : Months::add($lastClosed->key(), 1);

            if ($firstOpen > $current) {
                return $knownUntil;
            }

            $history = $this->history($versions, $current, $closed, $today);
            $reported = $this->reported($closed);
            $corrections = $lastClosed === null ? [] : $this->correct($history, $closed, $reported, $firstOpen, $knownUntil);
            $issues = self::sourceIssues($versions);

            foreach (Months::range($firstOpen, $current) as $month) {
                $isBooked = $lastClosed !== null && $month === $firstOpen;
                $this->writeMonth(
                    $month,
                    $history->month($month),
                    $history->month(Months::add($month, -1))->rates ?? [],
                    $isBooked ? ($reported[$lastClosed->key()] ?? []) : null,
                    $isBooked ? $corrections : [],
                    $history->cohorts($month),
                    $issues,
                );
            }

            $this->estimate($from);

            return $knownUntil;
        }));
    }

    /**
     * Closes a month: the month is recomputed and then frozen with the last
     * version it used and its rates. Months close in order, after they have ended.
     */
    public function close(Company $company, string $month, int $userId, ?CarbonImmutable $today = null): SaasMonth
    {
        $today ??= CarbonImmutable::today();

        return CurrentCompany::run($company, fn (): SaasMonth => DB::transaction(function () use ($company, $month, $userId, $today): SaasMonth {
            $knownUntil = $this->recompute($company, $today);
            $row = SaasMonth::query()->where('month', $month)->lockForUpdate()->firstOrFail();
            $row->forceFill([
                'status' => SaasMonth::CLOSED,
                'closed_at' => now(),
                'closed_by' => $userId,
                'versions_known_until' => $knownUntil ?? 0,
            ])->save();

            return $row;
        }));
    }

    /** The month a person may close now: the first open month that has ended, or null. */
    public static function closable(CarbonImmutable $today): ?SaasMonth
    {
        $first = SaasMonth::query()->where('status', SaasMonth::OPEN)->orderBy('month')->first();

        if ($first === null || Months::end($first->key()) >= $today->toDateString()) {
            return null;
        }

        return $first;
    }

    /**
     * Recomputes every closed month from the versions known when it was
     * closed and its frozen rates (ADR-080 §4). Returns the months that differ,
     * with what differs; an empty list means every closed month reproduces.
     *
     * @return array<string, list<string>> month => problems
     */
    public function verify(Company $company, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();

        return CurrentCompany::run($company, function () use ($today): array {
            $closed = SaasMonth::query()->where('status', SaasMonth::CLOSED)->orderBy('month')->get()->keyBy(fn (SaasMonth $month): string => $month->key());

            if ($closed->isEmpty()) {
                return [];
            }

            $records = SaasContractVersion::query()->orderBy('id')->get();
            $all = array_values($records->map(fn (SaasContractVersion $version): ContractVersion => $version->toDomain())->all());
            $stored = SaasCustomerMonth::query()->whereIn('month', $closed->keys()->all())->get()->groupBy(fn (SaasCustomerMonth $row): string => $row->month->toDateString());
            $booked = SaasCorrection::query()->whereIn('booked_month', $closed->keys()->all())->get()->groupBy(fn (SaasCorrection $row): string => $row->booked_month->toDateString());
            $first = (string) $closed->keys()->last();

            foreach ($all as $version) {
                $first = min($first, Months::of($version->effectiveFrom));
            }

            $frozen = $this->rates->for($all, Months::range($first, (string) $closed->keys()->last()), self::frozenRates($closed), $today);
            $problems = [];
            $previous = null;

            foreach ($closed as $key => $month) {
                $history = $this->revenue->history($all, $key, $frozen, $month->versions_known_until);
                $result = $history->month($key);
                $rows = ($stored[$key] ?? collect())->keyBy('customer_id');
                $issues = [];
                $expected = $result->customers ?? [];
                $corrections = ($booked[$key] ?? collect())->groupBy('customer_id')->map(fn (Collection $rows): int => (int) $rows->sum('amount_ore'));

                foreach (array_unique([...array_keys($expected), ...$rows->keys()->all()]) as $customer) {
                    /** @var ?SaasCustomerMonth $row */
                    $row = $rows->get($customer);
                    $want = $expected[$customer] ?? null;

                    if (($row->mrr_ore ?? 0) !== ($want->mrrOre ?? 0)) {
                        $issues[] = "customer {$customer}: MRR";
                    }

                    if (($row->movement ?? null) !== ($want->movement ?? null) || ($row->movement_ore ?? 0) !== ($want->movementOre ?? 0)) {
                        $issues[] = "customer {$customer}: movement";
                    }

                    if (($row->fx_ore ?? 0) !== ($want->fxOre ?? 0)) {
                        $issues[] = "customer {$customer}: currency effect";
                    }

                    if ($row !== null && $row->mrr_ore !== $row->opening_ore + $row->correction_ore + $row->fx_ore + $row->movement_ore) {
                        $issues[] = "customer {$customer}: bridge";
                    }

                    if ($row !== null && $row->correction_ore !== ($corrections[$customer] ?? 0)) {
                        $issues[] = "customer {$customer}: corrections";
                    }

                    if ($row !== null && $previous !== null) {
                        $before = ($stored[$previous] ?? collect())->firstWhere('customer_id', $customer);

                        if ($row->opening_ore !== ($before->mrr_ore ?? 0)) {
                            $issues[] = "customer {$customer}: opening";
                        }
                    }
                }

                $sum = (int) ($stored[$key] ?? collect())->sum('mrr_ore');

                if ($sum !== $month->mrr_ore) {
                    $issues[] = 'total MRR';
                }

                if ($month->mrr_ore !== $month->opening_mrr_ore + $month->correction_ore + $month->fx_ore + array_sum(array_map(fn (Movement $movement): int => $month->movement($movement), Movement::cases()))) {
                    $issues[] = 'bridge';
                }

                if ($issues !== []) {
                    $problems[$key] = array_values(array_unique($issues));
                }

                $previous = $key;
            }

            return $problems;
        });
    }

    /**
     * @return array{0: list<ContractVersion>, 1: int}
     */
    private static function versions(): array
    {
        $records = SaasContractVersion::query()->orderBy('id')->get();

        return [
            array_values($records->map(fn (SaasContractVersion $version): ContractVersion => $version->toDomain())->all()),
            (int) ($records->max('id') ?? 0),
        ];
    }

    /**
     * @param  list<ContractVersion>  $versions
     * @param  Collection<string, SaasMonth>  $closed
     */
    private function history(array $versions, string $current, Collection $closed, CarbonImmutable $today): RevenueHistory
    {
        $first = $current;

        foreach ($versions as $version) {
            $first = min($first, Months::of($version->effectiveFrom));
        }

        $rates = $this->rates->for($versions, Months::range($first, $current), self::frozenRates($closed), $today);

        return $this->revenue->history($versions, $current, $rates);
    }

    /**
     * @param  Collection<string, SaasMonth>  $closed
     * @return array<string, array<string, int>>
     */
    private static function frozenRates(Collection $closed): array
    {
        $frozen = [];

        foreach ($closed as $key => $month) {
            foreach ((array) $month->rates as $rateMonth => $rates) {
                // A month's own rates win over the copy kept by the month after it.
                if ($rateMonth === $key || ! isset($frozen[$rateMonth])) {
                    $frozen[$rateMonth] = array_map(intval(...), (array) $rates);
                }
            }
        }

        return $frozen;
    }

    /**
     * @param  Collection<string, SaasMonth>  $closed
     * @return array<string, array<int, int>> month => customer => reported MRR
     */
    private function reported(Collection $closed): array
    {
        if ($closed->isEmpty()) {
            return [];
        }

        $reported = [];

        foreach (SaasCustomerMonth::query()->whereIn('month', $closed->keys()->all())->get(['month', 'customer_id', 'mrr_ore']) as $row) {
            $reported[$row->month->toDateString()][$row->customer_id] = $row->mrr_ore;
        }

        return $reported;
    }

    /**
     * Writes the corrections the first open month needs (see the class
     * comment) and returns them per customer, summed with those already there.
     *
     * @param  Collection<string, SaasMonth>  $closed
     * @param  array<string, array<int, int>>  $reported
     * @return array<int, int> customer => Rättelse in the first open month
     */
    private function correct(RevenueHistory $history, Collection $closed, array $reported, string $firstOpen, int $knownUntil): array
    {
        $existing = SaasCorrection::query()->get(['booked_month', 'affects_month', 'customer_id', 'amount_ore']);
        $byCustomer = $existing->groupBy('customer_id');
        $customers = [];

        foreach ($reported as $rows) {
            $customers += array_fill_keys(array_keys($rows), true);
        }

        foreach ($closed->keys() as $key) {
            $customers += array_fill_keys(array_keys($history->month($key)->customers ?? []), true);
        }

        foreach ($existing as $row) {
            $customers[$row->customer_id] = true;
        }

        $new = [];

        foreach (array_keys($customers) as $customer) {
            $previousGap = 0;

            foreach ($closed->keys() as $key) {
                $later = ($byCustomer[$customer] ?? collect())
                    ->filter(fn (SaasCorrection $row): bool => $row->affects_month->toDateString() <= $key && $row->booked_month->toDateString() > $key)
                    ->sum('amount_ore');
                $known = $history->month($key)?->customers[$customer]->mrrOre ?? 0;
                $gap = $known - (($reported[$key][$customer] ?? 0) + (int) $later);
                $change = $gap - $previousGap;
                $previousGap = $gap;

                if ($change !== 0) {
                    $new[] = [
                        'booked_month' => $firstOpen,
                        'affects_month' => $key,
                        'customer_id' => $customer,
                        'amount_ore' => $change,
                        'version_id' => $knownUntil,
                    ];
                }
            }
        }

        foreach ($new as $attributes) {
            SaasCorrection::create($attributes);
        }

        return SaasCorrection::query()
            ->where('booked_month', $firstOpen)
            ->get(['customer_id', 'amount_ore'])
            ->groupBy('customer_id')
            ->map(fn (Collection $rows): int => (int) $rows->sum('amount_ore'))
            ->filter(fn (int $sum): bool => $sum !== 0)
            ->all();
    }

    /**
     * Replaces an open month's rows and totals with the calculation.
     *
     * @param  array<string, int>  $previousRates  the month before's rates, kept so a closed month can be recomputed
     * @param  ?array<int, int>  $anchor  the last closed month's reported MRR per customer, for the first open month
     * @param  array<int, int>  $corrections  Rättelse per customer, for the first open month
     * @param  array<string, list<array{customers: int, mrr_ore: int}>>  $cohorts
     */
    private function writeMonth(string $month, ?MonthResult $result, array $previousRates, ?array $anchor, array $corrections, array $cohorts, int $issues): void
    {
        SaasCustomerMonth::query()->where('month', $month)->delete();

        $rows = $result->customers ?? [];
        $ids = array_unique([...array_keys($rows), ...array_keys($corrections), ...array_keys(array_filter($anchor ?? []))]);
        sort($ids);
        $now = now();
        $insert = [];
        $totals = ['opening' => 0, 'correction' => 0];

        foreach ($ids as $id) {
            $row = $rows[$id] ?? null;
            $correction = $corrections[$id] ?? 0;
            $opening = $anchor === null ? ($row->openingOre ?? 0) : ($anchor[$id] ?? 0);
            $totals['opening'] += $opening;
            $totals['correction'] += $correction;

            $insert[] = [
                'company_id' => CurrentCompany::id(),
                'month' => $month,
                'customer_id' => $id,
                'opening_ore' => $opening,
                'correction_ore' => $correction,
                'fx_ore' => $row->fxOre ?? 0,
                'movement' => $row?->movement?->value,
                'movement_ore' => $row->movementOre ?? 0,
                'reclass_ore' => $row->reclassOre ?? 0,
                'mrr_ore' => $row->mrrOre ?? 0,
                'state' => ($row->state ?? CustomerState::Churned)->value,
                'cohort_month' => $row?->cohortMonth,
                'scheduled_churn_ore' => $row->scheduledChurnOre ?? 0,
                'scheduled_churn_month' => $row?->scheduledChurnMonth,
                'lines' => json_encode(array_map(fn (LineMonth $line): array => $line->toArray(), $row->lines ?? []), JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
            ];
        }

        foreach (array_chunk($insert, 500) as $chunk) {
            SaasCustomerMonth::query()->insert($chunk);
        }

        $values = [
            'opening_mrr_ore' => $totals['opening'],
            'opening_customers' => count(array_filter($anchor ?? array_map(fn (CustomerMonth $row): int => $row->openingOre, $rows), fn (int $ore): bool => $ore > 0)),
            'mrr_ore' => $result?->mrrOre() ?? 0,
            'customers' => $result?->activeCustomers() ?? 0,
            'fx_ore' => $result?->fxOre() ?? 0,
            'correction_ore' => $totals['correction'],
            'new_customers' => $result?->customersWith(Movement::New) ?? 0,
            'churned_customers' => $result?->churnedCustomers() ?? 0,
            'churn_from_active_ore' => $result?->churnFromActiveOre() ?? 0,
            'nrr_base_ore' => $result?->nrrBaseOre,
            'nrr_ore' => $result?->nrrOre,
            'grr_ore' => $result?->grrOre,
            'scheduled_churn_ore' => $result?->scheduledChurnOre() ?? 0,
            'source_issues' => $issues + count($result->missingRates ?? []),
            'missing_rates' => $result->missingRates ?? [],
            'cohorts' => $cohorts,
            'rates' => [$month => $result->rates ?? [], Months::add($month, -1) => $previousRates],
            'computed_at' => $now,
        ];

        foreach (Movement::cases() as $movement) {
            $values[$movement->column()] = $result?->movementOre($movement) ?? 0;
        }

        SaasMonth::query()->updateOrCreate(['month' => $month], $values);
    }

    /**
     * Months before the ledger starts, estimated from the recurring invoices
     * issued then (O9): each invoice spread over the months its interval
     * covers, billed in advance. Credit invoices are left out (SA-R11).
     */
    private function estimate(string $from): void
    {
        SaasEstimate::query()->delete();

        $start = Months::add($from, -self::ESTIMATE_MONTHS);
        $invoices = Invoice::query()
            ->join('recurring_invoices', 'recurring_invoices.id', '=', 'invoices.recurring_invoice_id')
            ->where('invoices.kind', Invoice::INVOICE)
            ->where('invoices.status', '!=', 'DRAFT')
            ->whereNotNull('invoices.recurring_invoice_id')
            ->where('invoices.invoice_date', '<', $from)
            ->where('invoices.invoice_date', '>=', Months::add($start, -12))
            ->orderBy('invoices.invoice_date')
            ->get(['invoices.id', 'invoices.customer_id', 'invoices.invoice_date', 'invoices.net_ore', 'recurring_invoices.interval_months']);

        $estimates = [];

        foreach ($invoices as $invoice) {
            $interval = max(1, (int) $invoice->getAttribute('interval_months'));
            $monthly = Arithmetic::divideHalfUp($invoice->net_ore->ore, $interval);
            $first = Months::of($invoice->invoice_date->toDateString());

            for ($index = 0; $index < $interval; $index++) {
                $month = Months::add($first, $index);

                if ($month < $start || $month >= $from) {
                    continue;
                }

                $estimates[$month][$invoice->customer_id] ??= ['mrr_ore' => 0, 'invoice_ids' => []];
                $estimates[$month][$invoice->customer_id]['mrr_ore'] += $monthly;
                $estimates[$month][$invoice->customer_id]['invoice_ids'][] = $invoice->id;
            }
        }

        $now = now();
        $insert = [];

        foreach ($estimates as $month => $customers) {
            foreach ($customers as $customer => $estimate) {
                $insert[] = [
                    'company_id' => CurrentCompany::id(),
                    'month' => $month,
                    'customer_id' => $customer,
                    'mrr_ore' => $estimate['mrr_ore'],
                    'invoice_ids' => json_encode($estimate['invoice_ids']),
                    'created_at' => $now,
                ];
            }
        }

        foreach (array_chunk($insert, 500) as $chunk) {
            SaasEstimate::query()->insert($chunk);
        }
    }

    /**
     * Contracts that are recorded but cannot be counted and need a person:
     * the latest version per contract without a customer, or left out for a
     * reason that is not merely a note (Källor).
     *
     * @param  list<ContractVersion>  $versions
     */
    private static function sourceIssues(array $versions): int
    {
        $latest = [];

        foreach ($versions as $version) {
            $latest[$version->contractKey()] = $version;
        }

        return count(array_filter($latest, fn (ContractVersion $version): bool => ($version->excluded !== null && ! $version->excluded->isExpected()) || ($version->excluded === null && $version->customerId === null)));
    }
}
