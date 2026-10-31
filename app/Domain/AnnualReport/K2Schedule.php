<?php

declare(strict_types=1);

namespace App\Domain\AnnualReport;

/**
 * The K2 layout of the income statement (kostnadsslagsindelad) and the
 * balance sheet, with the BAS accounts that make up each line. Every line
 * is an inclusive range of account numbers.
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

    /** @var list<array{key: string, label: string, section: string, from: int, to: int}> */
    public const array BALANCE = [
        ['key' => 'intangible', 'label' => 'Immateriella anläggningstillgångar', 'section' => 'fixed', 'from' => 1000, 'to' => 1099],
        ['key' => 'buildings', 'label' => 'Byggnader och mark', 'section' => 'fixed', 'from' => 1100, 'to' => 1199],
        ['key' => 'machinery', 'label' => 'Maskiner och inventarier', 'section' => 'fixed', 'from' => 1200, 'to' => 1299],
        ['key' => 'financial_fixed', 'label' => 'Finansiella anläggningstillgångar', 'section' => 'fixed', 'from' => 1300, 'to' => 1399],
        ['key' => 'inventory', 'label' => 'Varulager m.m.', 'section' => 'current', 'from' => 1400, 'to' => 1499],
        ['key' => 'trade_receivables', 'label' => 'Kundfordringar', 'section' => 'current', 'from' => 1500, 'to' => 1599],
        ['key' => 'other_receivables', 'label' => 'Övriga fordringar', 'section' => 'current', 'from' => 1600, 'to' => 1699],
        ['key' => 'prepaid', 'label' => 'Förutbetalda kostnader och upplupna intäkter', 'section' => 'current', 'from' => 1700, 'to' => 1799],
        ['key' => 'short_investments', 'label' => 'Kortfristiga placeringar', 'section' => 'current', 'from' => 1800, 'to' => 1899],
        ['key' => 'cash', 'label' => 'Kassa och bank', 'section' => 'current', 'from' => 1900, 'to' => 1999],
        ['key' => 'share_capital', 'label' => 'Aktiekapital', 'section' => 'restricted', 'from' => 2080, 'to' => 2084],
        ['key' => 'reserve', 'label' => 'Reservfond och annat bundet eget kapital', 'section' => 'restricted', 'from' => 2085, 'to' => 2089],
        ['key' => 'retained', 'label' => 'Balanserat resultat', 'section' => 'free', 'from' => 2090, 'to' => 2098],
        ['key' => 'untaxed_reserves', 'label' => 'Obeskattade reserver', 'section' => 'untaxed', 'from' => 2100, 'to' => 2199],
        ['key' => 'provisions', 'label' => 'Avsättningar', 'section' => 'provisions', 'from' => 2200, 'to' => 2299],
        ['key' => 'long_term', 'label' => 'Långfristiga skulder', 'section' => 'long_term', 'from' => 2300, 'to' => 2399],
        ['key' => 'credit_institutions', 'label' => 'Skulder till kreditinstitut', 'section' => 'short_term', 'from' => 2400, 'to' => 2439],
        ['key' => 'trade_payables', 'label' => 'Leverantörsskulder', 'section' => 'short_term', 'from' => 2440, 'to' => 2499],
        ['key' => 'tax_liabilities', 'label' => 'Skatteskulder', 'section' => 'short_term', 'from' => 2500, 'to' => 2599],
        ['key' => 'other_liabilities', 'label' => 'Övriga skulder', 'section' => 'short_term', 'from' => 2600, 'to' => 2899],
        ['key' => 'accrued', 'label' => 'Upplupna kostnader och förutbetalda intäkter', 'section' => 'short_term', 'from' => 2900, 'to' => 2999],
    ];

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
