<?php

declare(strict_types=1);

namespace App\Domain\AnnualReport;

/**
 * The K3 layout of the income statement (kostnadsslagsindelad, ÅRL bilaga 2)
 * and the balance sheet (ÅRL bilaga 1) of an aktiebolag in its own annual
 * report (juridisk person), with the BAS accounts that make up each line: a
 * list of inclusive ranges of account numbers. The same shape as K2Schedule,
 * so AnnualAccounts and PrintedFigures read either (ADR-059 A.3, S1-P6).
 *
 * Sources, each read 2026-11-07:
 * - ÅRL (1995:1554) at SFS 2026:780, bilaga 1 (balance sheet, Lag 2024:347)
 *   and bilaga 2 (income statement, Lag 2015:813), quoted in K3 chapters 4
 *   and 5 (vl12-1-k3-kons20251215, pp. 43–52). K3 4.1/5.1 apply them; ÅRL 3 kap.
 *   4 § third paragraph allows added posts and delposter, K3 5.2 subtotals.
 * - K3 4.3 comment: "Utöver de poster som anges i uppställningsformen ska ett
 *   företag redovisa aktuella skattefordringar, uppskjutna skattefordringar och
 *   uppskjutna skatteskulder som egna poster"; K3 29.31: "En uppskjuten
 *   skattefordran eller uppskjuten skatteskuld ska klassificeras som
 *   finansiell anläggningstillgång respektive avsättning" (S1-R4.6).
 * - K3 4.7: liabilities "som långfristiga eller kortfristiga och redovisa dem
 *   under egna rubriker".
 * - ÅRL 3 kap. 10 a §: bound equity holds "aktiekapital, bunden överkursfond,
 *   uppskrivningsfond, reservfond, kapitalandelsfond och fond för
 *   utvecklingsutgifter" (S1-R10).
 * - The BAS ranges: the BAS 2026 reference linkbase of the Swedish taxonomy
 *   (se-gen-base-rbas2026-2021-10-31-ref-bas.xml, taxonomy package
 *   2026-08-31), which ties every concept of the K3 forms `fbs` and `fisbn`
 *   (se-k3-risbs 2021-10-31) to BAS accounts. "rbas" below names that file.
 *
 * Where rbas names no line for an account, the account keeps the line its
 * K2Schedule line corresponds to, and where K3 splits that K2 line, the split's
 * catch-all line ("Övriga …"). Every such placement is marked "residual" in
 * the line's source.
 * TODO(legal-verify): the residual placements, and that rbas2026 matches the
 * BAS 2026 chart (fixture F-S1-a assumption A12).
 *
 * Purchases (BAS 4000–4999) follow K2Schedule: Råvaror och förnödenheter
 * unless the account carries the SRU code for goods for resale. rbas instead
 * puts 40xx–42xx, 496x and 498x on Handelsvaror and 43xx–48xx, 491x–492x on
 * Råvaror och förnödenheter; BAS 4010 "Inköp material och varor" is both.
 * TODO(legal-verify): which split K3 practice expects; F-S1-a (4010 as
 * material) follows the K2 rule.
 *
 * Labels: where a line holds gains and losses (or exchange and value
 * changes) and not only income or costs, the label says "Resultat från …" or
 * "… liknande resultatposter" instead of the text of bilaga 2, as ÅRL 3 kap.
 * 4 § third paragraph allows ("Kompletterande poster ska ges beteckningar som
 * tydligt anger vad som ingår i posten"); each such line says so in its source.
 *
 * Bilaga 2 p. 14, 15 and 17 require a separate statement ("med särskild
 * uppgift om") of the amounts from or to koncernföretag. rbas has no concept
 * for it and the BAS accounts are not settled, so the schedule does not split
 * them: S1-P9/P10 must provide the delpost or a note.
 * TODO(legal-verify): the BAS accounts for those amounts (the accountant).
 *
 * Kapitalandelsfond (bilaga 1 A.IV.2) is left out on purpose: this schedule
 * is for the juridisk person's own report, and BAS 2026 (rbas) ties no
 * account to it.
 */
final class K3Schedule
{
    /** As K2Schedule: the purchase accounts, and the SRU code that moves one to Handelsvaror. */
    public const array PURCHASES = K2Schedule::PURCHASES;

    public const string GOODS_FOR_RESALE_SRU = K2Schedule::GOODS_FOR_RESALE_SRU;

    /**
     * The parts of the annual report in the order K3 3.10 gives (S1-R8.2):
     * "förvaltningsberättelse, resultaträkning, balansräkning, eventuell
     * kassaflödesanalys och noter. Har företaget valt att specificera årets
     * förändring av eget kapital i egen räkning placeras den efter
     * balansräkningen." NONOX prints the equity statement as its own report
     * (S1-P7), so it follows the balance sheet directly.
     *
     * @var list<string>
     */
    public const array PARTS = ['management_report', 'income_statement', 'balance_sheet', 'equity_statement', 'cash_flow_statement', 'notes'];

