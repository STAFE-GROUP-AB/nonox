<?php

declare(strict_types=1);

namespace App\Domain\IncomeTax;

/**
 * The field codes (fältkoder) of INK2R and INK2S and the BAS accounts that
 * make up each one. The codes are Skatteverket's own, read from the field
 * tables INK2R_SKV2002-34-01-26-04 and INK2S_SKV2002-34-01-26-04 in
 * "Nyheter from beskattningsperiod 2026P4" (Teknisk information om
 * filöverföring, skatteverket.se); a period whose tables have not been
 * checked is refused.
 *
 * TODO(legal-verify): the BAS ranges behind each code follow the BAS
 * account groups and must be checked against the official BAS–INK2 mapping,
 * especially 4000–4999 (råvaror or handelsvaror), group items and
 * appropriations.
 */
final class Ink2Codes
{
    /** Beskattningsperioder whose field tables have been checked. */
    public const array VERIFIED_PERIODS = ['2026P4'];

    /**
     * Balance sheet (INK2R 2.1–2.50): code => list of [from, to]. Assets are
     * debit balances, equity and liabilities credit balances.
     *
     * @var array<int, list<array{0: int, 1: int}>>
     */
    public const array ASSETS = [
        7201 => [[1000, 1079], [1090, 1099]],
        7202 => [[1080, 1089]],
        7214 => [[1100, 1119], [1130, 1179], [1190, 1199]],
        7216 => [[1120, 1129]],
        7217 => [[1180, 1189], [1280, 1289]],
        7215 => [[1200, 1279], [1290, 1299]],
        7230 => [[1310, 1319]],
        7231 => [[1330, 1339]],
        7232 => [[1320, 1329], [1340, 1349]],
        7233 => [[1350, 1359]],
        7234 => [[1360, 1369]],
        7235 => [[1300, 1309], [1370, 1399]],
        7241 => [[1410, 1429]],
        7242 => [[1440, 1449]],
        7243 => [[1450, 1469]],
        7245 => [[1470, 1479]],
        7246 => [[1480, 1489]],
        7244 => [[1400, 1409], [1430, 1439], [1490, 1499]],
        7251 => [[1500, 1559], [1580, 1599]],
        7252 => [[1560, 1579], [1660, 1679]],
        7262 => [[1620, 1629]],
        7261 => [[1600, 1619], [1630, 1659], [1680, 1699]],
        7263 => [[1700, 1799]],
        7270 => [[1860, 1869]],
        7271 => [[1800, 1859], [1870, 1899]],
        7281 => [[1900, 1999]],
    ];

    /** @var array<int, list<array{0: int, 1: int}>> */
    public const array LIABILITIES = [
        7301 => [[2080, 2089]],
        7321 => [[2110, 2149]],
        7322 => [[2150, 2159]],
        7323 => [[2100, 2109], [2160, 2199]],
        7331 => [[2210, 2219]],
        7332 => [[2230, 2239]],
        7333 => [[2200, 2209], [2220, 2229], [2240, 2299]],
        7350 => [[2310, 2319]],
        7351 => [[2330, 2339]],
        7352 => [[2340, 2359]],
        7353 => [[2360, 2369]],
        7354 => [[2300, 2309], [2320, 2329], [2370, 2399]],
        7360 => [[2480, 2489]],
        7361 => [[2410, 2419]],
        7362 => [[2420, 2429]],
        7363 => [[2430, 2439]],
        7365 => [[2440, 2449]],
        7364 => [[2450, 2459]],
        7367 => [[2460, 2469], [2860, 2869]],
        7368 => [[2500, 2599]],
        7369 => [[2400, 2409], [2470, 2479], [2490, 2499], [2600, 2859], [2870, 2899]],
        7370 => [[2900, 2999]],
    ];

    /** Fritt eget kapital, including the year's result. */
    public const int FREE_EQUITY = 7302;

