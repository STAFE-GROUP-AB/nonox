<?php

declare(strict_types=1);

namespace App\Modules\Saas\Support;

use App\Models\Customer;
use App\Modules\Saas\Domain\ExclusionReason;
use App\Modules\Saas\Domain\Months;
use App\Modules\Saas\Domain\Movement;
use App\Modules\Saas\Models\SaasContractVersion;
use App\Modules\Saas\Models\SaasCorrection;
use App\Modules\Saas\Models\SaasCustomerMonth;
use App\Modules\Saas\Models\SaasEstimate;
use App\Modules\Saas\Models\SaasMonth;
use Illuminate\Support\Collection;

/**
 * What the pages and the CSV files read: only the stored ledger, with a fixed
 * number of queries whatever the number of customers (ADR-080 §4). The pages
 * and the files share these methods, so a file has the page's columns.
 */
final class SaasReport
{
    /** The bridge columns, in order: key => [label, column on saas_months or null]. */
    public const array CATEGORIES = [
        'rattelse' => 'correction_ore',
        'valutaeffekt' => 'fx_ore',
        'ny' => 'new_ore',
        'ateraktivering' => 'reactivation_ore',
        'aterupptagen' => 'resumed_ore',
        'utokning' => 'expansion_ore',
        'minskning' => 'contraction_ore',
        'paus' => 'pause_ore',
        'churn' => 'churn_ore',
    ];

    /** @return Collection<string, SaasMonth> every ledger month, oldest first, by month */
    public static function months(): Collection
    {
        return SaasMonth::query()->orderBy('month')->get()->keyBy(fn (SaasMonth $month): string => $month->key());
    }

    /**
     * The month asked for ("2026-10"), or the latest.
     *
     * @param  Collection<string, SaasMonth>  $months
     */
    public static function pick(Collection $months, ?string $asked): ?SaasMonth
    {
        if (is_string($asked) && preg_match('/^\d{4}-\d{2}$/', $asked) === 1 && $months->has("{$asked}-01")) {
            return $months->get("{$asked}-01");
        }

        return $months->last();
    }

    public static function label(string $category): string
    {
        return match ($category) {
            'rattelse' => __('Rättelse'),
            'valutaeffekt' => __('Valutaeffekt'),
            'ingaende' => __('Ingående MRR'),
            'utgaende' => __('MRR'),
            default => Movement::fromSlug($category)?->label() ?? $category,
        };
    }

    /**
     * Each closed month "med rättelser" (ADR-080 §4): as reported plus every
     * correction written later that affects it or an earlier month. Open months
     * are already as known now.
     *
     * @param  Collection<string, SaasMonth>  $months
     * @return array<string, int> month => MRR with corrections
     */
    public static function withCorrections(Collection $months): array
    {
        $rows = SaasCorrection::query()
            ->selectRaw('affects_month, booked_month, sum(amount_ore) as amount')
            ->groupBy('affects_month', 'booked_month')
            ->get();
        $adjusted = [];

        foreach ($months as $key => $month) {
            $adjusted[$key] = $month->mrr_ore;

            if (! $month->isClosed()) {
                continue;
            }

            foreach ($rows as $row) {
                if ($row->affects_month->toDateString() <= $key && $row->booked_month->toDateString() > $key) {
                    $adjusted[$key] += (int) $row->getAttribute('amount');
                }
            }
        }

        return $adjusted;
    }

    /** @return array<string, array{mrr_ore: int, customers: int}> month => estimate, for months before the ledger */
    public static function estimates(): array
    {
        return SaasEstimate::query()
            ->selectRaw('month, sum(mrr_ore) as mrr_ore, count(*) as customers')
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->mapWithKeys(fn (SaasEstimate $row): array => [$row->month->toDateString() => ['mrr_ore' => (int) $row->mrr_ore, 'customers' => (int) $row->getAttribute('customers')]])
            ->all();
    }