    /**
     * The income statement lines in the order of ÅRL bilaga 2 (S1-R8.2;
     * ÅRL 3 kap. 4 § first paragraph: "i den ordningsföljd som anges").
     *
     * @var list<array{key: string, label: string, ranges: list<array{0: int, 1: int}>, resale?: 'include'|'exclude', source: string}>
     */
    public const array INCOME = [
        ['key' => 'net_sales', 'label' => 'Nettoomsättning', 'ranges' => [[3000, 3799]], 'source' => 'ÅRL bilaga 2 p. 1; rbas 30xx–37xx'],
        ['key' => 'stock_change', 'label' => 'Förändring av varulager', 'ranges' => [[4940, 4959], [4970, 4979]], 'source' => 'ÅRL bilaga 2 p. 2; K3 5.4 comment; rbas 494x–495x, 497x'],
        ['key' => 'capitalised_work', 'label' => 'Aktiverat arbete för egen räkning', 'ranges' => [[3800, 3899]], 'source' => 'ÅRL bilaga 2 p. 3; rbas 38xx'],
        ['key' => 'other_operating_income', 'label' => 'Övriga rörelseintäkter', 'ranges' => [[3900, 3999]], 'source' => 'ÅRL bilaga 2 p. 4; rbas 39xx'],
        ['key' => 'raw_materials', 'label' => 'Råvaror och förnödenheter', 'ranges' => [[4000, 4939], [4980, 4999]], 'resale' => 'exclude', 'source' => 'ÅRL bilaga 2 p. 5; purchases as K2Schedule (see class comment)'],
        ['key' => 'goods_for_resale', 'label' => 'Handelsvaror', 'ranges' => [[4960, 4969]], 'resale' => 'include', 'source' => 'ÅRL 3 kap. 4 § third paragraph (added post); taxonomy HandelsvarorKostnader; rbas 496x; purchases as K2Schedule'],
        ['key' => 'other_external', 'label' => 'Övriga externa kostnader', 'ranges' => [[5000, 6999]], 'source' => 'ÅRL bilaga 2 p. 6; rbas 50xx–69xx'],
        ['key' => 'personnel', 'label' => 'Personalkostnader', 'ranges' => [[7000, 7699]], 'source' => 'ÅRL bilaga 2 p. 7; rbas 70xx–76xx'],
        ['key' => 'depreciation', 'label' => 'Avskrivningar och nedskrivningar av materiella och immateriella anläggningstillgångar', 'ranges' => [[7700, 7739], [7750, 7789], [7800, 7899]], 'source' => 'ÅRL bilaga 2 p. 8; rbas 771x–773x, 776x–778x, 78xx; residual 770x, 775x'],
        ['key' => 'current_asset_write_downs', 'label' => 'Nedskrivningar av omsättningstillgångar utöver normala nedskrivningar', 'ranges' => [[7740, 7749], [7790, 7799]], 'source' => 'ÅRL bilaga 2 p. 9; rbas 774x, 779x'],
        ['key' => 'other_operating_expenses', 'label' => 'Övriga rörelsekostnader', 'ranges' => [[7900, 7999]], 'source' => 'ÅRL bilaga 2 p. 10; rbas 79xx'],
        ['key' => 'group_shares', 'label' => 'Resultat från andelar i koncernföretag', 'ranges' => [[8000, 8069], [8090, 8099]], 'source' => 'ÅRL bilaga 2 p. 11 ("Intäkter från andelar i koncernföretag"; label differs from bilaga 2 text (3:4 tredje stycket): the line holds gains and losses); rbas 801x–803x; residual 800x, 804x–806x, 809x'],
        ['key' => 'associated_shares', 'label' => 'Resultat från andelar i intresseföretag och gemensamt styrda företag', 'ranges' => [[8100, 8112], [8114, 8117], [8119, 8122], [8124, 8132], [8134, 8169], [8190, 8199]], 'source' => 'ÅRL bilaga 2 p. 12 ("Intäkter från andelar i intresseföretag …"; label differs from bilaga 2 text (3:4 tredje stycket): gains and losses); rbas 8111–8112, 8116–8117, 8121–8122, 8131–8132; residual other 81xx outside 817x–818x'],
        ['key' => 'ownership_interests', 'label' => 'Resultat från övriga företag som det finns ett ägarintresse i', 'ranges' => [[8113, 8113], [8118, 8118], [8123, 8123], [8133, 8133]], 'source' => 'ÅRL bilaga 2 p. 13 ("Intäkter från övriga företag …"; label differs from bilaga 2 text (3:4 tredje stycket): gains and losses); rbas 8113, 8118, 8123, 8133'],
        ['key' => 'other_financial_assets', 'label' => 'Resultat från övriga värdepapper och fordringar som är anläggningstillgångar', 'ranges' => [[8200, 8269], [8290, 8299]], 'source' => 'ÅRL bilaga 2 p. 14 ("Intäkter från övriga värdepapper …"; label differs from bilaga 2 text (3:4 tredje stycket): gains and losses); rbas 821x–826x, 829x; residual 820x; TODO(legal-verify): "med särskild uppgift om" koncernföretag; no rbas concept, BAS accounts to be named by the accountant; delpost or note in S1-P9/P10'],
        ['key' => 'interest_income', 'label' => 'Övriga ränteintäkter och liknande resultatposter', 'ranges' => [[8300, 8369], [8390, 8399]], 'source' => 'ÅRL bilaga 2 p. 15 ("Övriga ränteintäkter och liknande intäkter"; label differs from bilaga 2 text (3:4 tredje stycket): includes exchange and value changes); rbas 831x–836x, 839x; residual 830x; TODO(legal-verify): "med särskild uppgift om" koncernföretag; no rbas concept, BAS accounts to be named by the accountant; delpost or note in S1-P9/P10'],
        ['key' => 'financial_write_downs', 'label' => 'Nedskrivningar av finansiella anläggningstillgångar och kortfristiga placeringar', 'ranges' => [[8070, 8089], [8170, 8189], [8270, 8289], [8370, 8389]], 'source' => 'ÅRL bilaga 2 p. 16; rbas 807x–808x, 817x–818x, 827x–828x, 837x–838x'],
        ['key' => 'interest_expense', 'label' => 'Räntekostnader och liknande resultatposter', 'ranges' => [[8400, 8799]], 'source' => 'ÅRL bilaga 2 p. 17 ("Räntekostnader och liknande kostnader"; label differs from bilaga 2 text (3:4 tredje stycket): includes exchange and value changes); rbas 84xx; residual 85xx–87xx; TODO(legal-verify): "med särskild uppgift om" koncernföretag; no rbas concept, BAS accounts to be named by the accountant; delpost or note in S1-P9/P10'],
        ['key' => 'appropriations', 'label' => 'Bokslutsdispositioner', 'ranges' => [[8800, 8899]], 'source' => 'ÅRL bilaga 2 p. 18; K3 29.37 (gross in juridisk person); rbas 88xx'],
        ['key' => 'tax', 'label' => 'Skatt på årets resultat', 'ranges' => [[8900, 8979]], 'source' => 'ÅRL bilaga 2 p. 19; K3 29.22 (deferred tax in the income statement); rbas 891x–894x; residual 890x, 895x–897x'],
        ['key' => 'other_taxes', 'label' => 'Övriga skatter', 'ranges' => [[8980, 8989]], 'source' => 'ÅRL bilaga 2 p. 20; rbas 898x'],
    ];