    /**
     * Income statement (INK2R 3.1–3.25): code for a credit (income) and for
     * a debit (cost) result of the range, each reported as a positive
     * amount. Null where the form has only one side.
     *
     * @var list<array{range: array{0: int, 1: int}, income: ?int, cost: ?int}>
     */
    public const array INCOME = [
        ['range' => [3000, 3799], 'income' => 7410, 'cost' => null],
        ['range' => [3800, 3899], 'income' => 7412, 'cost' => null],
        ['range' => [3900, 3999], 'income' => 7413, 'cost' => null],
        ['range' => [4000, 4999], 'income' => null, 'cost' => 7511],
        ['range' => [5000, 6999], 'income' => null, 'cost' => 7513],
        ['range' => [7000, 7699], 'income' => null, 'cost' => 7514],
        ['range' => [7700, 7899], 'income' => null, 'cost' => 7515],
        ['range' => [7900, 7999], 'income' => null, 'cost' => 7517],
        ['range' => [8000, 8099], 'income' => 7414, 'cost' => 7518],
        ['range' => [8100, 8199], 'income' => 7415, 'cost' => 7519],
        ['range' => [8200, 8299], 'income' => 7416, 'cost' => 7520],
        ['range' => [8300, 8399], 'income' => 7417, 'cost' => null],
        ['range' => [8400, 8799], 'income' => null, 'cost' => 7522],
        ['range' => [8810, 8819], 'income' => 7420, 'cost' => 7525],
        ['range' => [8820, 8829], 'income' => 7419, 'cost' => null],
        ['range' => [8830, 8839], 'income' => null, 'cost' => 7524],
        ['range' => [8850, 8859], 'income' => 7421, 'cost' => 7526],
        ['range' => [8800, 8809], 'income' => 7422, 'cost' => 7527],
        ['range' => [8840, 8849], 'income' => 7422, 'cost' => 7527],
        ['range' => [8860, 8899], 'income' => 7422, 'cost' => 7527],
        ['range' => [8900, 8989], 'income' => null, 'cost' => 7528],
    ];

    public const int PROFIT = 7450;

    public const int LOSS = 7550;

    /** INK2S: the adjustments a person enters, with their labels. Positive codes add to the taxable result, negative subtract. */
    public const array ADJUSTMENTS = [
        7653 => ['Andra bokförda kostnader som inte ska dras av', 1],
        7652 => ['Nedskrivning av finansiella tillgångar som inte ska dras av', 1],
        7753 => ['Utdelning som inte ska tas upp', -1],
        7754 => ['Andra bokförda intäkter som inte ska tas upp', -1],
        7654 => ['Beräknad schablonintäkt på periodiseringsfonder', 1],
        7665 => ['Andra ej bokförda intäkter som ska tas upp', 1],
        7764 => ['Andra ej bokförda kostnader som ska dras av', -1],
        7763 => ['Outnyttjat underskott från föregående år', -1],
    ];

    /**
     * Codes the system proposes from the books. An entered amount, 0
     * included, replaces the proposal; an empty field keeps it.
     */
    public const array PROPOSED = [self::S_STANDARD_INCOME, self::PENSION_BASIS, self::PENSION_BASIS_NEGATIVE, self::S_PENSION_COSTS];

    /**
     * Fields a person can enter that do not change the taxable result:
     * INK2 1.4/1.5, the basis for särskild löneskatt on pension costs
     * (lag 1991:687), and INK2S 4.21, the pension costs within 3.8. Field
     * codes from SKV's tables INK2_ and INK2S_SKV2002-34-01-26-04.
     *
     * @var array<int, string>
     */
    public const array INFORMATION = [
        self::PENSION_BASIS => 'Underlag för särskild löneskatt på pensionskostnader',
        self::PENSION_BASIS_NEGATIVE => 'Negativt underlag för särskild löneskatt på pensionskostnader',
        self::S_PENSION_COSTS => 'Pensionskostnader (som ingår i p. 3.8)',
    ];

