<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Planning\Engine;

use Carbon\CarbonImmutable;

/**
 * The material ready date per production order (ADR-071 §13.3 step 2):
 * free stock, then incoming purchase order rows by expected date, are
 * handed out in the planning order, as the reservations of §5. What is
 * still short with no purchase order gets today plus the supplier's
 * median lead time (or the company's default) and is flagged as an
 * assumed date; so is a purchase order row whose expected date has passed.
 */
final class MaterialPlanner
{
    /**
     * @param  list<int>  $orderIds  in planning order
     * @param  array<int, array<int, int>>  $needs  order id → article id → milli still needed
     * @param  array<int, int>  $stock  article id → milli on hand
     * @param  array<int, list<array{0: ?string, 1: int}>>  $incoming  article id → [expected date or null, milli], any order
     * @param  array<int, int>  $leadDays  article id → days to order and receive what is not on order
     * @return array<int, array{0: ?string, 1: bool}> order id → [ready date (null = now), assumed]
     */
    public static function allocate(array $orderIds, array $needs, array $stock, array $incoming, array $leadDays, string $today, int $defaultLeadDays): array
    {
        foreach ($incoming as $articleId => $rows) {
            usort($rows, fn (array $a, array $b): int => ($a[0] ?? '9999-12-31') <=> ($b[0] ?? '9999-12-31'));
            $incoming[$articleId] = $rows;
        }

        $ready = [];

        foreach ($orderIds as $orderId) {
            $date = null;
            $assumed = false;

            foreach ($needs[$orderId] ?? [] as $articleId => $needed) {
                $fromStock = min($needed, max(0, $stock[$articleId] ?? 0));
                $stock[$articleId] = ($stock[$articleId] ?? 0) - $fromStock;
                $needed -= $fromStock;

                while ($needed > 0 && ($incoming[$articleId] ?? []) !== []) {
                    [$expected, $quantity] = $incoming[$articleId][0];
                    $take = min($needed, $quantity);
                    $needed -= $take;
                    $quantity -= $take;

                    if ($quantity > 0) {
                        $incoming[$articleId][0][1] = $quantity;
                    } else {
                        array_shift($incoming[$articleId]);
                    }

                    if ($expected === null || $expected < $today) {
                        $assumed = true;
                        $expected = $expected === null ? self::addDays($today, $leadDays[$articleId] ?? $defaultLeadDays) : $today;
                    }

                    $date = max($date ?? $expected, $expected);
                }

                if ($needed > 0) {
                    $assumed = true;
                    $expected = self::addDays($today, $leadDays[$articleId] ?? $defaultLeadDays);
                    $date = max($date ?? $expected, $expected);
                }
            }

            $ready[$orderId] = [$date !== null && $date > $today ? $date : null, $assumed];
        }

        return $ready;
    }

    private static function addDays(string $date, int $days): string
    {
        return CarbonImmutable::parse($date, Clock::ZONE)->addDays(max(0, $days))->toDateString();
    }
}
