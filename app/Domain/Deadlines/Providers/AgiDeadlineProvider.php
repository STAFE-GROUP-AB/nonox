<?php

declare(strict_types=1);

namespace App\Domain\Deadlines\Providers;

use App\Domain\Deadlines\AgiDates;
use App\Domain\Deadlines\Contracts\DeadlineProvider;
use App\Domain\Deadlines\Deadline;
use App\Domain\Deadlines\DeadlineType;
use App\Models\Company;
use App\Modules\ModuleManager;
use App\Modules\Payroll\PayrollModule;
use Carbon\CarbonImmutable;

/**
 * The employer declaration for every payment month (REGLER.md §2), from
 * the law rather than from Skatteverket's yearly list, so it works for
 * every year and with or without the payroll module:
 *
 * - VAT base up to 40 million kronor, or not VAT registered: declare and
 *   pay by the 12th of the month after, the 17th in January and August
 *   (SFL 26 kap. 26 och 32 §§, 62 kap. 3 § första stycket).
 * - Above 40 million kronor: pay by the 12th (17th in January), declare by
 *   the 26th (27th in December) (SFL 26 kap. 30 §, 62 kap. 3 § andra
 *   stycket): two dates.
 *
 * Moved to the next working day (§0.1). Not handled: a decision under 26
 * kap. 31 §. The link to the AGI page only while the payroll module is on.
 */
final class AgiDeadlineProvider implements DeadlineProvider
{
    public function __construct(private readonly ModuleManager $modules) {}

    public const string SOURCE = 'https://www.skatteverket.se/foretag/arbetsgivare/lamnaarbetsgivardeklaration/narskajaglamnaarbetsgivardeklaration.4.361dc8c15312eff6fd13c11.html';

    private const string ARTICLE = 'viktiga-datum/arbetsgivardeklarationens-datum';

    public function deadlines(Company $company, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (! $company->employer_registered) {
            return [];
        }

        $large = AgiDates::isLarge($company);
        // The AGI page is the payroll module's: without it there is nowhere in NONOX to do it.
        $route = $this->modules->isEnabled(PayrollModule::KEY, $company) ? 'payroll.agi' : null;
        $deadlines = [];

        for ($month = $from->subMonthNoOverflow()->startOfMonth(); $month->lessThanOrEqualTo($to); $month = $month->addMonthNoOverflow()) {
            $period = $month->format('Y-m');
            $label = $month->translatedFormat('F Y');

            if (! $large) {
                $deadlines[] = new Deadline(
                    "agi:{$period}",
                    DeadlineType::Agi,
                    __('Arbetsgivardeklaration för :month', ['month' => $label]),
                    AgiDates::declaration($period, false),
                    $label,
                    __('Företaget är registrerat som arbetsgivare. Deklarationen lämnas och skatten betalas samma dag, också en nolldeklaration för en månad utan löner.'),
                    $route,
                    self::ARTICLE,
                    self::SOURCE,
                    periodStart: $month,
                );

                continue;
            }

            $deadlines[] = new Deadline(
                "agi-payment:{$period}",
                DeadlineType::Agi,
                __('Betala skatteavdrag och arbetsgivaravgifter för :month', ['month' => $label]),
                AgiDates::payment($period, true),
                $label,
                __('Företaget är arbetsgivare och omsätter mer än 40 miljoner kronor om året: skatten betalas två veckor före deklarationen.'),
                $route,
                self::ARTICLE,
                self::SOURCE,
                periodStart: $month,
            );
            $deadlines[] = new Deadline(
                "agi:{$period}",
                DeadlineType::Agi,
                __('Arbetsgivardeklaration för :month', ['month' => $label]),
                AgiDates::declaration($period, true),
                $label,
                __('Företaget är arbetsgivare och omsätter mer än 40 miljoner kronor om året.'),
                $route,
                self::ARTICLE,
                self::SOURCE,
                periodStart: $month,
            );
        }

        return $deadlines;
    }
}