    /**
     * Subtotals after a line: the label and the lines they add up. K3 5.2:
     * "Ett företag får lägga till underrubriker och delresultat"; its comment
     * names Rörelseresultat and Resultat efter finansiella poster. Årets
     * resultat is ÅRL bilaga 2 p. 21.
     */
    public const array INCOME_SUBTOTALS = [
        'other_operating_expenses' => ['key' => 'operating_result', 'label' => 'Rörelseresultat', 'lines' => ['net_sales', 'stock_change', 'capitalised_work', 'other_operating_income', 'raw_materials', 'goods_for_resale', 'other_external', 'personnel', 'depreciation', 'current_asset_write_downs', 'other_operating_expenses']],
        'interest_expense' => ['key' => 'after_financial', 'label' => 'Resultat efter finansiella poster', 'lines' => ['operating_result', 'group_shares', 'associated_shares', 'ownership_interests', 'other_financial_assets', 'interest_income', 'financial_write_downs', 'interest_expense']],
        'appropriations' => ['key' => 'before_tax', 'label' => 'Resultat före skatt', 'lines' => ['after_financial', 'appropriations']],
        'other_taxes' => ['key' => 'year_result', 'label' => 'Årets resultat', 'lines' => ['before_tax', 'tax', 'other_taxes']],
    ];

