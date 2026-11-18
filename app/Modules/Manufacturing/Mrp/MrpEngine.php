<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Mrp;

use App\Modules\Manufacturing\Production\ProductionMath;
use Carbon\CarbonImmutable;

/**
 * The MRP engine (ADR-081 gap 5, owner decisions 13 and 14). Pure: it reads
 * an MrpInput and returns an MrpPlan, with no database and no clock of its
 * own, so the same input always gives the same plan.
 *
 * Weekly buckets from the Monday of the current week, 13 by default and at
 * most 52. Anything dated before the first Monday falls in the first week;
 * anything after the horizon is left out. Per article and week:
 *
 *     independent = max(forecast, customer orders)            (decision 14)
 *     gross       = independent + components of open production orders
 *                   + components of the parents' planned orders
 *     receipts    = open purchase order rows + open production orders
 *
 * The projected stock starts at what is on hand. When a week falls short of
 * its demand or of the safety stock, the part needed of an existing receipt
 * expected later is moved forward first (advice "flytta fram", with the
 * order's full quantity so a partial move reads as splitting the order); the
 * rest of that receipt stays on its date. When the projected stock would
 * still fall below the safety stock, a planned order covers the difference,
 * made up to the minimum lot and rounded up to the lot multiple. It is due on the week's Monday (today
 * in the first week) and released the lead time before; a release date that
 * has passed is set to today and marked "försenad", one within seven days is
 * marked "släpp nu". A receipt that existing stock and orders alone do not
 * need through the horizon, in full or in part, gets the advice "överskott";
 * this run's planned orders are left out of that test, so no receipt is
 * called excess while a planned order is proposed for the same need.
 *
 * Articles are planned level by level (low-level code): a made article's
 * planned orders become demand for its components on the release date,
 * through the bill of materials valid today, before the components are
 * planned. Only articles planned by MRP are planned. Capacity is not
 * considered (the production planner of ADR-071 §13 schedules the orders a
 * person creates).
 *
 * The pegging of a planned order lists the requirements it covers, oldest
 * first, as the supply is used up in date order; what is left over goes to
 * the safety stock, and what remains after that is lot rounding.
 */
final class MrpEngine
{
    public const string VERSION = '1';

    /** The most requirements one planned order lists in its pegging. */
    public const int MAX_PEGS = 50;

    private const array KIND_ORDER = [
        DemandKind::CustomerOrder->value => 0,
        DemandKind::ProductionOrder->value => 1,
        DemandKind::PlannedParent->value => 2,
        DemandKind::Forecast->value => 3,
        DemandKind::SafetyStock->value => 4,
    ];

    public function plan(MrpInput $input): MrpPlan
    {
        $levels = $this->levels($input->articles);
        $ids = array_keys($input->articles);
        usort($ids, fn (int $a, int $b): int => [$levels[$a], $a] <=> [$levels[$b], $b]);

        $demands = [];

        foreach ($input->demands as $demand) {
            if (isset($input->articles[$demand->articleId])) {
                $demands[$demand->articleId][] = $demand;
            }
        }

        $receipts = [];

        foreach ($input->receipts as $receipt) {
            if (isset($input->articles[$receipt->articleId])) {
                $receipts[$receipt->articleId][] = $receipt;
            }
        }

        $planned = [];
        $messages = [];

        foreach ($ids as $id) {
            [$orders, $advice, $dependent] = $this->planArticle($input, $input->articles[$id], $demands[$id] ?? [], $receipts[$id] ?? []);
            array_push($planned, ...$orders);
            array_push($messages, ...$advice);

            foreach ($dependent as $demand) {
                $demands[$demand->articleId][] = $demand;
            }
        }

        return new MrpPlan($planned, $messages);
    }

