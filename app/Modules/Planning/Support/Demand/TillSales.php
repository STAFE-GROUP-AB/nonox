<?php

declare(strict_types=1);

namespace App\Modules\Planning\Support\Demand;

use App\Modules\Planning\Domain\Demand\DayBuckets;
use App\Modules\Planning\Support\ShiftQuery;
use App\Modules\Pos\Models\PosSale;
use Carbon\CarbonImmutable;

/**
 * The tills' receipts per local hour (ADR-037 gap 1, ADR-042): net of VAT
 * (total_ore − vat_ore; the total never holds tips, which the tills report
 * apart), refunds negative as recorded, grouped by the hour of sold_at in
 * Europe/Stockholm, so the autumn day's two 02 hours land in one bucket.
 * Only the shape of the day is taken from here. One grouped query, current
 * company.
 */
final class TillSales
{
    /**
     * @param  list<int>  $connectionIds
     * @param  list<string>  $alsoDates  Y-m-d outside the period
     * @return array<string, array<int, int>> Y-m-d => local hour => öre
     */
    public static function perHour(array $connectionIds, CarbonImmutable $from, CarbonImmutable $to, array $alsoDates = []): array
    {
        $result = [];

        foreach (self::perHourByConnection($connectionIds, $from, $to, $alsoDates) as $days) {
            foreach ($days as $day => $hours) {
                foreach ($hours as $hour => $net) {
                    $result[$day][$hour] = ($result[$day][$hour] ?? 0) + $net;
                }
            }
        }

        return $result;
    }

    /**
     * The same, per till, in one grouped query: for several scopes with
     * different tills at once (the schedule proposal, gap 3).
     *
     * @param  list<int>  $connectionIds
     * @param  list<string>  $alsoDates  Y-m-d outside the period
     * @return array<int, array<string, array<int, int>>> till id => Y-m-d => local hour => öre
     */
    public static function perHourByConnection(array $connectionIds, CarbonImmutable $from, CarbonImmutable $to, array $alsoDates = []): array
    {
        if ($connectionIds === []) {
            return [];
        }

        $zone = DayBuckets::ZONE;
        $local = "(pos_sales.sold_at AT TIME ZONE '{$zone}')";
        $ranges = [[$from, $to], ...array_map(fn (string $date): array => [CarbonImmutable::parse($date, $zone), CarbonImmutable::parse($date, $zone)], $alsoDates)];

        $rows = PosSale::query()
            ->whereIn('pos_connection_id', $connectionIds)
            ->where(function ($query) use ($ranges): void {
                foreach ($ranges as [$first, $last]) {
                    $query->orWhere(fn ($range) => $range
                        ->where('sold_at', '>=', ShiftQuery::instant($first->setTimezone(DayBuckets::ZONE)->startOfDay()))
                        ->where('sold_at', '<', ShiftQuery::instant($last->setTimezone(DayBuckets::ZONE)->addDay()->startOfDay())));
                }
            })
            ->groupByRaw("pos_sales.pos_connection_id, {$local}::date, EXTRACT(HOUR FROM {$local})")
            ->selectRaw("pos_sales.pos_connection_id AS till, {$local}::date::text AS day, EXTRACT(HOUR FROM {$local})::int AS hour, SUM(pos_sales.total_ore - pos_sales.vat_ore) AS net")
            ->toBase()
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $result[(int) $row->till][substr((string) $row->day, 0, 10)][(int) $row->hour] = (int) $row->net;
        }

        return $result;
    }
}
