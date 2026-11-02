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
     * there are none (K3 can always be used).
     */
    public static function conditions(LegalForm $legalForm, ReportingFramework $framework): ?string
    {
        $conditions = match ($framework) {
            ReportingFramework::K1 => __('K1 får bara användas om nettoomsättningen normalt är högst 3 miljoner kronor per år. En enskild firma som är moderföretag kan inte använda K1.'),
            ReportingFramework::K2 => $legalForm === LegalForm::Ab
                ? __('K2 får bara användas av mindre företag. Ett företag är större om det under vart och ett av de två senaste räkenskapsåren har haft mer än ett av: fler än 50 anställda i medeltal, en balansomslutning över 40 miljoner kronor och en nettoomsättning över 80 miljoner kronor, eller om dess aktier eller andra värdepapper är noterade på en reglerad marknad. Publika aktiebolag och moderföretag i en större koncern kan inte heller använda K2.')
                : __('K2 får bara användas av mindre företag. Ett företag är större om det under vart och ett av de två senaste räkenskapsåren har haft mer än ett av: fler än 50 anställda i medeltal, en balansomslutning över 40 miljoner kronor och en nettoomsättning över 80 miljoner kronor, eller om dess aktier eller andra värdepapper är noterade på en reglerad marknad.'),
            ReportingFramework::K3 => null,
        };

        return is_string($conditions) ? $conditions : null;
    }
}
