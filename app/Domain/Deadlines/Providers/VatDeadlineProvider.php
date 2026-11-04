<?php

declare(strict_types=1);

namespace App\Domain\Deadlines\Providers;

use App\Domain\Company\LegalForm;
use App\Domain\Company\VatPeriod;
use App\Domain\Deadlines\Contracts\DeadlineProvider;
use App\Domain\Deadlines\Deadline;
use App\Domain\Deadlines\DeadlineType;
use App\Domain\Deadlines\FiscalCalendar;
use App\Domain\Vat\EuTrade;
use App\Domain\Vat\VatDeadlines;
use App\Domain\Vat\VatPeriods;
use App\Models\Company;
use Carbon\CarbonImmutable;

/**
 * The VAT return of every period, as VatDeadlines dates it from the
 * company's VAT period, turnover, legal form and, for yearly VAT, its EU
 * trade in the year (EuTrade). Two queries: the fiscal years, and the EU
 * trade when the company reports yearly.
 */
final class VatDeadlineProvider implements DeadlineProvider
{
    private const string ARTICLE = 'viktiga-datum/momsdeklarationens-datum';

    public const string SOURCE = 'https://www.skatteverket.se/foretag/moms/deklareramoms/narskajagdeklareramoms.4.6d02084411db6e252fe80008988.html';

    public function deadlines(Company $company, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (! $company->isVatRegistered()) {
            return [];
        }

        // A period that ended up to 14 months ago can still be due (yearly VAT for some legal forms).
        $years = FiscalCalendar::years($company, $from->subMonthsNoOverflow(14), $to);
        $euTrade = $company->vat_period === VatPeriod::Yearly ? EuTrade::years([$company->id]) : [];
        $deadlines = [];

        foreach ($years as $year) {
            foreach (VatPeriods::of($year, $company->vat_period) as $period) {
                $trade = in_array(EuTrade::key($company->id, $period['start']->toDateString()), $euTrade, true);
                $date = VatDeadlines::for($company->vat_period, $period['end'], $company->turnover_over_40_msek, $company->legal_form, $trade);

                if ($date === null || ! $date->betweenIncluded($from, $to)) {
                    continue;
                }

                $label = self::periodLabel($period['start'], $period['end'], $company->vat_period);
                $because = self::because($company);
                $foundation = $company->vat_period === VatPeriod::Yearly && in_array($company->legal_form, [LegalForm::IdeellForening, LegalForm::Stiftelse], true) && ! $trade;

                if ($company->vat_period === VatPeriod::Yearly && ! $trade) {
                    $because .= ' '.__('Handlar företaget med andra EU-länder under året blir datumet tidigare.');
                }

                if ($foundation) {
                    // NONOX cannot know whether it files an income tax return (SFL 30 kap. 4 §): the earlier 33 § date, flagged.
                    $filing = VatDeadlines::for($company->vat_period, $period['end'], $company->turnover_over_40_msek, $company->legal_form, false, true, true);
                    $because .= ' '.__('Lämnar föreningen eller stiftelsen inkomstdeklaration är sista dagen i stället :date.', ['date' => $filing?->translatedFormat('j F Y')]);
                }

                $deadlines[] = new Deadline(
                    'vat:'.$period['end']->toDateString(),
                    DeadlineType::Vat,
                    __('Deklarera och betala moms för :period', ['period' => $label]),
                    $date,
                    $label,
                    $because,
                    'vat.index',
                    self::ARTICLE,
                    self::SOURCE,
                    legalVerify: $foundation,
                    periodStart: $period['start'],
                );
            }
        }

        return $deadlines;
    }

    public static function periodLabel(CarbonImmutable $start, CarbonImmutable $end, VatPeriod $vatPeriod): string
    {
        return match ($vatPeriod) {
            VatPeriod::Monthly => $start->translatedFormat('F Y'),
            VatPeriod::Quarterly => __('kvartal :quarter :year', ['quarter' => $start->quarter, 'year' => $start->year]),
            default => $start->year === $end->year ? (string) $start->year : "{$start->year}/{$end->year}",
        };
    }

    private static function because(Company $company): string
    {
        $period = match ($company->vat_period) {
            VatPeriod::Monthly => __('Ni redovisar moms varje månad'),
            VatPeriod::Quarterly => __('Ni redovisar moms varje kvartal'),
            default => __('Ni redovisar moms en gång om året'),
        };

        return $company->turnover_over_40_msek ? __(':period och omsätter mer än 40 miljoner kronor om året.', ['period' => $period]) : "{$period}.";
    }
}