    /** One customer's amount in a bridge column. */
    public static function amount(SaasCustomerMonth $row, string $category): int
    {
        return match ($category) {
            'rattelse' => $row->correction_ore,
            'valutaeffekt' => $row->fx_ore,
            'ingaende' => $row->opening_ore,
            'utgaende' => $row->mrr_ore,
            default => ($movement = Movement::fromSlug($category)) === null ? 0 : $row->amountIn($movement),
        };
    }

    /**
     * The customers behind one figure of a month: every row whose amount in
     * the column is not zero, largest first. They add up to the month's figure.
     *
     * @return Collection<int, SaasCustomerMonth>
     */
    public static function drill(string $month, string $category): Collection
    {
        return SaasCustomerMonth::query()
            ->with('customer:id,number,name')
            ->where('month', $month)
            ->get()
            ->filter(fn (SaasCustomerMonth $row): bool => self::amount($row, $category) !== 0)
            ->sortByDesc(fn (SaasCustomerMonth $row): int => abs(self::amount($row, $category)))
            ->values();
    }

    /** @return Collection<int, SaasCustomerMonth> the month's customers with MRR or a movement, largest MRR first */
    public static function customers(string $month): Collection
    {
        return SaasCustomerMonth::query()
            ->with('customer:id,number,name')
            ->where('month', $month)
            ->orderByDesc('mrr_ore')
            ->orderBy('customer_id')
            ->get();
    }

    /**
     * Contracts in force at the month-end that end on a later date (Uppsagd
     * MRR, SA-R15): one row per contract.
     *
     * @return list<array{customer: ?Customer, customer_id: int, source_type: string, source_id: string, description: string, mrr_ore: int, ends: string, churn_month: string}>
     */
    public static function scheduled(string $month): array
    {
        $rows = SaasCustomerMonth::query()
            ->with('customer:id,number,name')
            ->where('month', $month)
            ->where('scheduled_churn_ore', '<>', 0)
            ->get();
        $list = [];

        foreach ($rows as $row) {
            $contracts = [];

            foreach ($row->lineMonths() as $line) {
                if ($line->cancelEffectiveOn === null) {
                    continue;
                }

                $key = $line->sourceType->value.':'.$line->sourceId;
                $contracts[$key] ??= [
                    'customer' => $row->customer,
                    'customer_id' => $row->customer_id,
                    'source_type' => $line->sourceType->value,
                    'source_id' => $line->sourceId,
                    'description' => $line->description,
                    'mrr_ore' => 0,
                    'ends' => $line->cancelEffectiveOn,
                    'churn_month' => Months::of($line->cancelEffectiveOn),
                ];
                $contracts[$key]['mrr_ore'] += $line->sekOre;
            }

            array_push($list, ...array_values($contracts));
        }

        usort($list, fn (array $a, array $b): int => [$a['ends'], -$a['mrr_ore']] <=> [$b['ends'], -$b['mrr_ore']]);

        return $list;
    }

    /**
     * The latest version of every contract that is recorded but not counted
     * (Källor), with the reason.
     *
     * @return Collection<int, SaasContractVersion>
     */
    public static function uncounted(): Collection
    {
        $latest = SaasContractVersion::query()
            ->selectRaw('max(id) as id')
            ->groupBy('source_type', 'source_id');

        return SaasContractVersion::query()
            ->with('customer:id,number,name')
            ->whereIn('id', $latest)
            ->where(fn ($query) => $query->whereNotNull('excluded')->orWhereNull('customer_id'))
            ->orderBy('source_type')
            ->orderBy('id')
            ->get()
            ->sortBy(fn (SaasContractVersion $version): int => ($version->excluded?->isExpected() ?? false) ? 1 : 0)
            ->values();
    }

    /** True when the reason needs a person (not merely a note). */
    public static function needsAction(SaasContractVersion $version): bool
    {
        return $version->excluded === null || ! $version->excluded->isExpected() || $version->excluded === ExclusionReason::NoCustomer;
    }
}
