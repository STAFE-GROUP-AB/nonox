<?php

declare(strict_types=1);

namespace App\Modules\Planning\Domain\Proposal;

use App\Modules\Planning\Domain\Budget\BudgetLine;
use App\Modules\Planning\Domain\Budget\BudgetWeek;
use Carbon\CarbonImmutable;

/**
 * Keeps the new shifts of a proposal within the staff-cost budget (v2,
 * ADR-037 amendment gaps 2 and 3, P8). Pure and deterministic.
 *
 * Who will work a new shift is not known when it is made, and the cost of a
 * person must never decide who gets it (diskrimineringslagen, see
 * ProposalEngine). So a new shift is costed the same whoever takes it: its
 * worked hours times the scope's average estimated cost per worked hour of
 * the shifts already planned (the area's, else the whole company's), one
 * figure the caller works out from the shifts' own estimates. The engine
 * that chooses people never sees it.
 *
 * Every budget line a shift counts against (the week's total and its area,
 * BudgetLine::counts) must have room for it: the budget less the estimated
 * cost already planned, less the open shifts the proposal fills, costed the
 * same way. Shifts are taken by how many under-staffed hours they cover,
 * most first, then in time order; one without room is not made and is
 * named "Ryms inte i budgeten". The result is in time order. Without a budget there is no limit;
 * without an average (nothing costed yet) the budget cannot be checked and
 * the page says so. The figure stays an estimate, as in the budget itself.
 */
final class BudgetFit
{
    /**
     * @param  list<DemandBlock>  $blocks
     * @param  array<string, BudgetWeek>  $weeks  Monday (Y-m-d) => week
     * @param  array<int, int>  $areaRates  area id => öre per worked hour
     * @param  ?int  $companyRate  öre per worked hour for the whole company
     * @param  list<array{area: ?int, date: string, minutes: int}>  $reserved  the open shifts the proposal fills
     * @return array{kept: list<DemandBlock>, over: list<DemandBlock>, unchecked: bool, estimates: array<string, int>}
     */
    public static function apply(array $blocks, array $weeks, array $areaRates, ?int $companyRate, array $reserved = []): array
    {
        $room = [];
        $lines = [];

        foreach ($weeks as $monday => $week) {
            foreach ($week->lines() as $index => $line) {
                if ($line->budgetOre === null) {
                    continue;
                }

                $lines[$monday]["{$monday}#{$index}"] = $line;
                $room["{$monday}#{$index}"] = $line->budgetOre - $line->costOre;
            }
        }

        $rate = fn (?int $area): ?int => $area !== null && isset($areaRates[$area]) ? $areaRates[$area] : $companyRate;
        $cost = fn (?int $area, int $minutes): ?int => ($perHour = $rate($area)) === null ? null : intdiv($perHour * $minutes + 30, 60);
        $unchecked = false;

        foreach ($reserved as $open) {
            $monday = self::monday($open['date']);
            $estimate = $cost($open['area'], $open['minutes']);

            foreach (self::counting($lines[$monday] ?? [], $open['area'], $open['date']) as $key) {
                if ($estimate === null) {
                    $unchecked = true;

                    continue;
                }

                $room[$key] -= $estimate;
            }
        }

        $kept = [];
        $over = [];
        $estimates = [];

        // The shifts that cover the most hours with too few people first, then in time order, so a late day in the week is not left out only for being late.
        $order = $blocks;
        usort($order, fn (DemandBlock $a, DemandBlock $b): int => [-$a->coveredHours, $a->start->getTimestamp(), $a->areaId ?? 0] <=> [-$b->coveredHours, $b->start->getTimestamp(), $b->areaId ?? 0]);

        foreach ($order as $block) {
            $monday = $block->monday();
            $keys = self::counting($lines[$monday] ?? [], $block->areaId, $block->date);
            $estimate = $cost($block->areaId, $block->workedMinutes());

            if ($keys !== [] && $estimate === null) {
                $unchecked = true;
            }

            if ($estimate !== null && array_filter($keys, fn (string $key): bool => $room[$key] < $estimate) !== []) {
                $over[] = $block;

                continue;
            }

            foreach ($keys as $key) {
                $room[$key] -= (int) $estimate;
            }

            $kept[] = $block;

            if ($estimate !== null) {
                $estimates[$monday] = ($estimates[$monday] ?? 0) + $estimate;
            }
        }

        $time = fn (DemandBlock $a, DemandBlock $b): int => [$a->start->getTimestamp(), $a->areaId ?? 0, $a->end->getTimestamp()] <=> [$b->start->getTimestamp(), $b->areaId ?? 0, $b->end->getTimestamp()];
        usort($kept, $time);
        usort($over, $time);
        ksort($estimates);

        return ['kept' => $kept, 'over' => $over, 'unchecked' => $unchecked, 'estimates' => $estimates];
    }

    /**
     * The keys of the lines a shift in the area on the day counts against.
     *
     * @param  array<string, BudgetLine>  $lines
     * @return list<string>
     */
    private static function counting(array $lines, ?int $areaId, string $date): array
    {
        return array_map('strval', array_keys(array_filter($lines, fn (BudgetLine $line): bool => $line->counts($areaId, $date))));
    }

    private static function monday(string $date): string
    {
        return CarbonImmutable::parse($date, UnsocialTime::ZONE)->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
    }
}
