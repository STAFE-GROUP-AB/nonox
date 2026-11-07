<?php

declare(strict_types=1);

namespace App\Domain\AnnualReport;

/**
 * The questions Bokslut → Regelverk asks a person when NONOX has no data to
 * answer a condition of K2 1.1–1.1C or the size test (ADR-059, addendum
 * 2026-11-07, S1-P11). The answer is stored per fiscal year as a
 * `closing_checks` row whose key is the case value plus ".yes" or ".no".
 *
 * Wording of K2 1.1A–1.1C: BFN, Vägledning BFNAR 2016:10, kons. 2025,
 * https://www.bfn.se/wp-content/uploads/vl16-10-k2ar-kons2025.pdf, pp. 15–20,
 * read 2026-11-07. Enligt AI-utredning, juristgranskning återstår.
 */
enum K3EligibilityQuestion: string
{
    case Listed = 'k3_size_listed';
    case FirstYear = 'k3_size_first_year';
    case PublicCompany = 'k2_1_1a_a';
    case ParentLargerGroup = 'k2_1_1a_b';
    case ParentGroupAccounts = 'k2_1_1a_c';
    case HousingCooperative = 'k2_1_1a_e';
    case ForeignBranch = 'k2_1_1a_f';
    case ShareBasedPayment = 'k2_1_1a_g';
    case CompoundInstruments = 'k2_1_1a_h';
    case CryptoAssets = 'k2_1_1a_i';
    case DeferredTax = 'k2_1_1b_a';
    case BuildingRevenue = 'k2_1_1b_b';
    case PreviousYearK2 = 'k2_1_1c_prev';
    case NormallyNotCovered = 'k2_1_1c_normally';

    /** The prefix of the rows that record the person's own classification of revenue for K2 1.1B b. */
    public const string BUILDING_ACCOUNT_PREFIX = 'k2_1_1b_b.account.';

    /** The row that records that the person has classified the revenue accounts (none may be from buildings). */
    public const string BUILDING_CLASSIFIED = 'k2_1_1b_b.classified';

    public function key(bool $yes): string
    {
        return $this->value.($yes ? '.yes' : '.no');
    }

    /** The rule the question belongs to, shown with the answer. */
    public function rule(): string
    {
        return match ($this) {
            self::Listed => 'ÅRL 1 kap. 3 § första stycket 4',
            self::FirstYear => 'BFNAR 2006:11 punkt 6, K2 1.1C',
            self::PublicCompany => 'K2 1.1A a',
            self::ParentLargerGroup => 'K2 1.1A b',
            self::ParentGroupAccounts => 'K2 1.1A c',
            self::HousingCooperative => 'K2 1.1A e',
            self::ForeignBranch => 'K2 1.1A f',
            self::ShareBasedPayment => 'K2 1.1A g',
            self::CompoundInstruments => 'K2 1.1A h',
            self::CryptoAssets => 'K2 1.1A i',
            self::DeferredTax => 'K2 1.1B a',
            self::BuildingRevenue => 'K2 1.1B b',
            self::PreviousYearK2 => 'K2 1.1C andra stycket a',
            self::NormallyNotCovered => 'K2 1.1C andra stycket b',
        };
    }

    /** The yes/no question, in plain Swedish; $date is the first fiscal year's start for FirstYear. */
    public function question(?string $date = null): string
    {
        return match ($this) {
            self::Listed => __('Är företagets aktier eller andra överlåtbara värdepapper upptagna till handel på en reglerad marknad?'),
            self::FirstYear => __('Är räkenskapsåret som börjar :date företagets allra första räkenskapsår?', ['date' => (string) $date]),
            self::PublicCompany => __('Är företaget ett publikt aktiebolag?'),
            self::ParentLargerGroup => __('Är företaget moderföretag i en större koncern?'),
            self::ParentGroupAccounts => __('Är företaget moderföretag i en mindre koncern och upprättar det koncernredovisning?'),
            self::HousingCooperative => __('Är föreningen en bostadsrättsförening eller en bostadsförening?'),
            self::ForeignBranch => __('Har företaget, eller har det under räkenskapsåret haft, en eller flera filialer i utlandet?'),
            self::ShareBasedPayment => __('Har företaget under räkenskapsåret förvärvat varor eller tjänster mot aktierelaterade ersättningar, till exempel personaloptioner eller aktier som betalning?'),
            self::CompoundInstruments => __('Har företaget, eller har det under räkenskapsåret haft, egna utgivna skuldebrev som kan regleras med egetkapitalinstrument (till exempel konvertibler) eller liknande sammansatta finansiella instrument?'),
            self::CryptoAssets => __('Har företaget, eller har det under räkenskapsåret haft, kryptotillgångar? Svara nej om företaget bara någon enstaka gång har använt eller tagit emot kryptotillgångar som betalning, eller bara äger dem indirekt, till exempel genom certifikat.'),
            self::DeferredTax => __('Har företaget en uppskjuten skatteskuld som är väsentlig, bedömd på det senaste räkenskapsåret med en färdigställd årsredovisning?'),
            self::BuildingRevenue => __('Genererar en eller flera av företagets byggnader minst 75 procent av nettoomsättningen, bedömt på det senaste räkenskapsåret med en färdigställd årsredovisning?'),
            self::PreviousYearK2 => __('Tillämpade företaget K2 (BFNAR 2016:10) redan föregående räkenskapsår?'),
            self::NormallyNotCovered => __('Omfattas företaget normalt inte av punkt 1.1B? BFN:s kommentar: ”Med normalt avses mer än något eller några enstaka år.”'),
        };
    }

    /** A short label for the list of conditions. */
    public function label(): string
    {
        return match ($this) {
            self::Listed => __('Värdepapper på en reglerad marknad'),
            self::FirstYear => __('Företagets första räkenskapsår'),
            self::PublicCompany => __('Publikt aktiebolag'),
            self::ParentLargerGroup => __('Moderföretag i en större koncern'),
            self::ParentGroupAccounts => __('Moderföretag som upprättar koncernredovisning'),
            self::HousingCooperative => __('Bostadsrättsförening eller bostadsförening'),
            self::ForeignBranch => __('Filial i utlandet'),
            self::ShareBasedPayment => __('Aktierelaterade ersättningar'),
            self::CompoundInstruments => __('Konvertibler och liknande sammansatta instrument'),
            self::CryptoAssets => __('Kryptotillgångar'),
            self::DeferredTax => __('Väsentlig uppskjuten skatteskuld'),
            self::BuildingRevenue => __('Byggnader som ger minst 75 procent av nettoomsättningen'),
            self::PreviousYearK2 => __('K2 föregående år'),
            self::NormallyNotCovered => __('Normalt inte omfattat av punkt 1.1B'),
        };
    }

    /** The K2 1.1A conditions that close K2 when the answer is yes. */
    public function closesK2(): bool
    {
        return match ($this) {
            self::PublicCompany, self::ParentLargerGroup, self::ParentGroupAccounts, self::HousingCooperative,
            self::ForeignBranch, self::ShareBasedPayment, self::CompoundInstruments, self::CryptoAssets => true,
            default => false,
        };
    }

    /**
     * The question for a stored key such as "k2_1_1a_g.yes", with its answer.
     *
     * @return ?array{0: self, 1: bool}
     */
    public static function parse(string $key): ?array
    {
        if (! preg_match('/^(.+)\.(yes|no)$/', $key, $matches)) {
            return null;
        }

        $question = self::tryFrom($matches[1]);

        return $question === null ? null : [$question, $matches[2] === 'yes'];
    }
}
