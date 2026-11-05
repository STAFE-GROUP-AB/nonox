<?php

declare(strict_types=1);

namespace App\Domain\IncomeTax;

/**
 * The field codes of Inkomstdeklaration 4 for handelsbolag and
 * kommanditbolag (SKV 2004: INK4R, INK4S and INK4DU) and the BAS accounts
 * behind them (ADR-053). Codes from the field tables INK4R_SKV2004-33-02-26-01,
 * INK4S_SKV2004-33-01-26-06 and INK4DU_SKV2004-33-01-26-01 in "Nyheter from
 * beskattningsperiod 2026P4" (skatteverket.se). Several codes differ from
 * INK2R although the texts look alike, so nothing is shared with Ink2Codes.
 *
 * TODO(legal-verify): the ranges follow BAS's SRU coupling table for INK4
 * (INK4_P1 Intervall-241119, bas.se) for assets, equity and 2100–2399; the
 * short-term liabilities 7362–7371 follow the BAS groups and were not read
 * in that table.
 */
final class Ink4Codes
{
    public const array VERIFIED_PERIODS = ['2026P4'];

    /** @var array<int, list<array{0: int, 1: int}>> INK4R 2.1–2.18, debit balances */
    public const array ASSETS = [
        7201 => [[1000, 1087], [1089, 1099]],
        7202 => [[1088, 1088]],
        7214 => [[1100, 1119], [1130, 1179], [1190, 1199]],
        7215 => [[1200, 1279], [1290, 1299]],
        7216 => [[1120, 1129]],
        7217 => [[1180, 1189], [1280, 1289]],
        7236 => [[1310, 1319], [1330, 1339]],
        7232 => [[1320, 1329], [1340, 1349]],
        7234 => [[1360, 1369]],
        7237 => [[1300, 1309], [1350, 1359], [1370, 1399]],
        7247 => [[1400, 1469], [1490, 1499]],
        7245 => [[1470, 1479]],
        7246 => [[1480, 1489]],
        7253 => [[1500, 1559], [1580, 1599]],
        7264 => [[1560, 1579], [1600, 1699]],
        7263 => [[1700, 1799]],
        7272 => [[1800, 1899]],
        7281 => [[1900, 1999]],
    ];

    /** @var array<int, list<array{0: int, 1: int}>> INK4R 2.24–2.36, credit balances */
    public const array LIABILITIES = [
        7322 => [[2150, 2159]],
        7323 => [[2100, 2149], [2160, 2199]],
        7331 => [[2210, 2219]],
        7332 => [[2230, 2239]],
        7333 => [[2200, 2209], [2220, 2229], [2240, 2299]],
        7353 => [[2360, 2379]],
        7355 => [[2300, 2359], [2380, 2399]],
        7362 => [[2420, 2429]],
        7363 => [[2430, 2439]],
        7365 => [[2440, 2449]],
        7368 => [[2500, 2599]],
        7371 => [[2400, 2419], [2450, 2499], [2600, 2899]],
        7370 => [[2900, 2999]],
    ];

    /** INK4R 2.19–2.23: the partners' equity (BAS 2010–2049, one block of ten per partner). */
    public const int OPENING_CAPITAL = 7310;

    public const int NET_CONTRIBUTIONS = 7311;

    public const int EQUITY_PROFIT = 7312;

    public const int EQUITY_LOSS = 7313;

    public const int CLOSING_CAPITAL = 7314;

    public const array EQUITY = [2000, 2099];

    /**
     * INK4R 3.1–3.17, as Ink2Codes::INCOME: income code and cost code for a
     * credit or debit result of the ranges.
     *
     * @var list<array{ranges: list<array{0: int, 1: int}>, income: ?int, cost: ?int, resale?: 'include'|'exclude'}>
     */
    public const array INCOME = [
        ['ranges' => [[3000, 3799]], 'income' => 7410, 'cost' => null],
        ['ranges' => [[4940, 4959], [4970, 4979]], 'income' => 7411, 'cost' => 7510],
        ['ranges' => [[3800, 3899]], 'income' => 7412, 'cost' => null],
        ['ranges' => [[3900, 3999]], 'income' => 7413, 'cost' => null],
        ['ranges' => [[4000, 4939], [4980, 4999]], 'income' => null, 'cost' => 7511, 'resale' => 'exclude'],
        ['ranges' => [[4960, 4969]], 'income' => null, 'cost' => 7512, 'resale' => 'include'],
        ['ranges' => [[5000, 6999]], 'income' => null, 'cost' => 7513],
        ['ranges' => [[7000, 7699]], 'income' => null, 'cost' => 7514],
        ['ranges' => [[7700, 7899]], 'income' => null, 'cost' => 7515],
        ['ranges' => [[7900, 7999]], 'income' => null, 'cost' => 7535],
        ['ranges' => [[8000, 8099]], 'income' => 7414, 'cost' => 7518],
        ['ranges' => [[8100, 8199]], 'income' => 7415, 'cost' => 7519],
        ['ranges' => [[8200, 8299]], 'income' => 7416, 'cost' => 7520],
        ['ranges' => [[8300, 8399]], 'income' => 7417, 'cost' => null],
        ['ranges' => [[8400, 8799]], 'income' => null, 'cost' => 7522],
        ['ranges' => [[8800, 8899]], 'income' => 7427, 'cost' => 7537],
    ];

