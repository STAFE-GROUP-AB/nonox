<?php

declare(strict_types=1);

namespace App\Domain\Company;

/**
 * Which K-regelverk a company may choose (BFNAR 2006:1, 2010:1, 2016:10,
 * 2017:3, 2012:1; BFL 6 kap.; ÅRL 1 kap. 3 §).
 *
 * Enligt AI-utredning 2026-11-02, juristgranskning återstår.
 * TODO(legal-verify): the whole matrix. Sources read 2026-11-02:
 * https://www.bfn.se/redovisningsregler/allmanna-rad/,
 * https://www.bfn.se/wp-content/uploads/vl16-10-k2ar-kons2025.pdf (K2 punkt 1.1, 1.1A, 1.1B),
 * https://www.bfn.se/wp-content/uploads/2020/06/bfnar06-1-grund.pdf (K1 punkt 1.1-1.2),
 * https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/bokforingslag-19991078_sfs-1999-1078/ (BFL 6 kap. 1 §, 3 §).
 *
 * Only the legal form is known to NONOX. Size (net turnover, balance sheet
 * total, employees over two years) and the K2 exclusions (for example a
 * publikt aktiebolag) are not, so those are shown as conditions the user
 * confirms instead of being checked.
 */
final class ReportingFrameworkRules
{
    /**
     * Why the legal form cannot use the framework, or null when it may.
     */
    public static function refusal(LegalForm $legalForm, ReportingFramework $framework): ?string
    {
        if ($framework !== ReportingFramework::K1) {
            return null;
        }

        $refusal = match ($legalForm) {
            LegalForm::Ef, LegalForm::IdeellForening => null,
            // TODO(legal-verify): whether a handelsbolag or kommanditbolag with only
            // natural persons as partners may use K1. No allmänt råd for that was
            // found, so it is refused until verified.
            LegalForm::Hb, LegalForm::Kb => __('K1 för handels- och kommanditbolag är inte kontrollerat ännu. Välj K2 eller K3.'),
            default => __('K1 (förenklat årsbokslut) kan bara användas av enskilda firmor och ideella föreningar. :form kan välja K2 eller K3.', [
                'form' => $legalForm->label(),
            ]),
        };

        return is_string($refusal) ? $refusal : null;
    }

    /**
     * The conditions the user must confirm for the framework, or null when
     * there are none (K3 can always be used). Paragraphs are separated by a
     * blank line.
     *
     * K2 for a company that prepares an annual report (aktiebolag,
     * ekonomisk förening) also lists BFNAR 2016:10 punkt 1.1A–1.1C as
     * amended by BFNAR 2025:2 (ADR-059 stage F.1; source:
     * https://www.bfn.se/wp-content/uploads/vl16-10-k2ar-kons2025.pdf, read
     * 2026-11-06, pp. 15–16 and the transition rules p. 332).
     *
     * Enligt AI-utredning 2026-11-06, juristgranskning återstår.
     * TODO(legal-verify): the plain-Swedish summary of 1.1A–1.1C; and which
     * exclusions apply to a handelsbolag, kommanditbolag, stiftelse or
     * ideell förening that prepares an annual report under K2, and whether
     * BFNAR 2017:3 (K2 årsbokslut) has been amended to the same effect. For
     * those legal forms only the size condition is shown, as before.
     * TODO(legal-verify): the wording of punkt 1.1 that applies to fiscal
     * years beginning before 2026-01-01 (the pre-BFNAR 2025:2 text, in
     * vl16-10-k2ar-kons2024.pdf, not read); the form does not know the fiscal
     * year, so the 2025:2 conditions are shown with their effective date.
     */
    public static function conditions(LegalForm $legalForm, ReportingFramework $framework): ?string
    {
        $conditions = match ($framework) {
            ReportingFramework::K1 => __('K1 får bara användas om nettoomsättningen normalt är högst 3 miljoner kronor per år. En enskild firma som är moderföretag kan inte använda K1.'),
            ReportingFramework::K2 => implode("\n\n", self::k2Conditions($legalForm)),
            ReportingFramework::K3 => null,
        };

        return is_string($conditions) ? $conditions : null;
    }

