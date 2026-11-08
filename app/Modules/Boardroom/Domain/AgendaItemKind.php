<?php

declare(strict_types=1);

namespace App\Modules\Boardroom\Domain;

/**
 * What an agenda item is about. The three decisions every annual general
 * meeting takes (ABL 7 kap. 11 §) are mandatory and cannot be removed.
 */
enum AgendaItemKind: string
{
    case Procedure = 'PROCEDURE';
    case Adoption = 'ADOPTION';
    case Allocation = 'ALLOCATION';
    case Discharge = 'DISCHARGE';
    case Fees = 'FEES';
    case ElectionBoard = 'ELECTION_BOARD';
    case ElectionAuditor = 'ELECTION_AUDITOR';
    case Articles = 'ARTICLES';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::Procedure => __('Formalia'),
            self::Adoption => __('Fastställelse'),
            self::Allocation => __('Vinst eller förlust'),
            self::Discharge => __('Ansvarsfrihet'),
            self::Fees => __('Arvoden'),
            self::ElectionBoard => __('Val av styrelse'),
            self::ElectionAuditor => __('Val av revisor'),
            self::Articles => __('Ändring av bolagsordningen'),
            self::Other => __('Övrigt'),
        };
    }

    public function isMandatoryOnAgm(): bool
    {
        return in_array($this, [self::Adoption, self::Allocation, self::Discharge], true);
    }

    /** What a decision under the item usually has to be reported to Bolagsverket as, if anything. */
    public function suggestedReport(): ?ReportableKind
    {
        return match ($this) {
            self::ElectionBoard => ReportableKind::BoardChange,
            self::ElectionAuditor => ReportableKind::AuditorChange,
            self::Articles => ReportableKind::ArticlesChange,
            default => null,
        };
    }
}
