<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Deal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The figures of the sales work (ADR-043 stage 2), each in one query
 * whatever the number of deals: per stage on the board, and for the
 * dashboard the open pipeline, the win rate, the sales cycle, the
 * activities per week and the deals without a next step. Money in öre.
 * Queries for the current company.
 */
final class PipelineFigures
{
    public const string ZONE = 'Europe/Stockholm';

    /**
     * Count, value and weighted value of the open deals per stage.
     *
     * @return array<int, array{count: int, value: int, weighted: int}> by stage id
     */
    public function stages(int $pipelineId, ?int $ownerId = null): array
    {
        return Deal::query()
            ->join('crm_pipeline_stages', 'crm_pipeline_stages.id', '=', 'crm_deals.stage_id')
            ->where('crm_deals.pipeline_id', $pipelineId)
            ->where('crm_deals.status', DealStatus::Open->value)
            ->when($ownerId !== null, fn ($query) => $query->where('crm_deals.owner_id', $ownerId))
            ->groupBy('crm_deals.stage_id')
            ->get([
                'crm_deals.stage_id',
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(crm_deals.value_ore) as value'),
                DB::raw('SUM(crm_deals.value_ore * crm_pipeline_stages.probability) as product'),
            ])
            ->mapWithKeys(fn (Deal $row): array => [(int) $row->stage_id => [
                'count' => (int) $row->getAttribute('count'),
                'value' => (int) $row->getAttribute('value'),
                'weighted' => Forecast::percentOf((int) $row->getAttribute('product')),
            ]])
            ->all();
    }

    /** @return array{count: int, value: int, weighted: int} */
    public function open(?int $ownerId = null): array
    {
        $row = Deal::query()
            ->join('crm_pipeline_stages', 'crm_pipeline_stages.id', '=', 'crm_deals.stage_id')
            ->where('crm_deals.status', DealStatus::Open->value)
            ->when($ownerId !== null, fn ($query) => $query->where('crm_deals.owner_id', $ownerId))
            ->toBase()
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(crm_deals.value_ore), 0) as value, COALESCE(SUM(crm_deals.value_ore * crm_pipeline_stages.probability), 0) as product')
            ->first();

        return [
            'count' => (int) ($row->count ?? 0),
            'value' => (int) ($row->value ?? 0),
            'weighted' => Forecast::percentOf((int) ($row->product ?? 0)),
        ];
    }

    /**
     * Won and lost deals closed in the period, and the share won: won /
     * (won + lost). Null rate when nothing was closed.
     *
     * @return array{won: int, lost: int, won_value: int, rate: ?float}
     */
    public function winRate(CarbonImmutable $from, CarbonImmutable $to, ?int $ownerId = null): array
    {
        $row = Deal::query()
            ->where(fn ($query) => $query
                ->where(fn ($won) => $won->where('status', DealStatus::Won->value)->whereBetween('won_at', [$from, $to]))
                ->orWhere(fn ($lost) => $lost->where('status', DealStatus::Lost->value)->whereBetween('lost_at', [$from, $to])))
            ->when($ownerId !== null, fn ($query) => $query->where('owner_id', $ownerId))
            ->toBase()
            ->selectRaw("COUNT(*) FILTER (WHERE status = 'WON') as won, COUNT(*) FILTER (WHERE status = 'LOST') as lost, COALESCE(SUM(value_ore) FILTER (WHERE status = 'WON'), 0) as won_value")
            ->first();

        $won = (int) ($row->won ?? 0);
        $lost = (int) ($row->lost ?? 0);

        return ['won' => $won, 'lost' => $lost, 'won_value' => (int) ($row->won_value ?? 0), 'rate' => $won + $lost === 0 ? null : $won / ($won + $lost)];
    }

    /** The average days from created to won, for deals won in the period (SalesCycle). */
    public function cycleDays(CarbonImmutable $from, CarbonImmutable $to, ?int $ownerId = null): ?float
    {
        $deals = Deal::query()
            ->where('status', DealStatus::Won->value)
            ->whereBetween('won_at', [$from, $to])
            ->when($ownerId !== null, fn ($query) => $query->where('owner_id', $ownerId))
            ->toBase()
            ->get(['created_at', 'won_at']);

        return SalesCycle::averageDays($deals->map(fn (object $row): array => [
            CarbonImmutable::parse((string) $row->created_at),
            CarbonImmutable::parse((string) $row->won_at),
        ]));
    }

    /**
     * Completed activities per week, the last $weeks weeks up to and
     * including this one, oldest first; notes count too: they are logged
     * work. Weeks start on Monday, in Swedish time.
     *
     * @return list<array{week: string, start: string, count: int}> week as ISO "2026-W24"
     */
    public function activitiesPerWeek(CarbonImmutable $today, int $weeks = 8, ?int $userId = null): array
    {
        $monday = $today->setTimezone(self::ZONE)->startOfWeek(CarbonImmutable::MONDAY);
        $first = $monday->subWeeks($weeks - 1);

        $counts = Activity::query()
            ->whereNotNull('done_at')
            ->where('done_at', '>=', $first)
            ->when($userId !== null, fn ($query) => $query->where('assigned_to', $userId))
            ->toBase()
            ->selectRaw("to_char(date_trunc('week', done_at AT TIME ZONE 'Europe/Stockholm'), 'YYYY-MM-DD') as start, COUNT(*) as count")
            ->groupByRaw("date_trunc('week', done_at AT TIME ZONE 'Europe/Stockholm')")
            ->pluck('count', 'start');

        $result = [];

        for ($week = $first; $week->lessThanOrEqualTo($monday); $week = $week->addWeek()) {
            $result[] = ['week' => $week->format('o-\WW'), 'start' => $week->toDateString(), 'count' => (int) ($counts[$week->toDateString()] ?? 0)];
        }

        return $result;
    }

    public function missingNextStep(?int $ownerId = null): int
    {
        return DealNextStep::missing()
            ->when($ownerId !== null, fn ($query) => $query->where('owner_id', $ownerId))
            ->count();
    }
}
