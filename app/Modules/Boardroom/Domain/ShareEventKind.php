<?php

declare(strict_types=1);

namespace App\Modules\Boardroom\Domain;

/**
 * The kinds of entries in the share register (ADR-070 §2, extended by the
 * rule sheet's section G). The register is never edited: every change is
 * one of these, and the register at a date is what they add up to
 * (RegisterFold).
 */
enum ShareEventKind: string
{
    /** An existing company's register as it stands when it moves to NONOX (L10). */
    case Opening = 'OPENING';
    /** The shares subscribed in the memorandum of association (ABL 5 kap. 8 §). */
    case Formation = 'FORMATION';
    /** Shares allotted in a new issue: entered at once, registered later (13 kap. 18, 27, 29 §§). */
    case NewIssue = 'NEW_ISSUE';
    /** A new issue registered by Bolagsverket: its capital counts from then. */
    case Registration = 'REGISTRATION';
    /** A new issue not reported in time or refused: the shares are struck (13 kap. 30 §). */
    case NewIssueLapsed = 'NEW_ISSUE_LAPSED';
    /** Fondemission: effective on registration (12 kap. 10 §). */
    case BonusIssue = 'BONUS_ISSUE';
    case Transfer = 'TRANSFER';
    case InheritanceGift = 'INHERITANCE_GIFT';
    /** Effective on registration (4 kap. 49 §). */
    case Split = 'SPLIT';
    /** Effective on registration; surplus shares pass to the company (4 kap. 49–50 §§). */
    case ReverseSplit = 'REVERSE_SPLIT';
    /** Indragning when the reduction is carried out (20 kap. 21 §). */
    case Redemption = 'REDEMPTION';
    /** Omvandling of share class, completed on registration (4 kap. 6 §). */
    case Conversion = 'CONVERSION';
    /** Whether a share certificate has been issued (5 kap. 5 § 4). */
    case Certificate = 'CERTIFICATE';
    /** A rights holder, for example testamentary usufruct (5 kap. 6 §). */
    case RightsHolderNote = 'RIGHTS_HOLDER_NOTE';
    /** Förköp notes (4 kap. 20–22 §§). */
    case PreEmptionNotice = 'PRE_EMPTION_NOTICE';
    case PreEmptionClaim = 'PRE_EMPTION_CLAIM';
    case PreEmptionAction = 'PRE_EMPTION_ACTION';
    /** Ends one of the notes above. */
    case NoteEnded = 'NOTE_ENDED';
    /** A holder's changed name or address (5 kap. 9 § andra stycket): not a mistake. */
    case AddressChange = 'ADDRESS_CHANGE';
    /** Voids an earlier entry that was wrong; the original stays. */
    case Correction = 'CORRECTION';

    public function label(): string
    {
        return match ($this) {
            self::Opening => __('Aktieboken flyttad till NONOX'),
            self::Formation => __('Bildande'),
            self::NewIssue => __('Nyemission'),
            self::Registration => __('Nyemission registrerad'),
            self::NewIssueLapsed => __('Nyemission har fallit'),
            self::BonusIssue => __('Fondemission'),
            self::Transfer => __('Överlåtelse'),
            self::InheritanceGift => __('Arv, gåva eller bodelning'),
            self::Split => __('Split'),
            self::ReverseSplit => __('Sammanläggning'),
            self::Redemption => __('Indragning'),
            self::Conversion => __('Omvandling av aktieslag'),
            self::Certificate => __('Aktiebrev'),
            self::RightsHolderNote => __('Rättighetshavare'),
            self::PreEmptionNotice => __('Anmälan om förköp'),
            self::PreEmptionClaim => __('Begäran om förköp'),
            self::PreEmptionAction => __('Talan om förköp'),
            self::NoteEnded => __('Anteckning avslutad'),
            self::AddressChange => __('Ändrade uppgifter om ägare'),
            self::Correction => __('Rättelse'),
        };
    }

    /** Whether the entry names a range of shares. */
    public function needsRange(): bool
    {
        return in_array($this, [
            self::Opening, self::Formation, self::NewIssue, self::Transfer, self::InheritanceGift, self::Redemption,
            self::Conversion, self::Certificate, self::RightsHolderNote, self::PreEmptionNotice, self::PreEmptionClaim, self::PreEmptionAction,
        ], true);
    }

    /** Whether the entry names who receives the shares, or whose details change. */
    public function needsRecipient(): bool
    {
        return in_array($this, [self::Opening, self::Formation, self::NewIssue, self::Transfer, self::InheritanceGift, self::AddressChange], true);
    }

    /** Whether the entry names the holder the shares come from. */
    public function needsSender(): bool
    {
        return in_array($this, [self::Transfer, self::InheritanceGift], true);
    }

    /** Whether the entry adds shares and so needs a class and a share capital. */
    public function issuesShares(): bool
    {
        return in_array($this, [self::Opening, self::Formation, self::NewIssue], true);
    }

    /** Whether the entry points at an earlier one. */
    public function refersToEvent(): bool
    {
        return in_array($this, [self::Registration, self::NewIssueLapsed, self::NoteEnded, self::Correction], true);
    }

    /**
     * Whether the change takes effect only on registration with Bolagsverket,
     * so the effective date is the registration date (rule sheet AB-R42).
     */
    public function effectiveOnRegistration(): bool
    {
        return in_array($this, [self::BonusIssue, self::Split, self::ReverseSplit, self::Conversion, self::Registration], true);
    }

    /** The notes that a NOTE_ENDED can end and that show on the printed register. */
    public function isNote(): bool
    {
        return in_array($this, [self::RightsHolderNote, self::PreEmptionNotice, self::PreEmptionClaim, self::PreEmptionAction], true);
    }

    /** Whether the entry needs a ratio (split and reverse split). */
    public function needsRatio(): bool
    {
        return in_array($this, [self::Split, self::ReverseSplit], true);
    }

    /** Kinds a user may pick in the form; the opening ones only start a register. */
    public function startsRegister(): bool
    {
        return in_array($this, [self::Opening, self::Formation], true);
    }
}