    /**
     * The balance sheet lines in the order of ÅRL bilaga 1 (S1-R8.2), the
     * equity of an aktiebolag split into bound and free as ÅRL 3 kap. 10 a §
     * allows (K3 chapter 4 comment on the juridisk person).
     *
     * @var list<array{key: string, label: string, section: string, ranges: list<array{0: int, 1: int}>, source: string}>
     */
    public const array BALANCE = [
        ['key' => 'subscribed_capital', 'label' => 'Tecknat men ej inbetalt kapital', 'section' => 'subscribed', 'ranges' => [[1690, 1699]], 'source' => 'ÅRL bilaga 1 Tillgångar A; rbas 169x'],

        ['key' => 'development_expenditure', 'label' => 'Balanserade utgifter för utvecklingsarbeten och liknande arbeten', 'section' => 'intangible', 'ranges' => [[1010, 1019], [1081, 1081]], 'source' => 'ÅRL bilaga 1 B.I.1; rbas 101x, 1081'],
        ['key' => 'concessions', 'label' => 'Koncessioner, patent, licenser, varumärken samt liknande rättigheter', 'section' => 'intangible', 'ranges' => [[1000, 1009], [1020, 1059], [1090, 1099]], 'source' => 'ÅRL bilaga 1 B.I.2; rbas 102x–105x; residual 100x, 109x'],
        ['key' => 'leaseholds', 'label' => 'Hyresrätter och liknande rättigheter', 'section' => 'intangible', 'ranges' => [[1060, 1069]], 'source' => 'ÅRL bilaga 1 B.I.3; rbas 106x'],
        ['key' => 'goodwill', 'label' => 'Goodwill', 'section' => 'intangible', 'ranges' => [[1070, 1079]], 'source' => 'ÅRL bilaga 1 B.I.4; rbas 107x'],
        ['key' => 'intangible_advances', 'label' => 'Förskott avseende immateriella anläggningstillgångar', 'section' => 'intangible', 'ranges' => [[1080, 1080], [1082, 1089]], 'source' => 'ÅRL bilaga 1 B.I.5; rbas 108x except 1081, which rbas also ties to B.I.1 (BalanseradeUtgifterUtvecklingsarbetenLiknandeArbeten), where it is kept'],

        ['key' => 'buildings', 'label' => 'Byggnader och mark', 'section' => 'tangible', 'ranges' => [[1100, 1119], [1130, 1179], [1190, 1199]], 'source' => 'ÅRL bilaga 1 B.II.1; rbas 111x, 113x–115x; residual 110x, 116x–117x, 119x'],
        ['key' => 'machinery', 'label' => 'Maskiner och andra tekniska anläggningar', 'section' => 'tangible', 'ranges' => [[1200, 1219], [1230, 1249], [1270, 1279]], 'source' => 'ÅRL bilaga 1 B.II.2; rbas 121x, 123x–124x; residual 120x, 127x'],
        ['key' => 'equipment', 'label' => 'Inventarier, verktyg och installationer', 'section' => 'tangible', 'ranges' => [[1220, 1229], [1250, 1269]], 'source' => 'ÅRL bilaga 1 B.II.3; rbas 122x, 125x–126x'],
        ['key' => 'improvements', 'label' => 'Förbättringsutgifter på annans fastighet', 'section' => 'tangible', 'ranges' => [[1120, 1129]], 'source' => 'ÅRL 3 kap. 4 § third paragraph (added post); taxonomy ForbattringsutgifterAnnansFastighet; rbas 112x'],
        ['key' => 'construction_in_progress', 'label' => 'Pågående nyanläggningar och förskott avseende materiella anläggningstillgångar', 'section' => 'tangible', 'ranges' => [[1180, 1189], [1280, 1289]], 'source' => 'ÅRL bilaga 1 B.II.4; rbas 118x, 128x'],
        ['key' => 'other_tangible', 'label' => 'Övriga materiella anläggningstillgångar', 'section' => 'tangible', 'ranges' => [[1290, 1299]], 'source' => 'ÅRL 3 kap. 4 § third paragraph (added post); taxonomy OvrigaMateriellaAnlaggningstillgangar; rbas 129x'],

        ['key' => 'group_shares', 'label' => 'Andelar i koncernföretag', 'section' => 'financial', 'ranges' => [[1310, 1319]], 'source' => 'ÅRL bilaga 1 B.III.1; rbas 131x'],
        ['key' => 'group_receivables_long', 'label' => 'Fordringar hos koncernföretag', 'section' => 'financial', 'ranges' => [[1320, 1329]], 'source' => 'ÅRL bilaga 1 B.III.2; rbas 132x'],
        ['key' => 'associated_shares', 'label' => 'Andelar i intresseföretag och gemensamt styrda företag', 'section' => 'financial', 'ranges' => [[1330, 1335], [1338, 1339]], 'source' => 'ÅRL bilaga 1 B.III.3; rbas 1331–1334; residual 1330, 1335, 1338–1339'],
        ['key' => 'associated_receivables_long', 'label' => 'Fordringar hos intresseföretag och gemensamt styrda företag', 'section' => 'financial', 'ranges' => [[1340, 1345], [1348, 1349]], 'source' => 'ÅRL bilaga 1 B.III.4; rbas 1341–1344; residual 1340, 1345, 1348–1349'],
        ['key' => 'ownership_interests', 'label' => 'Ägarintressen i övriga företag', 'section' => 'financial', 'ranges' => [[1336, 1337]], 'source' => 'ÅRL bilaga 1 B.III.5; rbas 1336–1337'],
        ['key' => 'ownership_receivables_long', 'label' => 'Fordringar hos övriga företag som det finns ett ägarintresse i', 'section' => 'financial', 'ranges' => [[1346, 1347]], 'source' => 'ÅRL bilaga 1 B.III.6; rbas 1346–1347'],
        ['key' => 'long_securities', 'label' => 'Andra långfristiga värdepappersinnehav', 'section' => 'financial', 'ranges' => [[1350, 1359]], 'source' => 'ÅRL bilaga 1 B.III.7; rbas 135x'],
        ['key' => 'shareholder_loans', 'label' => 'Lån till delägare eller närstående', 'section' => 'financial', 'ranges' => [[1360, 1369]], 'source' => 'ÅRL bilaga 1 B.III.8 (label shortened as the taxonomy\'s LanDelagareNarstaende); rbas 136x'],
        ['key' => 'deferred_tax_asset', 'label' => 'Uppskjuten skattefordran', 'section' => 'financial', 'ranges' => [[1370, 1379]], 'source' => 'K3 29.19 (own post), 29.31 (financial fixed asset), 4.3 comment; rbas 137x (S1-R4.6)'],
        ['key' => 'other_long_receivables', 'label' => 'Andra långfristiga fordringar', 'section' => 'financial', 'ranges' => [[1300, 1309], [1380, 1399]], 'source' => 'ÅRL bilaga 1 B.III.9; rbas 138x; residual 130x, 139x'],

        ['key' => 'raw_materials_stock', 'label' => 'Råvaror och förnödenheter', 'section' => 'inventory', 'ranges' => [[1400, 1439]], 'source' => 'ÅRL bilaga 1 C.I.1; rbas 141x–142x; residual 140x, 143x'],
        ['key' => 'work_in_progress_stock', 'label' => 'Varor under tillverkning', 'section' => 'inventory', 'ranges' => [[1440, 1449]], 'source' => 'ÅRL bilaga 1 C.I.2; rbas 144x'],
        ['key' => 'finished_goods', 'label' => 'Färdiga varor och handelsvaror', 'section' => 'inventory', 'ranges' => [[1450, 1469]], 'source' => 'ÅRL bilaga 1 C.I.3; rbas 145x–146x'],
        ['key' => 'contracts_in_progress', 'label' => 'Pågående arbete för annans räkning', 'section' => 'inventory', 'ranges' => [[1470, 1479]], 'source' => 'ÅRL bilaga 1 C.I.4; rbas 147x'],
        ['key' => 'supplier_advances', 'label' => 'Förskott till leverantörer', 'section' => 'inventory', 'ranges' => [[1480, 1489]], 'source' => 'ÅRL bilaga 1 C.I.5; rbas 148x'],
        ['key' => 'other_inventory', 'label' => 'Övriga lagertillgångar', 'section' => 'inventory', 'ranges' => [[1490, 1499]], 'source' => 'ÅRL 3 kap. 4 § third paragraph (added post); taxonomy OvrigaLagertillgangar; rbas 149x'],

        ['key' => 'trade_receivables', 'label' => 'Kundfordringar', 'section' => 'receivables', 'ranges' => [[1500, 1559]], 'source' => 'ÅRL bilaga 1 C.II.1; rbas 151x–153x, 155x; residual 150x, 154x'],
        ['key' => 'group_receivables', 'label' => 'Fordringar hos koncernföretag', 'section' => 'receivables', 'ranges' => [[1560, 1569], [1660, 1669]], 'source' => 'ÅRL bilaga 1 C.II.2; rbas 156x, 166x'],
        ['key' => 'associated_receivables', 'label' => 'Fordringar hos intresseföretag och gemensamt styrda företag', 'section' => 'receivables', 'ranges' => [[1571, 1572], [1671, 1672]], 'source' => 'ÅRL bilaga 1 C.II.3; rbas 1571–1572, 1671–1672'],
        ['key' => 'ownership_receivables', 'label' => 'Fordringar hos övriga företag som det finns ett ägarintresse i', 'section' => 'receivables', 'ranges' => [[1573, 1573], [1673, 1673]], 'source' => 'ÅRL bilaga 1 C.II.4; rbas 1573, 1673'],
        ['key' => 'current_tax_assets', 'label' => 'Aktuella skattefordringar', 'section' => 'receivables', 'ranges' => [[1640, 1649]], 'source' => 'K3 4.3 comment ("aktuella skattefordringar … som egna poster"); rbas 164x'],
        ['key' => 'other_receivables', 'label' => 'Övriga fordringar', 'section' => 'receivables', 'ranges' => [[1570, 1570], [1574, 1619], [1630, 1639], [1650, 1659], [1670, 1670], [1674, 1689]], 'source' => 'ÅRL bilaga 1 C.II.5; rbas 161x, 163x, 165x, 168x; residual 1570, 1574–1599, 1670, 1674–1679'],
        ['key' => 'earned_not_invoiced', 'label' => 'Upparbetad men ej fakturerad intäkt', 'section' => 'receivables', 'ranges' => [[1620, 1629]], 'source' => 'ÅRL 3 kap. 4 § third paragraph (added post); taxonomy UpparbetadEjFaktureradIntakt; rbas 162x'],
        ['key' => 'prepaid', 'label' => 'Förutbetalda kostnader och upplupna intäkter', 'section' => 'receivables', 'ranges' => [[1700, 1799]], 'source' => 'ÅRL bilaga 1 C.II.6; rbas 17xx'],

        ['key' => 'group_short_shares', 'label' => 'Andelar i koncernföretag', 'section' => 'short_investments', 'ranges' => [[1860, 1869]], 'source' => 'ÅRL bilaga 1 C.III.1; rbas 186x'],
        ['key' => 'short_investments', 'label' => 'Övriga kortfristiga placeringar', 'section' => 'short_investments', 'ranges' => [[1800, 1859], [1870, 1899]], 'source' => 'ÅRL bilaga 1 C.III.2; rbas 181x–183x, 188x–189x; residual 180x, 184x–185x, 187x'],

        ['key' => 'cash', 'label' => 'Kassa och bank', 'section' => 'cash', 'ranges' => [[1900, 1999]], 'source' => 'ÅRL bilaga 1 C.IV; rbas 191x–199x'],

        ['key' => 'share_capital', 'label' => 'Aktiekapital', 'section' => 'restricted', 'ranges' => [[2081, 2081]], 'source' => 'ÅRL bilaga 1 EK A.I; 3 kap. 10 a §; rbas 2081'],
        ['key' => 'unregistered_share_capital', 'label' => 'Ej registrerat aktiekapital', 'section' => 'restricted', 'ranges' => [[2082, 2082]], 'source' => 'ÅRL 3 kap. 4 § third paragraph (delpost of A.I); taxonomy EjRegistreratAktiekapital; rbas 2082'],
        ['key' => 'bound_share_premium', 'label' => 'Bunden överkursfond', 'section' => 'restricted', 'ranges' => [[2087, 2087]], 'source' => 'ÅRL bilaga 1 EK A.II.1; 3 kap. 10 a §; rbas 2087'],
        ['key' => 'revaluation_reserve', 'label' => 'Uppskrivningsfond', 'section' => 'restricted', 'ranges' => [[2085, 2085]], 'source' => 'ÅRL bilaga 1 EK A.III; 3 kap. 10 a §; rbas 2085'],
        ['key' => 'reserve', 'label' => 'Reservfond', 'section' => 'restricted', 'ranges' => [[2086, 2086]], 'source' => 'ÅRL bilaga 1 EK A.IV.1; 3 kap. 10 a §; rbas 2086'],
        ['key' => 'development_fund', 'label' => 'Fond för utvecklingsutgifter', 'section' => 'restricted', 'ranges' => [[2089, 2089]], 'source' => 'ÅRL bilaga 1 EK A.IV.4; 3 kap. 10 a §; 4 kap. 2 § second paragraph; K3 18.3A; rbas 2089 (S1-R10; account to be confirmed by human decision H9)'],
        ['key' => 'other_restricted', 'label' => 'Övrigt bundet eget kapital', 'section' => 'restricted', 'ranges' => [[2080, 2080], [2083, 2084], [2088, 2088]], 'source' => 'ÅRL bilaga 1 EK A.IV.5 ("Övrigt"); residual 2080, 2083–2084, 2088 (K2Schedule: bound equity)'],

        ['key' => 'free_share_premium', 'label' => 'Fri överkursfond', 'section' => 'free', 'ranges' => [[2097, 2097]], 'source' => 'ÅRL bilaga 1 EK A.II.2; 3 kap. 10 a §; rbas 2097'],
        ['key' => 'fair_value_reserve', 'label' => 'Fond för verkligt värde', 'section' => 'free', 'ranges' => [[2096, 2096]], 'source' => 'ÅRL bilaga 1 EK A.IV.3; rbas 2096 (also listed under Balanserat resultat; kept here)'],
        ['key' => 'retained', 'label' => 'Balanserad vinst eller förlust', 'section' => 'free', 'ranges' => [[2090, 2095], [2098, 2098]], 'source' => 'ÅRL bilaga 1 EK A.V; rbas 2091, 2093–2095, 2098; residual 2090, 2092 (2099 is the year\'s result, AnnualAccounts)'],

        ['key' => 'tax_allocation_reserves', 'label' => 'Periodiseringsfonder', 'section' => 'untaxed', 'ranges' => [[2110, 2139]], 'source' => 'ÅRL bilaga 1 B (delpost, 3 kap. 4 § third paragraph); K3 29.37 (gross); rbas 211x–213x'],
        ['key' => 'accumulated_over_depreciation', 'label' => 'Ackumulerade överavskrivningar', 'section' => 'untaxed', 'ranges' => [[2150, 2159]], 'source' => 'ÅRL bilaga 1 B (delpost); K3 29.37; rbas 215x'],
        ['key' => 'replacement_reserves', 'label' => 'Ersättningsfonder', 'section' => 'untaxed', 'ranges' => [[2160, 2169]], 'source' => 'ÅRL bilaga 1 B (delpost); K3 29.37; rbas 216x'],
        ['key' => 'other_untaxed_reserves', 'label' => 'Andra obeskattade reserver', 'section' => 'untaxed', 'ranges' => [[2100, 2109], [2140, 2149], [2170, 2199]], 'source' => 'ÅRL bilaga 1 B (delpost); K3 29.37; rbas 219x; residual 210x, 214x, 217x–218x'],

        ['key' => 'pension_provisions', 'label' => 'Avsättningar för pensioner och liknande förpliktelser', 'section' => 'provisions', 'ranges' => [[2210, 2219], [2230, 2239]], 'source' => 'ÅRL bilaga 1 C.1; rbas 221x, 223x'],
        ['key' => 'deferred_tax_liability', 'label' => 'Uppskjuten skatteskuld', 'section' => 'provisions', 'ranges' => [[2240, 2249]], 'source' => 'ÅRL bilaga 1 C.2; K3 29.19 (own post), 29.31 (avsättning), 4.3 comment; rbas 224x (S1-R4.6)'],
        ['key' => 'other_tax_provisions', 'label' => 'Övriga avsättningar för skatter', 'section' => 'provisions', 'ranges' => [[2250, 2259]], 'source' => 'ÅRL bilaga 1 C.2 ("Avsättningar för skatter", the part that is not deferred tax); rbas 225x (AvsattningarOvrigSkatt)'],
        ['key' => 'other_provisions', 'label' => 'Övriga avsättningar', 'section' => 'provisions', 'ranges' => [[2200, 2209], [2220, 2229], [2260, 2299]], 'source' => 'ÅRL bilaga 1 C.3; rbas 222x, 229x; residual 220x, 226x–228x'],

        ['key' => 'bonds', 'label' => 'Obligationslån', 'section' => 'long_term', 'ranges' => [[2310, 2329]], 'source' => 'ÅRL bilaga 1 D.1; K3 4.7; rbas 231x–232x'],
        ['key' => 'long_overdraft', 'label' => 'Checkräkningskredit', 'section' => 'long_term', 'ranges' => [[2330, 2339]], 'source' => 'ÅRL bilaga 1 D.2 (delpost); K3 4.7; rbas 233x'],
        ['key' => 'long_credit_institutions', 'label' => 'Övriga skulder till kreditinstitut', 'section' => 'long_term', 'ranges' => [[2340, 2359]], 'source' => 'ÅRL bilaga 1 D.2 (delpost); K3 4.7; rbas 234x–235x'],
        ['key' => 'long_group', 'label' => 'Skulder till koncernföretag', 'section' => 'long_term', 'ranges' => [[2360, 2369]], 'source' => 'ÅRL bilaga 1 D.6; K3 4.7; rbas 236x'],
        ['key' => 'long_associated', 'label' => 'Skulder till intresseföretag och gemensamt styrda företag', 'section' => 'long_term', 'ranges' => [[2371, 2372]], 'source' => 'ÅRL bilaga 1 D.7; K3 4.7; rbas 2371–2372'],
        ['key' => 'long_ownership', 'label' => 'Skulder till övriga företag som det finns ett ägarintresse i', 'section' => 'long_term', 'ranges' => [[2373, 2373]], 'source' => 'ÅRL bilaga 1 D.8; K3 4.7; rbas 2373'],
        ['key' => 'long_other', 'label' => 'Övriga skulder', 'section' => 'long_term', 'ranges' => [[2300, 2309], [2370, 2370], [2374, 2399]], 'source' => 'ÅRL bilaga 1 D.10; K3 4.7; rbas 239x; residual 230x, 2370, 2374–2389'],

        ['key' => 'short_bonds', 'label' => 'Obligationslån', 'section' => 'short_term', 'ranges' => [[2841, 2841]], 'source' => 'ÅRL bilaga 1 D.1; K3 4.7; rbas 2841'],
        ['key' => 'overdraft', 'label' => 'Checkräkningskredit', 'section' => 'short_term', 'ranges' => [[2480, 2489]], 'source' => 'ÅRL bilaga 1 D.2 (delpost); K3 4.7; rbas 248x'],
        ['key' => 'credit_institutions', 'label' => 'Övriga skulder till kreditinstitut', 'section' => 'short_term', 'ranges' => [[2410, 2419]], 'source' => 'ÅRL bilaga 1 D.2 (delpost); K3 4.7; rbas 241x'],
        ['key' => 'customer_advances', 'label' => 'Förskott från kunder', 'section' => 'short_term', 'ranges' => [[2420, 2429]], 'source' => 'ÅRL bilaga 1 D.3; rbas 242x'],
        ['key' => 'work_in_progress', 'label' => 'Pågående arbete för annans räkning', 'section' => 'short_term', 'ranges' => [[2430, 2439]], 'source' => 'ÅRL 3 kap. 4 § third paragraph (added post); taxonomy PagaendeArbetenAnnansRakningKortfristigaSkulder; rbas 243x'],
        ['key' => 'trade_payables', 'label' => 'Leverantörsskulder', 'section' => 'short_term', 'ranges' => [[2440, 2449]], 'source' => 'ÅRL bilaga 1 D.4; rbas 244x'],
        ['key' => 'bills_payable', 'label' => 'Växelskulder', 'section' => 'short_term', 'ranges' => [[2492, 2492]], 'source' => 'ÅRL bilaga 1 D.5; rbas 2492'],
        ['key' => 'group_liabilities', 'label' => 'Skulder till koncernföretag', 'section' => 'short_term', 'ranges' => [[2460, 2469], [2860, 2869]], 'source' => 'ÅRL bilaga 1 D.6; rbas 246x, 286x'],
        ['key' => 'associated_liabilities', 'label' => 'Skulder till intresseföretag och gemensamt styrda företag', 'section' => 'short_term', 'ranges' => [[2471, 2472], [2871, 2872]], 'source' => 'ÅRL bilaga 1 D.7; rbas 2471–2472, 2871–2872'],
        ['key' => 'ownership_liabilities', 'label' => 'Skulder till övriga företag som det finns ett ägarintresse i', 'section' => 'short_term', 'ranges' => [[2473, 2473], [2873, 2873]], 'source' => 'ÅRL bilaga 1 D.8; rbas 2473, 2873'],
        ['key' => 'tax_liabilities', 'label' => 'Skatteskulder', 'section' => 'short_term', 'ranges' => [[2500, 2599]], 'source' => 'ÅRL bilaga 1 D.9; rbas 251x; residual 250x, 252x–259x (K2Schedule: Skatteskulder)'],
        ['key' => 'other_liabilities', 'label' => 'Övriga skulder', 'section' => 'short_term', 'ranges' => [[2400, 2409], [2470, 2470], [2474, 2479], [2490, 2491], [2493, 2499], [2600, 2840], [2842, 2859], [2870, 2870], [2874, 2899]], 'source' => 'ÅRL bilaga 1 D.10; rbas 2490–2491, 2493–2499, 26xx, 27xx, 281x–285x, 288x–289x; residual 240x, 2470, 2474–2479, 2800–2809, 2870, 2874–2879'],
        ['key' => 'invoiced_not_earned', 'label' => 'Fakturerad men ej upparbetad intäkt', 'section' => 'short_term', 'ranges' => [[2450, 2459]], 'source' => 'ÅRL 3 kap. 4 § third paragraph (added post); taxonomy FaktureradEjUpparbetadIntakt; rbas 245x'],
        ['key' => 'accrued', 'label' => 'Upplupna kostnader och förutbetalda intäkter', 'section' => 'short_term', 'ranges' => [[2900, 2999]], 'source' => 'ÅRL bilaga 1 D.11; rbas 29xx'],
    ];