    /** INK2 1.4 and 1.5: only one of them may be filled in (SKV field table). */
    public const int PENSION_BASIS = 7132;

    public const int PENSION_BASIS_NEGATIVE = 7133;

    /** INK2S 4.21. */
    public const int S_PENSION_COSTS = 8022;

    /** BAS 74xx Pensionskostnader, part of 7000–7699 (INK2R 3.8). */
    public const array PENSION_ACCOUNTS = [7400, 7499];

    /**
     * Every field a person can enter on the page, with its label.
     *
     * @return array<int, string>
     */
    public static function enteredLabels(): array
    {
        return array_map(fn (array $adjustment): string => $adjustment[0], self::ADJUSTMENTS) + self::INFORMATION;
    }

    /** INK2S 4.6 a: schablonintäkt på periodiseringsfonder (IL 30 kap. 6 a §). */
    public const int S_STANDARD_INCOME = 7654;

    /** INK2S 4.1/4.2 the book result, 4.3a the tax, 4.15/4.16 the taxable surplus or deficit. */
    public const int S_PROFIT = 7650;

    public const int S_LOSS = 7750;

    public const int S_TAX = 7651;

    public const int S_SURPLUS = 7670;

    public const int S_DEFICIT = 7770;

    /** INK2 1.1/1.2. */
    public const int SURPLUS = 7104;

    public const int DEFICIT = 7114;

    /** INK2S: a consultant helped with the annual report (yes/no), and it was audited (yes/no). */
    public const int CONSULTANT_YES = 8040;

    public const int CONSULTANT_NO = 8041;

    public const int AUDITED_YES = 8044;

    public const int AUDITED_NO = 8045;

