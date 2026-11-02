<?php

declare(strict_types=1);

namespace App\Domain\AnnualReport;

/**
 * The K2 layout of the income statement (kostnadsslagsindelad) and the
 * balance sheet, with the BAS accounts that make up each line: a list of
 * inclusive ranges of account numbers.
 *
 * TODO(legal-verify): check the line set against ÅRL bilaga 2 and 3 and
 * BFNAR 2016:10, and the BAS ranges against the official BAS–K2 mapping,
 * especially 4000–4999 (raw materials versus goods for resale and stock
 * changes) and 8000–8799.
 */
final class K2Schedule
{
    /** @var list<array{key: string, label: string, from: int, to: int}> */
    public const array INCOME = [
        ['key' => 'net_sales', 'label' => 'Nettoomsättning', 'from' => 3000, 'to' => 3799],
        ['key' => 'capitalised_work', 'label' => 'Aktiverat arbete för egen räkning', 'from' => 3800, 'to' => 3899],
        ['key' => 'other_operating_income', 'label' => 'Övriga rörelseintäkter', 'from' => 3900, 'to' => 3999],
        ['key' => 'raw_materials', 'label' => 'Råvaror och förnödenheter', 'from' => 4000, 'to' => 4999],
        ['key' => 'other_external', 'label' => 'Övriga externa kostnader', 'from' => 5000, 'to' => 6999],
        ['key' => 'personnel', 'label' => 'Personalkostnader', 'from' => 7000, 'to' => 7699],
        ['key' => 'depreciation', 'label' => 'Av- och nedskrivningar av materiella och immateriella anläggningstillgångar', 'from' => 7700, 'to' => 7899],
        ['key' => 'other_operating_expenses', 'label' => 'Övriga rörelsekostnader', 'from' => 7900, 'to' => 7999],
        ['key' => 'group_shares', 'label' => 'Resultat från andelar i koncernföretag', 'from' => 8000, 'to' => 8099],
        ['key' => 'associated_shares', 'label' => 'Resultat från andelar i intresseföretag och gemensamt styrda företag', 'from' => 8100, 'to' => 8199],
        ['key' => 'other_financial_assets', 'label' => 'Resultat från övriga finansiella anläggningstillgångar', 'from' => 8200, 'to' => 8299],
        ['key' => 'interest_income', 'label' => 'Övriga ränteintäkter och liknande resultatposter', 'from' => 8300, 'to' => 8399],
        ['key' => 'interest_expense', 'label' => 'Räntekostnader och liknande resultatposter', 'from' => 8400, 'to' => 8799],
        ['key' => 'appropriations', 'label' => 'Bokslutsdispositioner', 'from' => 8800, 'to' => 8899],
        ['key' => 'tax', 'label' => 'Skatt på årets resultat', 'from' => 8900, 'to' => 8989],
    ];

    /** Subtotals after a line: the label and the lines they add up. */
    public const array INCOME_SUBTOTALS = [
        'other_operating_expenses' => ['key' => 'operating_result', 'label' => 'Rörelseresultat', 'lines' => ['net_sales', 'capitalised_work', 'other_operating_income', 'raw_materials', 'other_external', 'personnel', 'depreciation', 'other_operating_expenses']],
        'interest_expense' => ['key' => 'after_financial', 'label' => 'Resultat efter finansiella poster', 'lines' => ['operating_result', 'group_shares', 'associated_shares', 'other_financial_assets', 'interest_income', 'interest_expense']],
        'appropriations' => ['key' => 'before_tax', 'label' => 'Resultat före skatt', 'lines' => ['after_financial', 'appropriations']],
        'tax' => ['key' => 'year_result', 'label' => 'Årets resultat', 'lines' => ['before_tax', 'tax']],
    ];

