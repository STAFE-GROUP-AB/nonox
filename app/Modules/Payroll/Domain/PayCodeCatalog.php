<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

/**
 * The system pay codes seeded when payroll is turned on (PAYROLL_MODULE.md
 * B.5), with default BAS accounts (B.12) for staff under collective
 * agreements (kollektivanställda, 70xx), the common case in restaurants.
 * The account mapping is editable per company.
 */
final class PayCodeCatalog
{
    /**
     * @var array<string, array{name_sv: string, name_en: string, category: string, unit: string, taxable: bool, tax_method: string, contribution: bool, vacation: bool, pension: bool, debit: ?int, credit: ?int}>
     */
    public const array CODES = [
        'MANADSLON' => ['name_sv' => 'Månadslön', 'name_en' => 'Monthly salary', 'category' => 'SALARY', 'unit' => 'AMOUNT', 'taxable' => true, 'tax_method' => 'TABLE', 'contribution' => true, 'vacation' => true, 'pension' => true, 'debit' => 7010, 'credit' => null],
        'TIMLON' => ['name_sv' => 'Timlön', 'name_en' => 'Hourly pay', 'category' => 'SALARY', 'unit' => 'HOURS', 'taxable' => true, 'tax_method' => 'TABLE', 'contribution' => true, 'vacation' => true, 'pension' => true, 'debit' => 7010, 'credit' => null],
        'OVERTID' => ['name_sv' => 'Övertid', 'name_en' => 'Overtime', 'category' => 'ADDITION', 'unit' => 'HOURS', 'taxable' => true, 'tax_method' => 'TABLE', 'contribution' => true, 'vacation' => false, 'pension' => true, 'debit' => 7010, 'credit' => null],
        'OBTILLAGG' => ['name_sv' => 'OB-tillägg', 'name_en' => 'Unsocial hours supplement', 'category' => 'ADDITION', 'unit' => 'HOURS', 'taxable' => true, 'tax_method' => 'TABLE', 'contribution' => true, 'vacation' => true, 'pension' => true, 'debit' => 7010, 'credit' => null],
        'BONUS' => ['name_sv' => 'Bonus', 'name_en' => 'Bonus', 'category' => 'ADDITION', 'unit' => 'AMOUNT', 'taxable' => true, 'tax_method' => 'ONE_OFF', 'contribution' => true, 'vacation' => false, 'pension' => true, 'debit' => 7010, 'credit' => null],
        'SJUKLON' => ['name_sv' => 'Sjuklön', 'name_en' => 'Sick pay', 'category' => 'SICK', 'unit' => 'HOURS', 'taxable' => true, 'tax_method' => 'TABLE', 'contribution' => true, 'vacation' => true, 'pension' => true, 'debit' => 7081, 'credit' => null],
        'SJUKAVDRAG' => ['name_sv' => 'Sjukavdrag', 'name_en' => 'Sick deduction', 'category' => 'SICK', 'unit' => 'HOURS', 'taxable' => true, 'tax_method' => 'TABLE', 'contribution' => true, 'vacation' => true, 'pension' => true, 'debit' => 7010, 'credit' => null],
        'KARENSAVDRAG' => ['name_sv' => 'Karensavdrag', 'name_en' => 'Qualifying-day deduction', 'category' => 'SICK', 'unit' => 'AMOUNT', 'taxable' => true, 'tax_method' => 'TABLE', 'contribution' => true, 'vacation' => true, 'pension' => true, 'debit' => 7081, 'credit' => null],
        'FRANVAROAVDRAG' => ['name_sv' => 'Avdrag för frånvaro', 'name_en' => 'Absence deduction', 'category' => 'ABSENCE', 'unit' => 'HOURS', 'taxable' => true, 'tax_method' => 'TABLE', 'contribution' => true, 'vacation' => false, 'pension' => true, 'debit' => 7010, 'credit' => null],
        'SEMESTERLON' => ['name_sv' => 'Semesterlön', 'name_en' => 'Vacation pay', 'category' => 'VACATION', 'unit' => 'DAYS', 'taxable' => true, 'tax_method' => 'TABLE', 'contribution' => true, 'vacation' => false, 'pension' => true, 'debit' => 7082, 'credit' => null],
        'SEMESTERTILLAGG' => ['name_sv' => 'Semestertillägg', 'name_en' => 'Vacation supplement', 'category' => 'VACATION', 'unit' => 'DAYS', 'taxable' => true, 'tax_method' => 'TABLE', 'contribution' => true, 'vacation' => false, 'pension' => true, 'debit' => 7082, 'credit' => null],
        'SEMESTERAVDRAG' => ['name_sv' => 'Löneavdrag för semester', 'name_en' => 'Salary deduction for vacation', 'category' => 'VACATION', 'unit' => 'HOURS', 'taxable' => true, 'tax_method' => 'TABLE', 'contribution' => true, 'vacation' => false, 'pension' => true, 'debit' => 7010, 'credit' => null],
        'OBETALD_SEMESTER' => ['name_sv' => 'Obetald semester', 'name_en' => 'Unpaid vacation', 'category' => 'ABSENCE', 'unit' => 'HOURS', 'taxable' => true, 'tax_method' => 'TABLE', 'contribution' => true, 'vacation' => false, 'pension' => true, 'debit' => 7010, 'credit' => null],
        'SEMESTERERSATTNING' => ['name_sv' => 'Semesterersättning', 'name_en' => 'Vacation compensation', 'category' => 'VACATION', 'unit' => 'AMOUNT', 'taxable' => true, 'tax_method' => 'TABLE', 'contribution' => true, 'vacation' => false, 'pension' => true, 'debit' => 7082, 'credit' => null],
        'KOSTFORMAN_HEL' => ['name_sv' => 'Kostförmån, hel dag', 'name_en' => 'Meal benefit, full day', 'category' => 'BENEFIT', 'unit' => 'QUANTITY', 'taxable' => true, 'tax_method' => 'TABLE', 'contribution' => true, 'vacation' => false, 'pension' => false, 'debit' => null, 'credit' => null],
        'KOSTFORMAN_LUNCH' => ['name_sv' => 'Kostförmån, lunch eller middag', 'name_en' => 'Meal benefit, lunch or dinner', 'category' => 'BENEFIT', 'unit' => 'QUANTITY', 'taxable' => true, 'tax_method' => 'TABLE', 'contribution' => true, 'vacation' => false, 'pension' => false, 'debit' => null, 'credit' => null],
        'KOSTFORMAN_FRUKOST' => ['name_sv' => 'Kostförmån, frukost', 'name_en' => 'Meal benefit, breakfast', 'category' => 'BENEFIT', 'unit' => 'QUANTITY', 'taxable' => true, 'tax_method' => 'TABLE', 'contribution' => true, 'vacation' => false, 'pension' => false, 'debit' => null, 'credit' => null],
        'BILFORMAN' => ['name_sv' => 'Bilförmån', 'name_en' => 'Car benefit', 'category' => 'BENEFIT', 'unit' => 'AMOUNT', 'taxable' => true, 'tax_method' => 'TABLE', 'contribution' => true, 'vacation' => false, 'pension' => false, 'debit' => null, 'credit' => null],
        'DRIVMEDELSFORMAN' => ['name_sv' => 'Drivmedelsförmån', 'name_en' => 'Fuel benefit', 'category' => 'BENEFIT', 'unit' => 'AMOUNT', 'taxable' => true, 'tax_method' => 'TABLE', 'contribution' => true, 'vacation' => false, 'pension' => false, 'debit' => null, 'credit' => null],
        'OVRIG_FORMAN' => ['name_sv' => 'Övrig förmån', 'name_en' => 'Other benefit', 'category' => 'BENEFIT', 'unit' => 'AMOUNT', 'taxable' => true, 'tax_method' => 'TABLE', 'contribution' => true, 'vacation' => false, 'pension' => false, 'debit' => null, 'credit' => null],
        'FORMAN_EGENBETALNING' => ['name_sv' => 'Egen betalning för förmån', 'name_en' => 'Employee payment for benefit', 'category' => 'NET_DEDUCTION', 'unit' => 'AMOUNT', 'taxable' => false, 'tax_method' => 'NONE', 'contribution' => false, 'vacation' => false, 'pension' => false, 'debit' => null, 'credit' => 2890],
        'MILERSATTNING' => ['name_sv' => 'Milersättning, egen bil', 'name_en' => 'Mileage allowance, own car', 'category' => 'ALLOWANCE_TAX_FREE', 'unit' => 'QUANTITY', 'taxable' => false, 'tax_method' => 'NONE', 'contribution' => false, 'vacation' => false, 'pension' => false, 'debit' => 7331, 'credit' => null],
        'MIL_SKATTEPLIKTIG' => ['name_sv' => 'Milersättning, skattepliktig del', 'name_en' => 'Mileage allowance, taxable part', 'category' => 'ALLOWANCE_TAXABLE', 'unit' => 'QUANTITY', 'taxable' => true, 'tax_method' => 'TABLE', 'contribution' => true, 'vacation' => false, 'pension' => false, 'debit' => 7332, 'credit' => null],
        'TRAKTAMENTE_HEL' => ['name_sv' => 'Traktamente, hel dag', 'name_en' => 'Per diem, full day', 'category' => 'ALLOWANCE_TAX_FREE', 'unit' => 'DAYS', 'taxable' => false, 'tax_method' => 'NONE', 'contribution' => false, 'vacation' => false, 'pension' => false, 'debit' => 7321, 'credit' => null],
        'TRAKTAMENTE_HALV' => ['name_sv' => 'Traktamente, halv dag', 'name_en' => 'Per diem, half day', 'category' => 'ALLOWANCE_TAX_FREE', 'unit' => 'DAYS', 'taxable' => false, 'tax_method' => 'NONE', 'contribution' => false, 'vacation' => false, 'pension' => false, 'debit' => 7321, 'credit' => null],
        'TRAKTAMENTE_NATT' => ['name_sv' => 'Nattraktamente', 'name_en' => 'Night allowance', 'category' => 'ALLOWANCE_TAX_FREE', 'unit' => 'DAYS', 'taxable' => false, 'tax_method' => 'NONE', 'contribution' => false, 'vacation' => false, 'pension' => false, 'debit' => 7321, 'credit' => null],
        'TRAKT_SKATTEPLIKTIG' => ['name_sv' => 'Traktamente, skattepliktig del', 'name_en' => 'Per diem, taxable part', 'category' => 'ALLOWANCE_TAXABLE', 'unit' => 'DAYS', 'taxable' => true, 'tax_method' => 'TABLE', 'contribution' => true, 'vacation' => false, 'pension' => false, 'debit' => 7322, 'credit' => null],
        'UTLAGG' => ['name_sv' => 'Utlägg', 'name_en' => 'Outlay repayment', 'category' => 'EXPENSE', 'unit' => 'AMOUNT', 'taxable' => false, 'tax_method' => 'NONE', 'contribution' => false, 'vacation' => false, 'pension' => false, 'debit' => 2890, 'credit' => null],
        'TJANSTEPENSION' => ['name_sv' => 'Tjänstepension', 'name_en' => 'Occupational pension', 'category' => 'PENSION', 'unit' => 'AMOUNT', 'taxable' => false, 'tax_method' => 'NONE', 'contribution' => false, 'vacation' => false, 'pension' => false, 'debit' => 7410, 'credit' => 2740],
        'SARSKILD_LONESKATT' => ['name_sv' => 'Särskild löneskatt på pension', 'name_en' => 'Special payroll tax on pension', 'category' => 'PENSION', 'unit' => 'AMOUNT', 'taxable' => false, 'tax_method' => 'NONE', 'contribution' => false, 'vacation' => false, 'pension' => false, 'debit' => 7530, 'credit' => 2514],
        'FORSKOTT' => ['name_sv' => 'Avdrag för förskott', 'name_en' => 'Advance deduction', 'category' => 'NET_DEDUCTION', 'unit' => 'AMOUNT', 'taxable' => false, 'tax_method' => 'NONE', 'contribution' => false, 'vacation' => false, 'pension' => false, 'debit' => null, 'credit' => 1680],
        'NETTOAVDRAG' => ['name_sv' => 'Nettolöneavdrag', 'name_en' => 'Net deduction', 'category' => 'NET_DEDUCTION', 'unit' => 'AMOUNT', 'taxable' => false, 'tax_method' => 'NONE', 'contribution' => false, 'vacation' => false, 'pension' => false, 'debit' => null, 'credit' => 2890],
    ];

