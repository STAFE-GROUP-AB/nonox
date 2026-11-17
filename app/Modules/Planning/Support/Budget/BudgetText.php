<?php

declare(strict_types=1);

namespace App\Modules\Planning\Support\Budget;

use App\Modules\Planning\Domain\Budget\BudgetLine;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * The Swedish words for the staff-cost budget (ADR-037 gap 2), shared by the
 * schedule's footer, the toasts, the budget page and the chain view. The
 * cost is always called an estimate.
 */
final class BudgetText
{
    /** @param  array<int, string>  $areaNames  area id => name */
    public static function scope(BudgetLine $line, array $areaNames): string
    {
        return match (true) {
            $line->kind === BudgetLine::AREAS => __('Avdelningar med budget'),
            $line->areaId === null => __('Hela företaget'),
            default => $areaNames[$line->areaId] ?? __('Avdelning'),
        };
    }

    public static function percent(?float $percent): string
    {
        return $percent === null ? '–' : str_replace('.', ',', (string) $percent).' %';
    }

    public static function basisPoints(int $bp): string
    {
        return rtrim(rtrim(number_format($bp / 100, 2, ',', ''), '0'), ',').' %';
    }

    /** What the budget is: "25 000 kr" or "30 % av prognosen (8 400 kr)". */
    public static function budget(BudgetLine $line): string
    {
        if ($line->kind !== BudgetLine::PERCENT) {
            return $line->budgetOre === null ? '–' : Money::ofOre($line->budgetOre)->format();
        }

        $share = self::basisPoints((int) $line->percentBp);

        return $line->budgetOre === null
            ? __(':share av prognosen (ingen prognos)', ['share' => $share])
            : __(':share av prognosen (:amount)', ['share' => $share, 'amount' => Money::ofOre($line->budgetOre)->format()]);
    }

    /**
     * A note for a share of the forecast: a week without any forecast, or
     * planned shifts on days without one, whose cost is left out of the
     * comparison. A day with neither shifts nor a forecast (a day the
     * company is closed) is no gap and gives no note.
     */
    public static function forecastNote(BudgetLine $line): ?string
    {
        if ($line->kind === BudgetLine::PERCENT && $line->forecastDays === 0) {
            return __('Veckan har ingen prognos, så budgeten blir inget belopp. Se Bemanningsbehov.');
        }

        if ($line->uncoveredDays === 0) {
            return null;
        }

        return trans_choice('{1} Passen 1 dag utan prognos (:amount) räknas inte mot budgeten.|[2,*] Passen :count dagar utan prognos (:amount) räknas inte mot budgeten.', $line->uncoveredDays, [
            'count' => $line->uncoveredDays,
            'amount' => Money::ofOre($line->uncoveredOre)->format(),
        ]);
    }

    /**
     * The warning for a line at or over its threshold, else null.
     *
     * @param  array<int, string>  $areaNames
     */
    public static function warning(BudgetLine $line, string $monday, array $areaNames): ?string
    {
        if (! $line->warns()) {
            return null;
        }

        $replace = [
            'week' => (int) CarbonImmutable::parse($monday)->format('W'),
            'scope' => self::scope($line, $areaNames),
            'cost' => Money::ofOre($line->costOre)->format(),
            'budget' => Money::ofOre((int) $line->budgetOre)->format(),
            'percent' => self::percent($line->usedPercent()),
        ];

        return match (true) {
            $line->exceeded() => __('Vecka :week, :scope: den beräknade kostnaden :cost är över budgeten :budget (:percent).', $replace),
            $line->status() === BudgetLine::OVER => __('Vecka :week, :scope: den beräknade kostnaden :cost har nått budgeten :budget.', $replace),
            default => __('Vecka :week, :scope: den beräknade kostnaden :cost är :percent av budgeten :budget.', $replace),
        };
    }
}