    /** The balance sheet lines with fixed assets that get a note (ÅRL 5 kap. 8 §): ÅRL bilaga 1 B.I and B.II. */
    public const array FIXED_ASSET_LINES = [
        'development_expenditure', 'concessions', 'leaseholds', 'goodwill', 'intangible_advances',
        'buildings', 'machinery', 'equipment', 'improvements', 'construction_in_progress', 'other_tangible',
    ];

    /**
     * Headings of the balance sheet sections, in order: the roman-numeral
     * headings of ÅRL bilaga 1 on the asset side; bound and free equity
     * (ÅRL 3 kap. 10 a §), obeskattade reserver and avsättningar (bilaga 1 B
     * and C), long- and short-term liabilities under their own headings
     * (K3 4.7). The equity and liability keys are K2Schedule's.
     */
    public const array SECTIONS = [
        'subscribed' => 'Tecknat men ej inbetalt kapital',
        'intangible' => 'Immateriella anläggningstillgångar',
        'tangible' => 'Materiella anläggningstillgångar',
        'financial' => 'Finansiella anläggningstillgångar',
        'inventory' => 'Varulager m.m.',
        'receivables' => 'Fordringar',
        'short_investments' => 'Kortfristiga placeringar',
        'cash' => 'Kassa och bank',
        'restricted' => 'Bundet eget kapital',
        'free' => 'Fritt eget kapital',
        'untaxed' => 'Obeskattade reserver',
        'provisions' => 'Avsättningar',
        'long_term' => 'Långfristiga skulder',
        'short_term' => 'Kortfristiga skulder',
    ];