    /**
     * @param  list<MrpDemand>  $demands
     * @param  list<MrpReceipt>  $receipts
     * @return array{0: list<PlannedOrderProposal>, 1: list<array{kind: string, article_id: int, source: string, id: int, number: int, on: string, to: ?string, quantity_milli: int, order_milli: int}>, 2: list<MrpDemand>}
     */
    private function planArticle(MrpInput $input, MrpArticle $article, array $demands, array $receipts): array
    {
        $weeks = $input->weeks;
        $byWeek = array_fill(0, $weeks, []);
        $ordered = array_fill(0, $weeks, 0);

        foreach ($demands as $demand) {
            $week = $this->week($input, $demand->date);

            if ($week === null || $demand->quantityMilli <= 0) {
                continue;
            }

            $byWeek[$week][] = $demand;

            if ($demand->kind === DemandKind::CustomerOrder) {
                $ordered[$week] += $demand->quantityMilli;
            }
        }

        // Forecast consumption (owner decision 14): the forecast adds only what it expects above the orders of its week.
        foreach ($input->forecast[$article->id] ?? [] as $monday => $milli) {
            $week = $this->week($input, (string) $monday);

            if ($week === null) {
                continue;
            }

            $above = $milli - $ordered[$week];

            if ($above > 0) {
                $byWeek[$week][] = new MrpDemand($article->id, DemandKind::Forecast, (string) $monday, $above);
            }
        }

        foreach ($byWeek as $week => $items) {
            usort($items, fn (MrpDemand $a, MrpDemand $b): int => [self::KIND_ORDER[$a->kind->value], $a->date, (string) $a->sourceId, (int) $a->parentArticleId] <=> [self::KIND_ORDER[$b->kind->value], $b->date, (string) $b->sourceId, (int) $b->parentArticleId]);
            $byWeek[$week] = $items;
        }

        $supply = [];

        foreach ($receipts as $receipt) {
            $week = $this->week($input, $receipt->date);

            if ($week !== null && $receipt->quantityMilli > 0) {
                // remaining: what is still expected on its own date, after any part moved forward.
                $supply[] = ['receipt' => $receipt, 'week' => $week, 'remaining' => $receipt->quantityMilli];
            }
        }

        usort($supply, fn (array $a, array $b): int => [$a['receipt']->date, $a['receipt']->source->value, $a['receipt']->sourceId] <=> [$b['receipt']->date, $b['receipt']->source->value, $b['receipt']->sourceId]);

        $safety = max(0, $article->safetyStockMilli);
        $pool = max(0, $article->onHandMilli);
        /** @var list<array{0: MrpDemand, 1: int}> $queue */
        $queue = [];
        // The projected stock without this run's planned orders: what existing stock and orders alone give.
        $existing = [];
        $plannedSoFar = 0;
        $orders = [];
        $messages = [];
        $dependent = [];

        for ($week = 0; $week < $weeks; $week++) {
            foreach ($supply as $receipt) {
                if ($receipt['week'] === $week) {
                    $pool += $receipt['remaining'];
                }
            }

            foreach ($byWeek[$week] as $demand) {
                $queue[] = [$demand, $demand->quantityMilli];
            }

            $this->allocate($pool, $queue);

            // Short of the demand or of the safety stock: move the part of a later receipt that is needed forward
            // before planning anything new. The rest of that receipt stays on its date (and may be excess).
            foreach ($supply as $index => $receipt) {
                $shortage = $safety - ($pool - $this->unmet($queue));

                if ($shortage <= 0) {
                    break;
                }

                if ($receipt['week'] <= $week || $receipt['remaining'] <= 0) {
                    continue;
                }

                $take = min($receipt['remaining'], $shortage);
                $supply[$index]['remaining'] -= $take;
                $pool += $take;
                $messages[] = $this->message(SupplyMessageKind::MoveIn, $receipt['receipt'], $this->dueDate($input, $week)->toDateString(), $take);
                $this->allocate($pool, $queue);
            }

            $shortage = $safety - ($pool - $this->unmet($queue));

            if ($shortage > 0) {
                $quantity = $article->lotSize($shortage);
                [$order, $components] = $this->plannedOrder($input, $article, $week, $quantity, $pool, $queue, $safety);
                $orders[] = $order;
                $plannedSoFar += $quantity;
                array_push($dependent, ...$components);
            }

            $existing[$week] = $pool - $this->unmet($queue) - $plannedSoFar;
        }

        // Excess: the part of a receipt, latest first, that existing stock and orders alone do not need to stay at the
        // safety stock through the horizon. This run's planned orders are left out, so a receipt is never called
        // excess because a planned order would cover the same need.
        foreach (array_reverse($supply) as $receipt) {
            if ($receipt['remaining'] <= 0) {
                continue;
            }

            $room = PHP_INT_MAX;

            for ($week = $receipt['week']; $week < $weeks; $week++) {
                $room = min($room, $existing[$week] - $safety);
            }

            $excess = min($receipt['remaining'], $room);

            if ($excess <= 0) {
                continue;
            }

            for ($week = $receipt['week']; $week < $weeks; $week++) {
                $existing[$week] -= $excess;
            }

            $messages[] = $this->message(SupplyMessageKind::Excess, $receipt['receipt'], null, $excess);
        }

        return [$orders, $messages, $dependent];
    }

