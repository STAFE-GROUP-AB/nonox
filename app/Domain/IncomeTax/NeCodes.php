<?php

declare(strict_types=1);

namespace App\Domain\IncomeTax;

/**
 * The field codes of the NE-bilaga (SKV 2161, block NE-{period}) and the BAS
 * accounts behind each of them (ADR-053). The codes are Skatteverket's own,
 * read from the field table NE_SKV2161-14-02-26-02 in "Nyheter from
 * beskattningsperiod 2026P4" (Teknisk information om filöverföring,
 * skatteverket.se); a period whose table has not been checked is refused.
 *
 * TODO(legal-verify): the ranges follow BAS's own SRU coupling table for NE
 * (NE_EJ_K1 Intervall-231002, bas.se); a 2025 or 2026 edition was not
 * available, and BAS says an account may belong to another row depending on
 * what the company has booked. Check them against the current table.
 */
final class NeCodes
{
    /** Beskattningsperioder whose field tables have been checked. */
    public const array VERIFIED_PERIODS = ['2026P4'];

    /**
     * Balance sheet, assets (B1–B9): code => list of [from, to], debit balances.
     *
     * @var array<int, list<array{0: int, 1: int}>>
     */
    public const array ASSETS = [
        7200 => [[1000, 1099]],
        7210 => [[1100, 1129], [1150, 1179], [1190, 1199]],
        7211 => [[1130, 1149], [1180, 1189], [1291, 1291]],
        7212 => [[1200, 1290], [1292, 1299]],
        7213 => [[1300, 1399]],
        7240 => [[1400, 1499]],
        7250 => [[1500, 1599]],
        7260 => [[1600, 1899]],
        7280 => [[1900, 1999]],
    ];

    /**
     * Balance sheet, reserves and liabilities (B11–B16): credit balances.
     * B14 Skatteskulder: BAS maps nothing here, as the owner's own taxes are
     * private; a balance on 2500–2599 is shown here and warned about.
     *
     * @var array<int, list<array{0: int, 1: int}>>
     */
    public const array LIABILITIES = [
        7320 => [[2100, 2199]],
        7330 => [[2200, 2299]],
        7380 => [[2300, 2399], [2410, 2419], [2480, 2489]],
        7381 => [[2500, 2599]],
        7382 => [[2440, 2449], [2460, 2479]],
        7383 => [[2400, 2409], [2420, 2439], [2450, 2459], [2490, 2499], [2600, 2999]],
    ];

    /** B10 Eget kapital: the assets less the reserves and liabilities, as the form defines it. */
    public const int EQUITY = 7300;

    public const int TAX_DEBTS = 7381;

    /** R1 and R2: the same BAS ranges, split by whether the sale is exempt from VAT (ruta 42). */
    public const array SALES = [[3000, 3799], [3900, 3999]];

    public const int SALES_TAXABLE = 7400;

    public const int SALES_EXEMPT = 7401;

    /** R3 Bil- och bostadsförmån m.m.: no BAS account, entered. */
    public const int BENEFITS = 7402;

    public const int INTEREST_INCOME = 7403;

    public const int INTEREST_COSTS = 7503;

    /**
     * R4–R10 by range; costs are debit balances. The financial accounts
     * 8000–8899 count by the sign of each account's own result (BAS: "(+)"):
     * an income goes to R4, a cost to R8 (FINANCIAL).
     *
     * TODO(legal-verify): BAS's table puts 774x, 779x and 79xx in R8 and 885x
     * in both R9 and R10; 885x is put in R10 here, and the 77xx–78xx accounts
     * BAS does not list are put in R10.
     *
     * @var array<int, list<array{0: int, 1: int}>>
     */
    public const array RESULT = [
        7403 => [[3800, 3899]],
        7500 => [[4000, 4999]],
        7501 => [[5000, 6999]],
        7502 => [[7000, 7699]],
        7503 => [[7740, 7749], [7790, 7799], [7900, 7999]],
        7504 => [[7720, 7729], [7770, 7779], [7820, 7829], [7840, 7849]],
        7505 => [[7700, 7719], [7730, 7739], [7750, 7769], [7780, 7789], [7800, 7819], [7830, 7839], [7850, 7899], [8850, 8859]],
    ];

    /** The accounts counted by their own sign: income to R4, cost to R8. */
    public const array FINANCIAL = [[8000, 8849], [8860, 8989]];

    /** R11 Bokfört resultat and R12, the same amount carried to page 2. */
    public const int RESULT_BOOKED = 7440;

    public const int TAX_RESULT_START = 7600;