    /**
     * Accounts payroll books on: the pay codes' accounts and the liability
     * accounts of the salary voucher, with their BAS 2026 names.
     *
     * @var array<int, string>
     */
    public const array ACCOUNTS = [
        1680 => 'Andra kortfristiga fordringar',
        1930 => 'Företagskonto/checkkonto/affärskonto',
        2710 => 'Personalskatt',
        2514 => 'Beräknad särskild löneskatt på pensionskostnader',
        2730 => 'Lagstadgade sociala avgifter och särskild löneskatt',
        2740 => 'Avtalade sociala avgifter',
        2890 => 'Övriga kortfristiga skulder',
        2920 => 'Upplupna semesterlöner',
        2940 => 'Upplupna lagstadgade sociala och andra avgifter',
        7010 => 'Löner till kollektivanställda',
        7081 => 'Sjuklöner till kollektivanställda',
        7082 => 'Semesterlöner till kollektivanställda',
        7090 => 'Förändring av semesterlöneskuld',
        7321 => 'Skattefria traktamenten, Sverige',
        7322 => 'Skattepliktiga traktamenten, Sverige',
        7331 => 'Skattefria bilersättningar',
        7332 => 'Skattepliktiga bilersättningar',
        7410 => 'Pensionsförsäkringspremier',
        7210 => 'Löner till tjänstemän',
        7281 => 'Sjuklöner till tjänstemän',
        7510 => 'Arbetsgivaravgifter',
        7519 => 'Sociala avgifter för semester- och löneskulder',
        7530 => 'Särskild löneskatt',
    ];
}