    /**
     * Writes one planned order: it covers the waiting requirements first,
     * then the safety stock; the rest is lot rounding and stays as free stock.
     *
     * @param  list<array{0: MrpDemand, 1: int}>  $queue
     * @return array{0: PlannedOrderProposal, 1: list<MrpDemand>}
     */
    private function plannedOrder(MrpInput $input, MrpArticle $article, int $week, int $quantity, int &$pool, array &$queue, int $safety): array
    {
        $due = $this->dueDate($input, $week);
        $pegs = [];
        $left = $quantity;

        while ($queue !== [] && $left > 0) {
            [$demand, $remaining] = $queue[0];
            $take = min($left, $remaining);

            if (count($pegs) < self::MAX_PEGS) {
                $pegs[] = $demand->peg($take);
            }

            $left -= $take;

            if ($take === $remaining) {
                array_shift($queue);
            } else {
                $queue[0][1] = $remaining - $take;
            }
        }

        $forSafety = min($left, max(0, $safety - $pool));

        if ($forSafety > 0 && count($pegs) < self::MAX_PEGS) {
            $pegs[] = (new MrpDemand($article->id, DemandKind::SafetyStock, $due->toDateString(), $forSafety))->peg($forSafety);
        }

        $pool += $left;

        $release = $due->subDays($article->leadTimeDays);
        $exception = null;

        if ($release->lessThan($input->today)) {
            $exception = PlannedOrderException::Late;
            $release = $input->today;
        } elseif ($release->lessThan($input->today->addDays(7))) {
            $exception = PlannedOrderException::ReleaseNow;
        }

        $components = [];

        if ($article->kind === PlannedOrderKind::Production && $article->bom !== null) {
            foreach ($article->bom['rows'] as $row) {
                if (! isset($input->articles[$row['component_article_id']]) || $row['component_article_id'] === $article->id) {
                    continue;
                }

                $components[] = new MrpDemand(
                    $row['component_article_id'],
                    DemandKind::PlannedParent,
                    $release->toDateString(),
                    ProductionMath::required($row['quantity_milli'], $row['loss_bp'], $quantity, $article->bom['yield_milli']),
                    parentArticleId: $article->id,
                );
            }
        }

        return [new PlannedOrderProposal(
            articleId: $article->id,
            kind: $article->kind,
            quantityMilli: $quantity,
            releaseOn: $release->toDateString(),
            dueOn: $due->toDateString(),
            supplierId: $article->kind === PlannedOrderKind::Purchase ? $article->supplierId : null,
            pegging: $pegs,
            exception: $exception,
        ), $components];
    }

    /**
     * Uses the free supply on the waiting requirements, oldest first.
     *
     * @param  list<array{0: MrpDemand, 1: int}>  $queue
     */
    private function allocate(int &$pool, array &$queue): void
    {
        while ($pool > 0 && $queue !== []) {
            $take = min($pool, $queue[0][1]);
            $pool -= $take;

            if ($take === $queue[0][1]) {
                array_shift($queue);
            } else {
                $queue[0][1] -= $take;
            }
        }
    }

    /** @param  list<array{0: MrpDemand, 1: int}>  $queue */
    private function unmet(array $queue): int
    {
        return array_sum(array_map(fn (array $item): int => $item[1], $queue));
    }

    /** @return array{kind: string, article_id: int, source: string, id: int, number: int, on: string, to: ?string, quantity_milli: int, order_milli: int} */
    private function message(SupplyMessageKind $kind, MrpReceipt $receipt, ?string $to, int $quantity): array
    {
        return [
            'kind' => $kind->value,
            'article_id' => $receipt->articleId,
            'source' => $receipt->source->value,
            'id' => $receipt->sourceId,
            'number' => $receipt->number,
            'on' => $receipt->date,
            'to' => $to,
            'quantity_milli' => $quantity,
            'order_milli' => $receipt->quantityMilli,
        ];
    }

    /** The week a date falls in: the first week for anything earlier, null after the horizon. */
    private function week(MrpInput $input, string $date): ?int
    {
        $day = CarbonImmutable::parse($date)->startOfDay();

        if ($day->lessThan($input->firstMonday)) {
            return 0;
        }

        $week = intdiv((int) $input->firstMonday->diffInDays($day), 7);

        return $week < $input->weeks ? $week : null;
    }

    /** When a week's requirement is due: its Monday, or today in the first week. */
    private function dueDate(MrpInput $input, int $week): CarbonImmutable
    {
        if ($week === 0) {
            return $input->today->greaterThan($input->firstMonday) ? $input->today : $input->firstMonday;
        }

        return $input->firstMonday->addWeeks($week);
    }

    /**
     * The low-level code of every article: 0 for an article no planned
     * article is made from, else one more than its deepest parent.
     *
     * @param  array<int, MrpArticle>  $articles
     * @return array<int, int>
     */
    private function levels(array $articles): array
    {
        $levels = array_fill_keys(array_keys($articles), 0);

        for ($pass = 0; $pass <= count($articles); $pass++) {
            $changed = false;

            foreach ($articles as $article) {
                if ($article->kind !== PlannedOrderKind::Production || $article->bom === null) {
                    continue;
                }

                foreach ($article->bom['rows'] as $row) {
                    $component = $row['component_article_id'];

                    if ($component === $article->id || ! isset($levels[$component])) {
                        continue;
                    }

                    if ($levels[$component] < $levels[$article->id] + 1) {
                        $levels[$component] = $levels[$article->id] + 1;
                        $changed = true;
                    }
                }
            }

            if (! $changed) {
                return $levels;
            }
        }

        throw new MrpStructureLoop(array_keys(array_filter($levels, fn (int $level): bool => $level >= count($articles))));
    }
}