    public const array ASSET_SECTIONS = ['subscribed', 'intangible', 'tangible', 'financial', 'inventory', 'receivables', 'short_investments', 'cash'];

    /**
     * The letter headings of ÅRL bilaga 1 above the sections: Anläggningstillgångar
     * (B), Omsättningstillgångar (C) and Eget kapital (A on the other side).
     *
     * @var array<string, array{label: string, sections: list<string>}>
     */
    public const array GROUPS = [
        'fixed' => ['label' => 'Anläggningstillgångar', 'sections' => ['intangible', 'tangible', 'financial']],
        'current' => ['label' => 'Omsättningstillgångar', 'sections' => ['inventory', 'receivables', 'short_investments', 'cash']],
        'equity' => ['label' => 'Eget kapital', 'sections' => ['restricted', 'free']],
    ];

    /**
     * The rows of the equity specification that take what rounding leaves
     * over in a column, in order. K3 6.3 lists the rows a specification shows
     * and gives no residual row (S1-R8.3), so PrintedFigures never adds an
     * "Övriga förändringar" row for K3: the leftover goes to an existing row
     * with an amount in the column (ADR-059 A.3). The keys are
     * K3EquityStatement's rows (S1-P7).
     *
     * @var list<string>
     */
    public const array EQUITY_ROUNDING_ROWS = ['prior_result_transfer', 'dividend'];

    public const bool EQUITY_ROUNDING_ADDS_ROW = false;

    /** PrintedFigures refuses a K3 balance sheet whose sides differ by more than rounding (UnbalancedBalanceSheet). */
    public const bool REFUSES_UNBALANCED_SHEET = true;

    /**
     * The sums of the GROUPS from a balance sheet's sections (AnnualAccounts
     * or PrintedFigures): each is the sum of its sections, so the printed
     * group sums are sums of rounded lines too.
     *
     * @param  array{sections: list<array{key: string, amount: int, previous: int}>}  $sheet
     * @return array<string, array{label: string, amount: int, previous: int}>
     */
    public static function groupTotals(array $sheet): array
    {
        $totals = [];

        foreach (self::GROUPS as $key => $group) {
            $sections = array_filter($sheet['sections'], fn (array $section): bool => in_array($section['key'], $group['sections'], true));
            $totals[$key] = [
                'label' => $group['label'],
                'amount' => array_sum(array_column($sections, 'amount')),
                'previous' => array_sum(array_column($sections, 'previous')),
            ];
        }

        return $totals;
    }
}
