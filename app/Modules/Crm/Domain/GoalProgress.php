<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

use App\Models\SalesDocument;
use App\Models\User;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Deal;
use App\Modules\Crm\Models\Goal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * How far each seller has come towards the month's goals (ADR-043 stage
 * 2), measured from the data, never typed in:
 *
 * - MEETINGS: meetings assigned to the seller and completed in the month.
 * - QUOTES: quotes the seller created in the ledger with a quote date in
 *   the month (sales_documents.created_by records who made each one).
 * - ORDER_VALUE: the value of the deals the seller owns that were won in
 *   the month. Shown next to it, the invoiced value (excluding VAT) of
 *   issued invoices dated in the month that are linked to the seller's won
 *   deals, directly or through the deal's quote or order: only what can be
 *   traced to the seller's deal is counted, each invoice once.
 *
 * Six queries for any number of sellers. Queries for the current company.
 */
final class GoalProgress
{
    public const string ZONE = 'Europe/Stockholm';

    /**
     * @param  ?list<int>  $userIds  null for everyone with a goal
     * @return list<array{user_id: int, name: string, metric: GoalMetric, target: int, actual: int, invoiced: ?int}>
     */
    public function for(CarbonImmutable $month, ?array $userIds = null): array
    {
        $start = $month->setTimezone(self::ZONE)->startOfMonth()->startOfDay();
        $end = $start->endOfMonth();

        $goals = Goal::query()
            ->where('month', $start->toDateString())
            ->when($userIds !== null, fn ($query) => $query->whereIn('user_id', $userIds))
            ->with('user:id,name')
            ->get();

        if ($goals->isEmpty()) {
            return [];
        }

        $users = array_values(array_unique($goals->map(fn (Goal $goal): int => $goal->user_id)->all()));

        $meetings = Activity::query()
            ->where('type', ActivityType::Meeting->value)
            ->whereBetween('done_at', [$start, $end])
            ->whereIn('assigned_to', $users)
            ->groupBy('assigned_to')
            ->toBase()
            ->selectRaw('assigned_to as user_id, COUNT(*) as total')
            ->pluck('total', 'user_id');

        $quotes = SalesDocument::query()
            ->where('kind', SalesDocument::QUOTE)
            ->whereBetween('document_date', [$start->toDateString(), $end->toDateString()])
            ->whereIn('created_by', $users)
            ->groupBy('created_by')
            ->toBase()
            ->selectRaw('created_by as user_id, COUNT(*) as total')
            ->pluck('total', 'user_id');

        $won = Deal::query()
            ->where('status', DealStatus::Won->value)
            ->whereBetween('won_at', [$start, $end])
            ->whereIn('owner_id', $users)
            ->groupBy('owner_id')
            ->toBase()
            ->selectRaw('owner_id as user_id, SUM(value_ore) as total')
            ->pluck('total', 'user_id');

        $invoiced = $this->invoiced($start, $end, $users);

        return array_values($goals
            ->sortBy(fn (Goal $goal): string => ($goal->user->name ?? '').'|'.$goal->metric->value)
            ->map(fn (Goal $goal): array => [
                'user_id' => $goal->user_id,
                'name' => $goal->user instanceof User ? $goal->user->name : '',
                'metric' => $goal->metric,
                'target' => $goal->target,
                'actual' => (int) (match ($goal->metric) {
                    GoalMetric::Meetings => $meetings[$goal->user_id] ?? 0,
                    GoalMetric::Quotes => $quotes[$goal->user_id] ?? 0,
                    GoalMetric::OrderValue => $won[$goal->user_id] ?? 0,
                }),
                'invoiced' => $goal->metric === GoalMetric::OrderValue ? (int) ($invoiced[$goal->user_id] ?? 0) : null,
            ])
            ->all());
    }

    /**
     * Net invoiced on issued invoices dated in the month that are linked to
     * a won deal of the seller (directly, or as the invoice of the deal's
     * order or quote), each invoice once per seller.
     *
     * @param  list<int>  $users
     * @return array<int, int> by user id
     */
    private function invoiced(CarbonImmutable $start, CarbonImmutable $end, array $users): array
    {
        $linked = Deal::query()
            ->leftJoin('sales_documents as orders', 'orders.id', '=', 'crm_deals.order_id')
            ->leftJoin('sales_documents as quotes', 'quotes.id', '=', 'crm_deals.quote_id')
            ->join('invoices', fn ($join) => $join
                ->on('invoices.id', '=', DB::raw('COALESCE(crm_deals.invoice_id, orders.invoice_id, quotes.invoice_id)'))
                ->on('invoices.company_id', '=', 'crm_deals.company_id'))
            ->where('crm_deals.status', DealStatus::Won->value)
            ->whereIn('crm_deals.owner_id', $users)
            ->whereNotNull('invoices.issued_at')
            ->whereBetween('invoices.invoice_date', [$start->toDateString(), $end->toDateString()])
            ->toBase()
            ->distinct()
            ->select(['crm_deals.owner_id', 'invoices.id', 'invoices.net_ore']);

        return DB::query()
            ->fromSub($linked, 'linked')
            ->groupBy('owner_id')
            ->selectRaw('owner_id, SUM(net_ore) as total')
            ->pluck('total', 'owner_id')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();
    }
}