    /** A handelsbolag pays no income tax; 8900–8989 has no row on INK4R. */
    public const array TAX_ACCOUNTS = [8900, 8989];

    public const int PROFIT = 7450;

    public const int LOSS = 7550;

    /** INK4S. */
    public const int S_PROFIT = 7650;

    public const int S_LOSS = 7750;

    public const int S_SURPLUS = 7670;

    public const int S_DEFICIT = 7770;

    public const int S_PENSION_COSTS = 8022;

    public const array PENSION_ACCOUNTS = [7400, 7499];

    /** INK4S adjustments a person enters: label and sign on the result. */
    public const array ADJUSTMENTS = [
        7652 => ['4.3a Nedskrivning av finansiella tillgångar', 1],
        7653 => ['4.3b Andra bokförda kostnader som inte ska dras av', 1],
        7761 => ['4.4a Skogs- eller substansminskningsavdrag', -1],
        7764 => ['4.4b Andra ej bokförda kostnader som ska dras av', -1],
        7752 => ['4.5a Ackordsvinster', -1],
        7754 => ['4.5b Andra bokförda intäkter som inte ska tas upp', -1],
        7662 => ['4.6a Återföring av värdeminskningsavdrag vid avyttring av fastighet', 1],
        7668 => ['4.6b Schablonintäkt på fondandelar', 1],
        7665 => ['4.6c Andra ej bokförda intäkter som ska tas upp', 1],
        7766 => ['4.7 Bokförd intäkt av delägarnas uttag', -1],
        7741 => ['4.8a Andel i handelsbolag: bokförd intäkt eller vinst', -1],
        7642 => ['4.8b Andel i handelsbolag: skattemässigt överskott enligt N3B', 1],
        7641 => ['4.8c Andel i handelsbolag: bokförd kostnad eller förlust', 1],
        7742 => ['4.8d Andel i handelsbolag: skattemässigt underskott enligt N3B', -1],
        7666 => ['4.9 Justering för avskrivningar på byggnader m.m. (+)', 1],
        7765 => ['4.9 Justering för avskrivningar på byggnader m.m. (−)', -1],
        7767 => ['4.10a Avyttrad fastighet eller bostadsrätt: bokförd vinst', -1],
        7669 => ['4.10b Avyttrad fastighet eller bostadsrätt: bokförd förlust', 1],
        7768 => ['4.11a Avyttrade värdepapper: bokförd vinst', -1],
        7643 => ['4.11b Avyttrade värdepapper: bokförd förlust', 1],
    ];

    /** INK4S 4.14–4.16, amounts that do not change the result. */
    public const array INFORMATION = [
        8020 => '4.14 Ej återförda värdeminskningsavdrag, byggnader',
        8021 => '4.15 Ej återförda värdeminskningsavdrag, markanläggningar',
        8022 => '4.16 Pensionskostnader (som ingår i p. 3.8)',
    ];

    public const int CONSULTANT_YES = 8040;

    public const int CONSULTANT_NO = 8041;

    public const int AUDITED_YES = 8044;

    public const int AUDITED_NO = 8045;

    /** INK4DU, one block per partner. */
    public const int DU_PARTNER = 8100;

    public const int DU_SURPLUS = 8101;

    public const int DU_DEFICIT = 8102;

    public const int DU_CONTRIBUTIONS = 8103;

    public const int DU_WITHDRAWALS = 8104;

    /** Per partner block (BAS): uttag on x1–x3, tillskott on x7–x8 of 2010+10(n−1). */
    public const array WITHDRAWALS = [1, 3];

    public const array CONTRIBUTIONS = [7, 8];