    /** @return list<string> */
    private static function k2Conditions(LegalForm $legalForm): array
    {
        $size = __('K2 får bara användas av mindre företag. Ett företag är större om det under vart och ett av de två senaste räkenskapsåren har haft mer än ett av: fler än 50 anställda i medeltal, en balansomslutning över 40 miljoner kronor och en nettoomsättning över 80 miljoner kronor, eller om dess aktier eller andra värdepapper är noterade på en reglerad marknad.');

        $excluded = match ($legalForm) {
            LegalForm::Ab => __('K2 får inte heller användas av publika aktiebolag, moderföretag i en större koncern, moderföretag i en mindre koncern som upprättar koncernredovisning, företag som har eller under räkenskapsåret har haft filialer i utlandet, företag som under räkenskapsåret har köpt varor eller tjänster mot aktierelaterade ersättningar, företag som har eller under räkenskapsåret har haft skuldebrev som företaget har gett ut och som kan regleras med egetkapitalinstrument (till exempel konvertibla skuldebrev) eller liknande sammansatta finansiella instrument, och företag som har eller under räkenskapsåret har haft kryptotillgångar – utom den som bara någon enstaka gång har använt eller tagit emot kryptotillgångar som betalning (BFNAR 2016:10 punkt 1.1A).'),
            LegalForm::EkonomiskForening => __('K2 får inte heller användas av bostadsrättsföreningar och bostadsföreningar, moderföretag i en större koncern, moderföretag i en mindre koncern som upprättar koncernredovisning, företag som har eller under räkenskapsåret har haft filialer i utlandet, företag som under räkenskapsåret har köpt varor eller tjänster mot aktierelaterade ersättningar, företag som har eller under räkenskapsåret har haft skuldebrev som företaget har gett ut och som kan regleras med egetkapitalinstrument (till exempel konvertibla skuldebrev) eller liknande sammansatta finansiella instrument, och företag som har eller under räkenskapsåret har haft kryptotillgångar – utom den som bara någon enstaka gång har använt eller tagit emot kryptotillgångar som betalning (BFNAR 2016:10 punkt 1.1A).'),
            default => null,
        };

        if ($excluded === null) {
            return array_filter([$size], is_string(...));
        }

        return array_values(array_filter([
            $size,
            $excluded,
            __('K2 får inte heller användas av ett företag som har en väsentlig uppskjuten skatteskuld (tillgångarnas redovisade värde är högre än det skattemässiga, eller avsättningarnas eller skuldernas lägre), eller som har en eller flera byggnader som ger minst 75 procent av nettoomsättningen. Det bedöms utifrån den senaste färdiga årsredovisningen (punkt 1.1B).'),
            __('Punkt 1.1B gäller inte om högst ett av följande gäller under vart och ett av de två senaste räkenskapsåren: mer än 3 anställda i medeltal, en redovisad balansomslutning över 1,5 miljoner kronor, en redovisad nettoomsättning över 3 miljoner kronor. Den gäller inte heller ett företag som tillämpade K2 redan föregående räkenskapsår och som normalt inte omfattas av punkt 1.1B; med normalt avses mer än något eller några enstaka år (punkt 1.1C med kommentar).'),
            __('Punkterna 1.1A–1.1C i den här lydelsen kommer från BFNAR 2025:2 och gäller för räkenskapsår som börjar efter den 31 december 2025. Ett företag som inleder sin verksamhet efter den 30 juni 2025 och har förlängt räkenskapsår ska dock tillämpa dem redan för räkenskapsår som börjar efter den 30 juni 2025, om räkenskapsåret ska avslutas den 31 december 2026 eller senare. För tidigare räkenskapsår gäller en äldre lydelse, som inte visas här.'),
        ], is_string(...)));
    }
}
