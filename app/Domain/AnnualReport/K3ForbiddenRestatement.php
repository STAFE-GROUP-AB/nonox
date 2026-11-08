<?php

declare(strict_types=1);

namespace App\Domain\AnnualReport;

/**
 * The retroactive restatements K3 chapter 35 forbids at the transition from
 * K2 (rule sheet S1-R7.2, BFN's consolidated K3 vl12-1-k3-kons20251215, pp.
 * 286–287, read 2026-11-07). A person who tries to enter one on Bokslut →
 * Övergång till K3 is refused with the rule:
 *
 * - 35.15: earlier estimates and judgements may not be changed;
 * - 35.16: internally generated intangible assets expensed earlier may not
 *   be capitalised;
 * - 35.18: "Materiella anläggningstillgångar, som enligt punkt 17.4 ska delas
 *   upp på komponenter, får inte räknas om retroaktivt. Det redovisade värdet
 *   för dessa tillgångar ska, per tidpunkten för övergång, delas upp på
 *   betydande komponenter." The split is made in the fixed asset register and
 *   creates no equity difference;
 * - 35.20: borrowing costs expensed earlier may not be capitalised.
 */
enum K3ForbiddenRestatement: string
{
    case ChangedEstimate = 'CHANGED_ESTIMATE';
    case InternallyGeneratedIntangible = 'INTERNALLY_GENERATED_INTANGIBLE';
    case ComponentRestatement = 'COMPONENT_RESTATEMENT';
    case CapitalisedBorrowingCosts = 'CAPITALISED_BORROWING_COSTS';

    public function rule(): string
    {
        return match ($this) {
            self::ChangedEstimate => 'K3 35.15',
            self::InternallyGeneratedIntangible => 'K3 35.16',
            self::ComponentRestatement => 'K3 35.18',
            self::CapitalisedBorrowingCosts => 'K3 35.20',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ChangedEstimate => __('Ändrad uppskattning eller bedömning'),
            self::InternallyGeneratedIntangible => __('Aktivering av tidigare kostnadsförda utgifter för eget utvecklingsarbete'),
            self::ComponentRestatement => __('Omräkning av avskrivningar per komponent bakåt i tiden'),
            self::CapitalisedBorrowingCosts => __('Aktivering av tidigare kostnadsförda låneutgifter'),
        };
    }

    /** Why the restatement is refused, in Swedish. */
    public function message(): string
    {
        return match ($this) {
            self::ChangedEstimate => __('Uppskattningar och bedömningar som gjordes enligt K2 får inte ändras i efterhand vid övergången (K3 punkt 35.15).'),
            self::InternallyGeneratedIntangible => __('Utgifter för eget utvecklingsarbete som har kostnadsförts får inte tas upp som tillgång vid övergången (K3 punkt 35.16).'),
            self::ComponentRestatement => __('Materiella anläggningstillgångar räknas inte om bakåt i tiden. Det redovisade värdet per övergångsdagen delas upp på komponenter i anläggningsregistret, och det ger ingen skillnad i eget kapital (K3 punkt 35.18).'),
            self::CapitalisedBorrowingCosts => __('Låneutgifter som har kostnadsförts får inte räknas in i anskaffningsvärdet vid övergången (K3 punkt 35.20).'),
        };
    }

    /**
     * The forbidden restatement an account of a PROVISION or OTHER item
     * would be: intangible fixed assets (BAS 10xx) and tangible fixed assets
     * (11xx–12xx) cannot be restated through the transition.
     *
     * TODO(legal-verify): 35.29 (dismantling costs measured at the transition
     * date) would add to a tangible asset; it is not supported, so 11xx–12xx
     * are refused outright.
     */
    public static function forAccount(int $account): ?self
    {
        if ($account >= 1000 && $account <= 1099) {
            return self::InternallyGeneratedIntangible;
        }

        if ($account >= 1100 && $account <= 1299) {
            return self::ComponentRestatement;
        }

        return null;
    }
}