    /**
     * Tax adjustments a person enters (R13–R46), with the label and the
     * sign the amount has on the result. Labels as in the field table.
     *
     * @var array<int, array{0: string, 1: int}>
     */
    public const array ADJUSTMENTS = [
        7601 => ['Bokförda kostnader som inte ska dras av', 1],
        7700 => ['Bokförda intäkter som inte ska tas upp', -1],
        7602 => ['Intäkter som inte bokförts men som ska tas upp', 1],
        7701 => ['Kostnader som inte bokförts men som ska dras av', -1],
        7702 => ['Gemensam verksamhet: överskott redovisat av annan eller min andel av underskott', -1],
        7603 => ['Gemensam verksamhet: underskott redovisat av annan eller min andel av överskott', 1],
        7614 => ['Min andel som medhjälpande make', 1],
        7703 => ['Andel till medhjälpande make', -1],
        7704 => ['Övriga skattemässiga justeringar, kostnader', -1],
        7604 => ['Övriga skattemässiga justeringar, intäkter', 1],
        7705 => ['Outnyttjat underskott från föregående år (R48 förra året)', -1],
        7706 => ['Skogsavdrag eller substansminskningsavdrag enligt N8', -1],
        7605 => ['Återföring av värdeminskningsavdrag vid avyttring av näringsfastighet', 1],
        7606 => ['Uttag från skogs-, skogsskade- eller upphovsmannakonto', 1],
        7707 => ['Inbetalning till skogs-, skogsskade- eller upphovsmannakonto', -1],
        7608 => ['Återföring av periodiseringsfond', 1],
        7711 => ['Egna pensionspremier eller inbetalning på pensionssparkonto', -1],
        7712 => ['Särskild löneskatt på pensionssparavdraget', -1],
        7610 => ['Medgivna avdrag för egenavgifter och särskild löneskatt förra året (R43 förra året)', 1],
        7713 => ['Påförda egenavgifter och särskild löneskatt enligt slutskattebeskedet', -1],
        7611 => ['Sjukpenning som hör till näringsverksamheten', 1],
        7612 => ['Allmänt avdrag, utnyttjat underskott vid konstnärlig eller nystartad verksamhet', 1],
        7613 => ['Underskott som utnyttjas i kapital m.m.', 1],
    ];

    /** The codes in the order the form adds them up: R13–R28 (before räntefördelning). */
    public const array BEFORE_INTEREST = [7601, 7700, 7602, 7701, 7702, 7603, 7614, 7703, 7704, 7604, 7705, 7706, 7605, 7606, 7707];

    /** R38–R41: after the expansionsfond, before the egenavgifter. */
    public const array BEFORE_SELF_EMPLOYMENT = [7711, 7712, 7610, 7713];

    /** R44–R46: after the egenavgifter. */
    public const array AFTER_SELF_EMPLOYMENT = [7611, 7612, 7613];

    public const int POSITIVE_ALLOCATION = 7708;

    public const int NEGATIVE_ALLOCATION = 7607;

    public const int RESERVE_RETURN = 7608;

    public const int RESERVE_ALLOCATION = 7709;

    public const int EXPANSION_INCREASE = 7710;

    public const int EXPANSION_DECREASE = 7609;

    public const int SELF_EMPLOYMENT_DEDUCTION = 7714;

    public const int SURPLUS = 7630;

    public const int DEFICIT = 7730;

    /** Övriga uppgifter: kapitalunderlag and the saved fördelningsbelopp. */
    public const int CAPITAL_BASE_POSITIVE = 8009;

    public const int CAPITAL_BASE_NEGATIVE = 8010;

    public const int SAVED_ALLOCATION = 8011;

    public const int EXPANSION_CAPITAL_BASE = 8012;

    /** Övriga uppgifter a person enters (amounts). */
    public const array INFORMATION = [
        8002 => 'Bilkostnader för egen bil enligt schablon',
        8003 => 'Ränteutgifter enligt kontrolluppgifter som dragits av i näringsverksamheten',
        8004 => 'Återförda belopp vid restvärdesavskrivning',
        8006 => 'Värdeminskningsavdrag vid årets slut, byggnader',
        8007 => 'Värdeminskningsavdrag vid årets slut, markanläggningar',
    ];

    public const int ACTIVITY = 7020;

    public const int PASSIVE = 7021;

    public const int OUTSIDE_EEA = 7024;

    public const int NOT_SIMPLIFIED = 7023;

    public const int LEASED_CAR = 8000;

    public const int CONSULTANT_YES = 8046;

    public const int CONSULTANT_NO = 8047;

