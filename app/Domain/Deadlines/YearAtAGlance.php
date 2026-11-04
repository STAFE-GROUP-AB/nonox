<?php

declare(strict_types=1);

namespace App\Domain\Deadlines;

use App\Domain\Company\LegalForm;
use App\Domain\Company\VatPeriod;
use App\Models\Company;
use Carbon\CarbonImmutable;

/**
 * "Ditt år i korthet" on Mina datum (ADR-051): one line per kind of date
 * that applies to the company, in plain Swedish, with the setting that
 * decides it and the next date. Built from the company's settings and the
 * engine's own dates, so a line is only there when the engine has dates of
 * that kind. A flag that would repeat on every row of a series (the
 * F-skatt decision) is stated once here. The facts are REGLER.md's.
 *
 * @phpstan-type Line array{
 *     category: DeadlineCategory,
 *     title: string,
 *     when: string,
 *     setting: string,
 *     settingLink: bool,
 *     next: ?Deadline,
 *     flag: ?string,
 *     authority: ?string
 * }
 */
final class YearAtAGlance
{
    /**
     * @param  list<Deadline>  $deadlines  the engine's dates for the coming fifteen months
     * @return list<Line>
     */
    public static function lines(Company $company, array $deadlines, CarbonImmutable $today): array
    {
        $byCategory = [];

        foreach ($deadlines as $deadline) {
            $byCategory[DeadlineCategory::of($deadline)->value][] = $deadline;
        }

        $lines = [];
        $form = $company->legal_form;
        $partnership = in_array($form, [LegalForm::Hb, LegalForm::Kb], true);
        $settings = fn (string $field): string => __('Styrs av :field under Företagets uppgifter.', ['field' => $field]);

        if (! $company->isVatRegistered()) {
            $lines[] = self::line(DeadlineCategory::Vat, __('Moms – ingen momsredovisning'), __('Företaget är inte momsregistrerat, så här visas inga momsdatum.'), $settings(__('Momsredovisning')));
        } elseif (isset($byCategory[DeadlineCategory::Vat->value])) {
            $vat = $byCategory[DeadlineCategory::Vat->value];
            $flagged = array_filter($vat, fn (Deadline $deadline): bool => $deadline->legalVerify) !== [];
            $lines[] = self::line(
                DeadlineCategory::Vat,
                match ($company->vat_period) {
                    VatPeriod::Monthly => __('Moms – tolv gånger om året, en gång per månad (ni redovisar per månad)'),
                    VatPeriod::Quarterly => __('Moms – fyra gånger om året, en gång per kvartal (ni redovisar per kvartal)'),
                    default => __('Moms – en gång om året (ni redovisar per år)'),
                },
                match (true) {
                    $company->vat_period === VatPeriod::Monthly && $company->turnover_over_40_msek => __('Deklarera och betala senast den 26:e i månaden efter (27:e i december).'),
                    $company->vat_period === VatPeriod::Monthly => __('Deklarera och betala senast den 12:e i andra månaden efter (17:e i januari och augusti).'),
                    $company->vat_period === VatPeriod::Quarterly => __('Deklarera och betala senast den 12:e i andra månaden efter kvartalet (17 augusti för april–juni).'),
                    default => __('Deklarera och betala en gång efter räkenskapsåret. Dagen beror på bolagsformen, räkenskapsåret och om företaget handlar med andra EU-länder.'),
                },
                $company->turnover_over_40_msek ? $settings(__('Momsredovisning och omsättningen')) : $settings(__('Momsredovisning')),
                self::next($vat, $today),
                $flagged ? (string) __('Datumet beror på om föreningen eller stiftelsen lämnar inkomstdeklaration.') : null,
            );
        }

        if (isset($byCategory[DeadlineCategory::EuSales->value])) {
            $lines[] = self::line(
                DeadlineCategory::EuSales,
                __('EU-försäljning – en periodisk sammanställning efter varje period med försäljning till företag i andra EU-länder'),
                __('Lämna den senast den 25:e i månaden efter perioden, när du lämnar den digitalt.'),
                __('Styrs av fakturorna till kunder i andra EU-länder.'),
                self::next($byCategory[DeadlineCategory::EuSales->value], $today),
                settingLink: false,
            );
        }

        if ($company->f_skatt && $partnership) {
            $lines[] = self::line(
                DeadlineCategory::PreliminaryTax,
                __('F-skatt – betalas av delägarna, inte av bolaget'),
                __('Ett handelsbolag betalar ingen inkomstskatt själv. Delägarna betalar preliminärskatt för sin del av vinsten.'),
                $settings(__('bolagsformen')),
            );
        } elseif (isset($byCategory[DeadlineCategory::PreliminaryTax->value])) {
            $lines[] = self::line(
                DeadlineCategory::PreliminaryTax,
                AgiDates::isLarge($company)
                    ? __('F-skatt – varje månad, oftast den 12:e (17:e i januari)')
                    : __('F-skatt – varje månad, oftast den 12:e (17:e i januari och augusti)'),
                __('F-skatt är företagets skatt i förskott (preliminärskatt), samma belopp varje månad. Det är en betalning, inte moms.')
                    .($form === LegalForm::Ef ? ' '.__('I enskild firma ingår egenavgifterna.') : ''),
                $settings(__('Företaget har F-skatt')),
                self::next($byCategory[DeadlineCategory::PreliminaryTax->value], $today),
                __('Första månaden och beloppet står i ert beslut om F-skatt från Skatteverket.'),
            );
        }

        if (isset($byCategory[DeadlineCategory::Employer->value])) {
            $large = AgiDates::isLarge($company);
            $lines[] = self::line(
                DeadlineCategory::Employer,
                $large
                    ? __('Arbetsgivardeklaration – varje månad: betala den 12:e, deklarera den 26:e')
                    : __('Arbetsgivardeklaration – varje månad, den 12:e (17:e i januari och augusti)'),
                $large
                    ? __('Skatteavdrag och arbetsgivaravgifter ska vara betalda senast den 12:e (17:e i januari), och deklarationen lämnas senast den 26:e (27:e i december).')
                    : __('Deklarera och betala samma dag, för lönen som betalades ut månaden före. Också en nolldeklaration en månad utan löner.'),
                $settings(__('Företaget är registrerat som arbetsgivare')),
                self::next($byCategory[DeadlineCategory::Employer->value], $today),
            );
        }

        if (isset($byCategory[DeadlineCategory::IncomeTax->value])) {
            $lines[] = self::line(
                DeadlineCategory::IncomeTax,
                match (true) {
                    $form === LegalForm::Ef => __('Inkomstdeklaration – en gång om året, med NE-bilagan i din egen deklaration'),
                    $partnership => __('Inkomstdeklaration 4 – en gång om året'),
                    default => __('Inkomstdeklaration – en gång om året'),
                },
                match (true) {
                    $form === LegalForm::Ef => __('Senast 2 maj året efter, eller nästa vardag om dagen är en helg.'),
                    $partnership => __('Handelsbolaget lämnar särskilda uppgifter, och delägarna deklarerar sin del själva.'),
                    default => __('Inkomstdeklaration 2 lämnas efter räkenskapsåret. Dagen beror på när räkenskapsåret slutar.'),
                },
                $settings(__('bolagsformen och räkenskapsåret')),
                self::next($byCategory[DeadlineCategory::IncomeTax->value], $today),
            );
        }

        if (isset($byCategory[DeadlineCategory::Bolagsverket->value])) {
            $lines[] = self::line(
                DeadlineCategory::Bolagsverket,
                match (true) {
                    $partnership => __('Årsredovisning – bara om en delägare är en juridisk person'),
                    $form === LegalForm::EkonomiskForening => __('Föreningsstämma och årsredovisning – en gång om året'),
                    default => __('Årsstämma och årsredovisning – en gång om året'),
                },
                $partnership
                    ? __('Då ska årsredovisningen vara hos Bolagsverket senast sex månader efter räkenskapsårets slut.')
                    : __('Stämman hålls inom sex månader efter räkenskapsårets slut. Årsredovisningen ska vara hos Bolagsverket inom en månad efter stämman, och senast sju månader efter årets slut för att slippa förseningsavgift.'),
                $settings(__('bolagsformen och räkenskapsåret')),
                self::next($byCategory[DeadlineCategory::Bolagsverket->value], $today, recommendations: $partnership),
            );
        }

        if (isset($byCategory[DeadlineCategory::Closing->value])) {
            $lines[] = self::line(
                DeadlineCategory::Closing,
                __('Bokslut – en gång om året (rekommendation)'),
                in_array($form, [LegalForm::Ab, LegalForm::EkonomiskForening], true)
                    ? __('Lagen har inget datum för bokslutet. Företagsguiden föreslår ett datum i god tid före stämman och deklarationen.')
                    : __('Lagen har inget datum för bokslutet. Företagsguiden föreslår ett datum i god tid före deklarationen.'),
                $settings(__('räkenskapsåret')),
                self::next($byCategory[DeadlineCategory::Closing->value], $today, recommendations: true),
            );
        }

        return $lines;
    }

    /** @return Line */
    private static function line(DeadlineCategory $category, string $title, string $when, string $setting, ?Deadline $next = null, ?string $flag = null, bool $settingLink = true): array
    {
        return [
            'category' => $category,
            'title' => $title,
            'when' => $when,
            'setting' => $setting,
            'settingLink' => $settingLink,
            'next' => $next,
            'flag' => $flag,
            'authority' => $flag === null ? null : 'Skatteverket',
        ];
    }

    /**
     * The first date from today; a recommendation only where the kind is all advice.
     *
     * @param  list<Deadline>  $deadlines
     */
    private static function next(array $deadlines, CarbonImmutable $today, bool $recommendations = false): ?Deadline
    {
        foreach ($deadlines as $deadline) {
            if ($deadline->daysLeft($today) >= 0 && ($recommendations || ! $deadline->isRecommendation)) {
                return $deadline;
            }
        }

        return null;
    }
}
