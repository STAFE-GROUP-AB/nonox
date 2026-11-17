<?php

declare(strict_types=1);

namespace App\Modules\Planning\Support\Demand;

use App\Modules\Planning\Domain\Demand\DayDemand;
use App\Modules\Planning\Domain\Demand\Forecast;
use App\Modules\Planning\Domain\Demand\HourShape;
use App\Modules\Planning\Domain\WorkingTimeChecks;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * The Swedish sentences that explain a forecast and a day's demand, the
 * same on the schedule, Bemanning och kostnad and Bemanningsbehov.
 */
final class DemandText
{
    public static function forecast(Forecast $forecast): string
    {
        $dates = implode(', ', array_map(fn (string $date): string => CarbonImmutable::parse($date)->format('j/n'), $forecast->basis));

        return match ($forecast->kind) {
            Forecast::AVERAGE => trans_choice('{1} Försäljningen samma veckodag :dates.|[2,*] Snitt av samma veckodag :count veckor: :dates.', count($forecast->basis), ['count' => count($forecast->basis), 'dates' => $dates]),
            Forecast::HOLIDAY => __(':holiday förra året (:date).', ['holiday' => $forecast->holiday, 'date' => $dates]),
            Forecast::OVERRIDE => __('Ändrad: :reason. Prognosen var :amount.', ['reason' => $forecast->reason, 'amount' => $forecast->computedOre === null ? __('ingen') : Money::ofOre($forecast->computedOre)->format()]),
            default => $forecast->holiday !== null
                ? __('Ingen prognos: :holiday förra året finns inte i bokföringen.', ['holiday' => $forecast->holiday])
                : __('Ingen prognos: samma veckodag de senaste veckorna har ingen försäljning i bokföringen.'),
        };
    }

    public static function shape(?string $shape): string
    {
        return match ($shape) {
            HourShape::TILL => __('Fördelning per timme från kassan'),
            HourShape::PROFILE => __('Fördelning per timme: egen uppskattning'),
            default => __('Bara per dag: ingen kassa eller timprofil'),
        };
    }

    /** "Behov 24 tim · bemannat 22 tim" or null without a target. */
    public static function hours(DayDemand $day): ?string
    {
        if ($day->needMinutes === null) {
            return null;
        }

        return __('Behov :need · bemannat :planned', [
            'need' => WorkingTimeChecks::hours($day->needMinutes),
            'planned' => WorkingTimeChecks::hours($day->plannedMinutes),
        ]);
    }

    /** The warning of a day, or null when it is staffed as needed. */
    public static function warning(DayDemand $day): ?string
    {
        $under = DayDemand::ranges($day->underHours());

        if ($day->status === DayDemand::UNDER) {
            return $under !== []
                ? __('Underbemannat kl. :hours.', ['hours' => implode(', ', $under)])
                : __('Underbemannat: färre timmar planerade än behovet.');
        }

        if ($day->status === DayDemand::OVER) {
            $over = DayDemand::ranges($day->overHours());

            return $over !== []
                ? __('Överbemannat kl. :hours.', ['hours' => implode(', ', $over)])
                : __('Överbemannat: minst en timme mer planerad än behovet.');
        }

        return null;
    }
}
