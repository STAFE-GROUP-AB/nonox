<?php

declare(strict_types=1);

namespace App\Domain\Budget;

/**
 * A budget, or a forecast made from a cut-off month (ADR-081 gap 2; forecasts
 * are built in E5).
 */
enum BudgetKind: string
{
    case Budget = 'BUDGET';
    case Forecast = 'FORECAST';

    public function label(): string
    {
        return match ($this) {
            self::Budget => __('Budget'),
            self::Forecast => __('Prognos'),
        };
    }
}