    /** Labels for the computed and booked fields, for the page. */
    public const array LABELS = [
        7020 => 'Verksamhetens art',
        7021 => 'Passiv näringsverksamhet',
        7023 => 'Har inte tillämpat reglerna om förenklat årsbokslut',
        7024 => 'Näringsverksamhet utanför EES',
        8000 => 'Leasad personbil eller lätt lastbil',
        8046 => 'Uppdragstagare har biträtt vid årsbokslutet: ja',
        8047 => 'Uppdragstagare har biträtt vid årsbokslutet: nej',
        7200 => 'B1 Immateriella anläggningstillgångar',
        7210 => 'B2 Byggnader och markanläggningar',
        7211 => 'B3 Mark och andra tillgångar som inte får skrivas av',
        7212 => 'B4 Maskiner och inventarier',
        7213 => 'B5 Övriga anläggningstillgångar',
        7240 => 'B6 Varulager',
        7250 => 'B7 Kundfordringar',
        7260 => 'B8 Övriga fordringar',
        7280 => 'B9 Kassa och bank',
        7300 => 'B10 Eget kapital',
        7320 => 'B11 Obeskattade reserver',
        7330 => 'B12 Avsättningar',
        7380 => 'B13 Låneskulder',
        7381 => 'B14 Skatteskulder',
        7382 => 'B15 Leverantörsskulder',
        7383 => 'B16 Övriga skulder',
        7400 => 'R1 Försäljning och övriga momspliktiga intäkter',
        7401 => 'R2 Momsfria intäkter',
        7402 => 'R3 Bil- och bostadsförmån m.m.',
        7403 => 'R4 Ränteintäkter m.m.',
        7500 => 'R5 Varor, material och tjänster',
        7501 => 'R6 Övriga externa kostnader',
        7502 => 'R7 Anställd personal',
        7503 => 'R8 Räntekostnader m.m.',
        7504 => 'R9 Av- och nedskrivningar byggnader och markanläggningar',
        7505 => 'R10 Av- och nedskrivningar maskiner, inventarier och immateriella tillgångar',
        7440 => 'R11 Bokfört resultat',
        7600 => 'R12 Bokfört resultat',
        7601 => 'R13 Bokförda kostnader som inte ska dras av',
        7700 => 'R14 Bokförda intäkter som inte ska tas upp',
        7602 => 'R15 Intäkter som inte bokförts men som ska tas upp',
        7701 => 'R16 Kostnader som inte bokförts men som ska dras av',
        7702 => 'R18 Gemensam verksamhet, överskott redovisat av annan',
        7603 => 'R19 Gemensam verksamhet, underskott redovisat av annan',
        7614 => 'R20 Min andel som medhjälpande make',
        7703 => 'R20 Andel till medhjälpande make',
        7704 => 'R22 Övriga skattemässiga justeringar, kostnader',
        7604 => 'R23 Övriga skattemässiga justeringar, intäkter',
        7705 => 'R24 Outnyttjat underskott från föregående år',
        7706 => 'R25 Skogsavdrag eller substansminskningsavdrag',
        7605 => 'R26 Återföring av värdeminskningsavdrag',
        7606 => 'R27 Uttag från skogs- eller upphovsmannakonto',
        7707 => 'R28 Inbetalning till skogs- eller upphovsmannakonto',
        7708 => 'R30 Positiv räntefördelning',
        7607 => 'R31 Negativ räntefördelning',
        7608 => 'R32 Återföring av periodiseringsfond',
        7709 => 'R34 Avsättning till periodiseringsfond',
        7710 => 'R36 Ökning av expansionsfond',
        7609 => 'R37 Minskning av expansionsfond',
        7711 => 'R38 Egna pensionspremier',
        7712 => 'R39 Särskild löneskatt på pensionssparavdrag',
        7610 => 'R40 Medgivna avdrag för egenavgifter förra året',
        7713 => 'R41 Påförda egenavgifter enligt slutskattebeskedet',
        7714 => 'R43 Årets beräknade avdrag för egenavgifter',
        7611 => 'R44 Sjukpenning',
        7612 => 'R45 Allmänt avdrag',
        7613 => 'R46 Underskott som utnyttjas i kapital',
        7630 => 'R47 Överskott',
        7730 => 'R48 Underskott',
        8002 => 'Bilkostnader för egen bil enligt schablon',
        8003 => 'Ränteutgifter enligt kontrolluppgifter',
        8004 => 'Återförda belopp vid restvärdesavskrivning',
        8006 => 'Värdeminskningsavdrag vid årets slut, byggnader',
        8007 => 'Värdeminskningsavdrag vid årets slut, markanläggningar',
        8009 => 'Kapitalunderlag för räntefördelning (positivt)',
        8010 => 'Kapitalunderlag för räntefördelning (negativt)',
        8011 => 'Positivt fördelningsbelopp som sparas',
        8012 => 'Kapitalunderlag för expansionsfond (positivt)',
    ];
}