    /** What each field code is, in the form's own words (shortened). */
    public const array LABELS = [
        7011 => 'Räkenskapsårets början',
        7012 => 'Räkenskapsårets slut',
        7201 => 'Koncessioner, patent, licenser, varumärken, goodwill m.m.',
        7202 => 'Förskott avseende immateriella anläggningstillgångar',
        7214 => 'Byggnader och mark',
        7215 => 'Maskiner, inventarier och övriga materiella anläggningstillgångar',
        7216 => 'Förbättringsutgifter på annans fastighet',
        7217 => 'Pågående nyanläggningar och förskott',
        7230 => 'Andelar i koncernföretag',
        7231 => 'Andelar i intresseföretag och gemensamt styrda företag',
        7232 => 'Fordringar hos koncern-, intresse- och gemensamt styrda företag',
        7233 => 'Ägarintressen i övriga företag och andra långfristiga värdepappersinnehav',
        7234 => 'Lån till delägare eller närstående',
        7235 => 'Andra långfristiga fordringar',
        7241 => 'Råvaror och förnödenheter',
        7242 => 'Varor under tillverkning',
        7243 => 'Färdiga varor och handelsvaror',
        7244 => 'Övriga lagertillgångar',
        7245 => 'Pågående arbeten för annans räkning',
        7246 => 'Förskott till leverantörer',
        7251 => 'Kundfordringar',
        7252 => 'Fordringar hos koncern-, intresse- och gemensamt styrda företag',
        7261 => 'Övriga fordringar',
        7262 => 'Upparbetad men ej fakturerad intäkt',
        7263 => 'Förutbetalda kostnader och upplupna intäkter',
        7270 => 'Kortfristiga placeringar, andelar i koncernföretag',
        7271 => 'Övriga kortfristiga placeringar',
        7281 => 'Kassa, bank och redovisningsmedel',
        7301 => 'Bundet eget kapital',
        7302 => 'Fritt eget kapital',
        7321 => 'Periodiseringsfonder',
        7322 => 'Ackumulerade överavskrivningar',
        7323 => 'Övriga obeskattade reserver',
        7331 => 'Avsättningar för pensioner enligt tryggandelagen',
        7332 => 'Övriga avsättningar för pensioner',
        7333 => 'Övriga avsättningar',
        7350 => 'Obligationslån',
        7351 => 'Kontokredit, långfristig',
        7352 => 'Övriga skulder till kreditinstitut, långfristiga',
        7353 => 'Skulder till koncern-, intresse- och gemensamt styrda företag, långfristiga',
        7354 => 'Övriga långfristiga skulder',
        7360 => 'Kontokredit, kortfristig',
        7361 => 'Övriga skulder till kreditinstitut, kortfristiga',
        7362 => 'Förskott från kunder',
        7363 => 'Pågående arbeten för annans räkning',
        7364 => 'Fakturerad men ej upparbetad intäkt',
        7365 => 'Leverantörsskulder',
        7367 => 'Skulder till koncern-, intresse- och gemensamt styrda företag, kortfristiga',
        7368 => 'Skatteskulder',
        7369 => 'Övriga kortfristiga skulder',
        7370 => 'Upplupna kostnader och förutbetalda intäkter',
        7410 => 'Nettoomsättning',
        7412 => 'Aktiverat arbete för egen räkning',
        7413 => 'Övriga rörelseintäkter',
        7414 => 'Resultat från andelar i koncernföretag (+)',
        7415 => 'Resultat från andelar i intresseföretag (+)',
        7416 => 'Resultat från övriga finansiella anläggningstillgångar (+)',
        7417 => 'Övriga ränteintäkter och liknande resultatposter',
        7419 => 'Mottagna koncernbidrag',
        7420 => 'Återföring av periodiseringsfond',
        7421 => 'Förändring av överavskrivningar (+)',
        7422 => 'Övriga bokslutsdispositioner (+)',
        7450 => 'Årets resultat, vinst',
        7511 => 'Råvaror och förnödenheter',
        7513 => 'Övriga externa kostnader',
        7514 => 'Personalkostnader',
        7515 => 'Av- och nedskrivningar av materiella och immateriella anläggningstillgångar',
        7517 => 'Övriga rörelsekostnader',
        7518 => 'Resultat från andelar i koncernföretag (−)',
        7519 => 'Resultat från andelar i intresseföretag (−)',
        7520 => 'Resultat från övriga finansiella anläggningstillgångar (−)',
        7522 => 'Räntekostnader och liknande resultatposter',
        7524 => 'Lämnade koncernbidrag',
        7525 => 'Avsättning till periodiseringsfond',
        7526 => 'Förändring av överavskrivningar (−)',
        7527 => 'Övriga bokslutsdispositioner (−)',
        7528 => 'Skatt på årets resultat',
        7550 => 'Årets resultat, förlust',
        7650 => 'Årets resultat, vinst',
        7750 => 'Årets resultat, förlust',
        7651 => 'Skatt på årets resultat',
        7652 => 'Nedskrivning av finansiella tillgångar',
        7653 => 'Andra bokförda kostnader som inte ska dras av',
        7753 => 'Utdelning som inte ska tas upp',
        7754 => 'Andra bokförda intäkter som inte ska tas upp',
        7654 => 'Beräknad schablonintäkt på periodiseringsfonder',
        7665 => 'Andra ej bokförda intäkter',
        7764 => 'Andra ej bokförda kostnader',
        7763 => 'Outnyttjat underskott från föregående år',
        7670 => 'Överskott',
        7770 => 'Underskott',
        8040 => 'Uppdragstagare har biträtt: ja',
        8041 => 'Uppdragstagare har biträtt: nej',
        8044 => 'Årsredovisningen har reviderats: ja',
        8045 => 'Årsredovisningen har reviderats: nej',
        7132 => 'Underlag för särskild löneskatt på pensionskostnader',
        7133 => 'Negativt underlag för särskild löneskatt på pensionskostnader',
        8022 => 'Pensionskostnader (som ingår i p. 3.8)',
        7104 => 'Överskott av näringsverksamhet',
        7114 => 'Underskott av näringsverksamhet',
    ];
}
