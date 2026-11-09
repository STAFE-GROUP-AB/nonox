<?php

declare(strict_types=1);

namespace App\Modules\Saas\Support;

use App\Domain\Deadlines\Contracts\DeadlineProvider;
use App\Domain\Deadlines\Deadline;
use App\Domain\Deadlines\DeadlineType;
use App\Models\Company;
use App\Modules\Saas\Domain\Months;
use App\Modules\Saas\Models\SaasMonth;
use App\Modules\Saas\SaasSettings;
use Carbon\CarbonImmutable;

/**
 * The reminder to close a month in Återkommande intäkter (O6): on the 10th of
 * the following month, for every ledger month that is not yet closed. A
 * recommendation, not a legal date: nothing closes by itself, and the
 * figures are management metrics, not bookkeeping (SA-R0). One query, plus
 * the module's settings.
 */
final class SaasDeadlines implements DeadlineProvider
{
    public function deadlines(Company $company, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $historyFrom = SaasSettings::historyFrom($company);

        if ($historyFrom === null) {
            return [];
        }

        $day = SaasSettings::closeReminderDay($company);
        $closed = SaasMonth::query()->where('status', SaasMonth::CLOSED)->pluck('month')
            ->map(fn (CarbonImmutable $month): string => $month->toDateString())
            ->all();
        $deadlines = [];

        foreach (Months::range(Months::add(Months::of($from->toDateString()), -1), Months::of($to->toDateString())) as $month) {
            $date = CarbonImmutable::parse(Months::add($month, 1))->setDay($day);

            if ($month < $historyFrom || in_array($month, $closed, true) || $date->lessThan($from->startOfDay()) || $date->greaterThan($to->endOfDay())) {
                continue;
            }

            $deadlines[] = new Deadline(
                'saas-close:'.$month,
                DeadlineType::Closing,
                __('Stäng :month i Återkommande intäkter', ['month' => Months::label($month)]),
                $date,
                Months::label($month),
                __('Företaget har NONOX SaaS påslaget. När månaden är stängd ändras dess nyckeltal aldrig; det som blir känt senare visas som rättelse i nästa öppna månad. Ingenting stängs av sig självt.'),
                'saas.months',
                null,
                null,
                isRecommendation: true,
                periodStart: CarbonImmutable::parse($month),
            );
        }

        return $deadlines;
    }
}