    /** The BAS partner blocks 2010–2049: partner 1 is 2010–2019 … partner 4 is 2040–2049. */
    public const int MAX_PARTNERS_IN_BAS = 4;

    public const array LABELS = [
        7201 => '2.1 Koncessioner, patent, licenser, varumärken, goodwill m.m.',
        7202 => '2.2 Förskott avseende immateriella anläggningstillgångar',
        7214 => '2.3 Byggnader och mark',
        7215 => '2.4 Maskiner, inventarier och övriga materiella anläggningstillgångar',
        7216 => '2.5 Förbättringsutgifter på annans fastighet',
        7217 => '2.6 Pågående nyanläggningar och förskott',
        7236 => '2.7 Andelar i koncernföretag m.m.',
        7232 => '2.8 Fordringar hos koncernföretag m.m.',
        7234 => '2.9 Lån till delägare eller närstående',
        7237 => '2.10 Andra finansiella anläggningstillgångar',
        7247 => '2.11 Varulager',
        7245 => '2.12 Pågående arbeten för annans räkning',
        7246 => '2.13 Förskott till leverantörer',
        7253 => '2.14 Kundfordringar',
        7264 => '2.15 Övriga fordringar',
        7263 => '2.16 Förutbetalda kostnader och upplupna intäkter',
        7272 => '2.17 Kortfristiga placeringar',
        7281 => '2.18 Kassa, bank och redovisningsmedel',
        7310 => '2.19 Ingående kapital',
        7311 => '2.20 Delägarnas nettouttag (−) eller nettotillskott (+)',
        7312 => '2.21 Årets redovisade vinst',
        7313 => '2.22 Årets redovisade förlust',
        7314 => '2.23 Utgående kapital',
        7322 => '2.24 Ackumulerade överavskrivningar',
        7323 => '2.25 Övriga obeskattade reserver',
        7331 => '2.26 Avsättningar för pensioner enligt tryggandelagen',
        7332 => '2.27 Övriga avsättningar för pensioner',
        7333 => '2.28 Övriga avsättningar',
        7353 => '2.29 Skulder till koncernföretag m.m.',
        7355 => '2.30 Övriga skulder (långfristiga)',
        7362 => '2.31 Förskott från kunder',
        7363 => '2.32 Pågående arbeten för annans räkning',
        7365 => '2.33 Leverantörsskulder',
        7368 => '2.34 Skatteskulder',
        7371 => '2.35 Övriga skulder',
        7370 => '2.36 Upplupna kostnader och förutbetalda intäkter',
        7410 => '3.1 Nettoomsättning',
        7411 => '3.2 Förändring av lager (+)',
        7510 => '3.2 Förändring av lager (−)',
        7412 => '3.3 Aktiverat arbete för egen räkning',
        7413 => '3.4 Övriga rörelseintäkter',
        7511 => '3.5 Råvaror och förnödenheter',
        7512 => '3.6 Handelsvaror',
        7513 => '3.7 Övriga externa kostnader',
        7514 => '3.8 Personalkostnader',
        7515 => '3.9 Av- och nedskrivningar',
        7535 => '3.10 Övriga rörelsekostnader',
        7414 => '3.11 Resultat från andelar i koncernföretag (+)',
        7518 => '3.11 Resultat från andelar i koncernföretag (−)',
        7415 => '3.12 Resultat från andelar i intresseföretag m.m. (+)',
        7519 => '3.12 Resultat från andelar i intresseföretag m.m. (−)',
        7416 => '3.13 Resultat från övriga finansiella anläggningstillgångar (+)',
        7520 => '3.13 Resultat från övriga finansiella anläggningstillgångar (−)',
        7417 => '3.14 Övriga ränteintäkter',
        7522 => '3.16 Räntekostnader',
        7427 => '3.17 Bokslutsdispositioner (+)',
        7537 => '3.17 Bokslutsdispositioner (−)',
        7450 => '3.18 Årets resultat, vinst',
        7550 => '3.19 Årets resultat, förlust',
        7650 => '4.1 Årets resultat, vinst',
        7750 => '4.2 Årets resultat, förlust',
        7670 => '4.12 Överskott',
        7770 => '4.13 Underskott',
        8020 => '4.14 Ej återförda värdeminskningsavdrag, byggnader',
        8021 => '4.15 Ej återförda värdeminskningsavdrag, markanläggningar',
        8022 => '4.16 Pensionskostnader',
        8101 => 'Delägarens överskott',
        8102 => 'Delägarens underskott',
        8103 => 'Delägarens tillskott',
        8104 => 'Delägarens uttag',
    ];
}