    /**
     * The balance sheet lines in the order of the K2 layout (BFNAR 2016:10
     * kap. 4, uppställningsform balansräkning; ÅRL bilaga 1), with the same
     * BAS split as the INK2R field codes in Ink2Codes. Checkräkningskredit,
     * förskott från kunder and leverantörsskulder are separate posts (#88).
     *
     * @var list<array{key: string, label: string, section: string, ranges: list<array{0: int, 1: int}>}>
     */
    public const array BALANCE = [
        ['key' => 'intangible', 'label' => 'Immateriella anläggningstillgångar', 'section' => 'fixed', 'ranges' => [[1000, 1099]]],
        ['key' => 'buildings', 'label' => 'Byggnader och mark', 'section' => 'fixed', 'ranges' => [[1100, 1119], [1130, 1179], [1190, 1199]]],
        ['key' => 'machinery', 'label' => 'Maskiner och inventarier', 'section' => 'fixed', 'ranges' => [[1200, 1279], [1290, 1299]]],
        ['key' => 'improvements', 'label' => 'Förbättringsutgifter på annans fastighet', 'section' => 'fixed', 'ranges' => [[1120, 1129]]],
        ['key' => 'construction_in_progress', 'label' => 'Pågående nyanläggningar och förskott avseende materiella anläggningstillgångar', 'section' => 'fixed', 'ranges' => [[1180, 1189], [1280, 1289]]],
        ['key' => 'financial_fixed', 'label' => 'Finansiella anläggningstillgångar', 'section' => 'fixed', 'ranges' => [[1300, 1399]]],
        ['key' => 'inventory', 'label' => 'Varulager m.m.', 'section' => 'current', 'ranges' => [[1400, 1499]]],
        ['key' => 'trade_receivables', 'label' => 'Kundfordringar', 'section' => 'current', 'ranges' => [[1500, 1599]]],
        ['key' => 'other_receivables', 'label' => 'Övriga fordringar', 'section' => 'current', 'ranges' => [[1600, 1699]]],
        ['key' => 'prepaid', 'label' => 'Förutbetalda kostnader och upplupna intäkter', 'section' => 'current', 'ranges' => [[1700, 1799]]],
        ['key' => 'short_investments', 'label' => 'Kortfristiga placeringar', 'section' => 'current', 'ranges' => [[1800, 1899]]],
        ['key' => 'cash', 'label' => 'Kassa och bank', 'section' => 'current', 'ranges' => [[1900, 1999]]],
        ['key' => 'share_capital', 'label' => 'Aktiekapital', 'section' => 'restricted', 'ranges' => [[2080, 2084]]],
        ['key' => 'reserve', 'label' => 'Reservfond och annat bundet eget kapital', 'section' => 'restricted', 'ranges' => [[2085, 2089]]],
        ['key' => 'retained', 'label' => 'Balanserat resultat', 'section' => 'free', 'ranges' => [[2090, 2098]]],
        ['key' => 'untaxed_reserves', 'label' => 'Obeskattade reserver', 'section' => 'untaxed', 'ranges' => [[2100, 2199]]],
        ['key' => 'provisions', 'label' => 'Avsättningar', 'section' => 'provisions', 'ranges' => [[2200, 2299]]],
        ['key' => 'bonds', 'label' => 'Obligationslån', 'section' => 'long_term', 'ranges' => [[2310, 2319]]],
        ['key' => 'long_overdraft', 'label' => 'Checkräkningskredit', 'section' => 'long_term', 'ranges' => [[2330, 2339]]],
        ['key' => 'long_credit_institutions', 'label' => 'Övriga skulder till kreditinstitut', 'section' => 'long_term', 'ranges' => [[2340, 2359]]],
        ['key' => 'long_group', 'label' => 'Skulder till koncern-, intresse- och gemensamt styrda företag', 'section' => 'long_term', 'ranges' => [[2360, 2369]]],
        ['key' => 'long_other', 'label' => 'Övriga skulder', 'section' => 'long_term', 'ranges' => [[2300, 2309], [2320, 2329], [2370, 2399]]],
        ['key' => 'overdraft', 'label' => 'Checkräkningskredit', 'section' => 'short_term', 'ranges' => [[2480, 2489]]],
        ['key' => 'credit_institutions', 'label' => 'Övriga skulder till kreditinstitut', 'section' => 'short_term', 'ranges' => [[2410, 2419]]],
        ['key' => 'customer_advances', 'label' => 'Förskott från kunder', 'section' => 'short_term', 'ranges' => [[2420, 2429]]],
        ['key' => 'work_in_progress', 'label' => 'Pågående arbete för annans räkning', 'section' => 'short_term', 'ranges' => [[2430, 2439]]],
        ['key' => 'invoiced_not_earned', 'label' => 'Fakturerad men ej upparbetad intäkt', 'section' => 'short_term', 'ranges' => [[2450, 2459]]],
        ['key' => 'trade_payables', 'label' => 'Leverantörsskulder', 'section' => 'short_term', 'ranges' => [[2440, 2449]]],
        ['key' => 'group_liabilities', 'label' => 'Skulder till koncern-, intresse- och gemensamt styrda företag', 'section' => 'short_term', 'ranges' => [[2460, 2469], [2860, 2869]]],
        ['key' => 'tax_liabilities', 'label' => 'Skatteskulder', 'section' => 'short_term', 'ranges' => [[2500, 2599]]],
        ['key' => 'other_liabilities', 'label' => 'Övriga skulder', 'section' => 'short_term', 'ranges' => [[2400, 2409], [2470, 2479], [2490, 2499], [2600, 2859], [2870, 2899]]],
        ['key' => 'accrued', 'label' => 'Upplupna kostnader och förutbetalda intäkter', 'section' => 'short_term', 'ranges' => [[2900, 2999]]],
    ];

    /** The balance sheet lines with fixed assets that get a note (ÅRL 5 kap. 8 §). */
    public const array FIXED_ASSET_LINES = ['intangible', 'buildings', 'machinery', 'improvements', 'construction_in_progress'];

    /** Headings of the balance sheet sections, in order. */
    public const array SECTIONS = [
        'fixed' => 'Anläggningstillgångar',
        'current' => 'Omsättningstillgångar',
        'restricted' => 'Bundet eget kapital',
        'free' => 'Fritt eget kapital',
        'untaxed' => 'Obeskattade reserver',
        'provisions' => 'Avsättningar',
        'long_term' => 'Långfristiga skulder',
        'short_term' => 'Kortfristiga skulder',
    ];

    public const array ASSET_SECTIONS = ['fixed', 'current'];
}
