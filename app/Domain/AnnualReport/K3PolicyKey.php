<?php

declare(strict_types=1);

namespace App\Domain\AnnualReport;

/**
 * The K3 accounting policies a company records as data (ADR-059, addendum
 * 2026-11-07, A.2.3). The `components` policy is added after human decision
 * H4 (S1-R3.1) and is deliberately not a key yet.
 */
enum K3PolicyKey: string
{
    case FinancialInstruments = 'financial_instruments';
    case Intangibles = 'intangibles';
    case Leasing = 'leasing';
    case BorrowingCosts = 'borrowing_costs';
    case FixedPriceContracts = 'fixed_price_contracts';
    case CashFlowStatement = 'cash_flow_statement';
    case ComparativesRestatedAtTransition = 'comparatives_restated_at_transition';
    case K3AppliedBefore = 'k3_applied_before';
    case LeftK2Reason = 'left_k2_reason';

    /** @return class-string<K3PolicyValue> */
    public function valueEnum(): string
    {
        return match ($this) {
            self::FinancialInstruments => K3FinancialInstruments::class,
            self::Intangibles => K3Intangibles::class,
            self::Leasing => K3Leasing::class,
            self::BorrowingCosts => K3BorrowingCosts::class,
            self::FixedPriceContracts => K3FixedPriceContracts::class,
            self::CashFlowStatement => K3CashFlowStatement::class,
            self::ComparativesRestatedAtTransition, self::K3AppliedBefore => K3YesNo::class,
            self::LeftK2Reason => K3LeftK2Reason::class,
        };
    }

    /** @return list<string> */
    public function values(): array
    {
        $enum = $this->valueEnum();

        return array_map(fn (K3PolicyValue $value): string => (string) $value->value, $enum::cases());
    }

    public function valueOf(string $value): ?K3PolicyValue
    {
        $enum = $this->valueEnum();

        return $enum::tryFrom($value);
    }

    /** The rule id the policy implements, shown with every refusal. */
    public function rule(): string
    {
        return match ($this) {
            self::FinancialInstruments => 'S1-R6',
            self::Intangibles => 'S1-R10',
            self::Leasing => 'S1-R5',
            self::BorrowingCosts => 'K3 25.3',
            self::FixedPriceContracts => 'ADR-059 3.2.5',
            self::CashFlowStatement => 'S1-R2',
            self::ComparativesRestatedAtTransition => 'S1-R7.1',
            self::K3AppliedBefore, self::LeftK2Reason => 'S1-R7.6',
        };
    }

    /** Asked only for the first K3 year after K2 (chapter 35). */
    public function transitionOnly(): bool
    {
        return match ($this) {
            self::ComparativesRestatedAtTransition, self::K3AppliedBefore, self::LeftK2Reason => true,
            default => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::FinancialInstruments => __('Finansiella instrument'),
            self::Intangibles => __('Utgifter för eget utvecklingsarbete'),
            self::Leasing => __('Leasing'),
            self::BorrowingCosts => __('Låneutgifter'),
            self::FixedPriceContracts => __('Uppdrag till fast pris'),
            self::CashFlowStatement => __('Kassaflödesanalys'),
            self::ComparativesRestatedAtTransition => __('Omräknade jämförelsetal vid övergången'),
            self::K3AppliedBefore => __('K3 tillämpat tidigare'),
            self::LeftK2Reason => __('Skäl till att lämna K2'),
        };
    }

    /** The question a person answers when the policy is missing for a year. */
    public function question(): string
    {
        return match ($this) {
            self::FinancialInstruments => __('Värderas företagets finansiella instrument utifrån anskaffningsvärdet (K3 kapitel 11) eller till verkligt värde (kapitel 12)?'),
            self::Intangibles => __('Kostnadsförs utgifterna för eget utvecklingsarbete (kostnadsföringsmodellen) eller tas de upp som tillgång (aktiveringsmodellen)?'),
            self::Leasing => __('Redovisas alla finansiella leasingavtal som operationella leasingavtal, som K3 20.29 tillåter i juridisk person?'),
            self::BorrowingCosts => __('Kostnadsförs låneutgifterna, eller räknas de in i tillgångarnas anskaffningsvärde?'),
            self::FixedPriceContracts => __('Hur redovisas uppdrag till fast pris: successivt, när uppdraget är slutfört, eller har företaget inga sådana uppdrag? Redovisning när uppdraget är slutfört (K3 23.31) får bara användas i juridisk person, av företag i verksamheter som avses i 17 kap. 23 § inkomstskattelagen, och då för alla uppdrag enligt 17 kap. 27 § på samma sätt.'),
            self::CashFlowStatement => __('Ska årsredovisningen ha en kassaflödesanalys (indirekt metod)?'),
            self::ComparativesRestatedAtTransition => __('Räknas jämförelsetalen för föregående år om enligt K3 vid övergången?'),
            self::K3AppliedBefore => __('Har företaget tillämpat K3 för något tidigare räkenskapsår?'),
            self::LeftK2Reason => __('Lämnar företaget K2 för att det inte längre får tillämpa K2 i lydelsen enligt BFNAR 2025:2, eller av eget val?'),
        };
    }
}
