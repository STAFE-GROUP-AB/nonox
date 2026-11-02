<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Support\Luhn;

/**
 * One synthetic demo company per industry page in docs/branscher, keyed by
 * the page's slug. Every name, address, organisation number and personal
 * number here is made up; the numbers are only Luhn-valid. Amounts are
 * typical for a small company in the industry, not statistics.
 *
 * Sales profiles:
 * - daily: a cash register's daily report, booked as one voucher per open
 *   day (gross kronor per day, split per VAT rate, part card and Swish to
 *   the bank and part cash);
 * - invoices: customer invoices built from the company's articles, with
 *   hours per invoice row for articles sold by the hour.
 *
 * @phpstan-type Article array{number: string, name: string, unit: string, price: string, vat_rate: int, quantity?: array{0: int, 1: int}}
 * @phpstan-type Customer array{name: string, org_number?: string, vat_number?: string, personal_number?: string, email?: string, city: string, payment_terms_days: int, reverse_charge_construction?: bool}
 * @phpstan-type Cost array{supplier: string, account: int, every: string, amount: array{0: int, 1: int}, vat_rate: int, description: string}
 * @phpstan-type Supplier array{name: string, org_number: string, bankgiro: string, payment_terms_days: int, default_account: int}
 * @phpstan-type Employee array{first_name: string, last_name: string, birth: string, position_title: string, salary_form: string, salary: string, employment_type: string, employment_rate: int}
 * @phpstan-type Demo array{
 *     org_serial: string,
 *     name: string,
 *     city: string,
 *     postal_code: string,
 *     vat_period: string,
 *     firm?: string,
 *     is_firm?: bool,
 *     opening_bank: int,
 *     salary_account: int,
 *     payroll_runs?: bool,
 *     sales: array{kind: string, closed_weekdays?: list<int>, gross_per_day?: array{0: int, 1: int}, rates?: array<int, int>, cash_share?: int, invoices_per_month?: int, hours?: array{0: int, 1: int}},
 *     articles: list<Article>,
 *     customers: list<Customer>,
 *     suppliers: list<Supplier>,
 *     costs: list<Cost>,
 *     employees: list<Employee>,
 *     staff_ledger?: string
 * }
 */
final class DemoIndustries
{
    /** @return array<string, Demo> */
    public static function all(): array
    {
        return [
            'bokforing-for-restauranger' => [
                'org_serial' => '01',
                'salary_account' => 7010,
                'payroll_runs' => true,
                'name' => 'Restaurang Ekbacken Demo AB',
                'city' => 'Göteborg',
                'postal_code' => '411 01',
                'vat_period' => 'MONTHLY',
                'firm' => 'for-redovisningsbyraer',
                'opening_bank' => 180_000,
                'sales' => ['kind' => 'daily', 'closed_weekdays' => [1], 'gross_per_day' => [12_000, 30_000], 'rates' => [1200 => 78, 2500 => 22], 'cash_share' => 4],
                'articles' => [
                    ['number' => '100', 'name' => 'Lunchbuffé, catering', 'unit' => 'st', 'price' => '135', 'vat_rate' => 1200],
                    ['number' => '110', 'name' => 'Middag tre rätter, sällskap', 'unit' => 'st', 'price' => '495', 'vat_rate' => 1200],
                    ['number' => '120', 'name' => 'Lokalhyra privat sällskap', 'unit' => 'tim', 'price' => '1200', 'vat_rate' => 2500],
                ],
                'customers' => [
                    ['name' => 'Kontorshuset Exempel AB', 'org_number' => self::orgNumber('11'), 'email' => 'ekonomi@exempel.test', 'city' => 'Göteborg', 'payment_terms_days' => 30],
                    ['name' => 'Föreningen Exempelbygden', 'org_number' => self::orgNumber('12', '802'), 'city' => 'Göteborg', 'payment_terms_days' => 20],
                ],
                'suppliers' => [
                    ['name' => 'Grossisten Demo AB', 'org_number' => self::orgNumber('51'), 'bankgiro' => '5050-1055', 'payment_terms_days' => 14, 'default_account' => 4010],
                    ['name' => 'Dryckesleverantören Demo AB', 'org_number' => self::orgNumber('52'), 'bankgiro' => '5050-1055', 'payment_terms_days' => 20, 'default_account' => 4010],
                    ['name' => 'Fastighets AB Demo', 'org_number' => self::orgNumber('53'), 'bankgiro' => '5050-1055', 'payment_terms_days' => 0, 'default_account' => 5010],
                    ['name' => 'Städservice Demo AB', 'org_number' => self::orgNumber('54'), 'bankgiro' => '5050-1055', 'payment_terms_days' => 30, 'default_account' => 5060],
                    ['name' => 'Kassasystem Demo AB', 'org_number' => self::orgNumber('55'), 'bankgiro' => '5050-1055', 'payment_terms_days' => 30, 'default_account' => 5420],
                ],
                'costs' => [
                    ['supplier' => 'Grossisten Demo AB', 'account' => 4010, 'every' => 'week', 'amount' => [45_000, 62_000], 'vat_rate' => 1200, 'description' => 'Livsmedel'],
                    ['supplier' => 'Dryckesleverantören Demo AB', 'account' => 4010, 'every' => 'twoweeks', 'amount' => [14_000, 22_000], 'vat_rate' => 2500, 'description' => 'Drycker'],
                    ['supplier' => 'Fastighets AB Demo', 'account' => 5010, 'every' => 'month', 'amount' => [42_000, 42_000], 'vat_rate' => 2500, 'description' => 'Lokalhyra'],
                    ['supplier' => 'Städservice Demo AB', 'account' => 5060, 'every' => 'month', 'amount' => [6_500, 8_000], 'vat_rate' => 2500, 'description' => 'Storstädning kök'],
                    ['supplier' => 'Kassasystem Demo AB', 'account' => 5420, 'every' => 'month', 'amount' => [899, 899], 'vat_rate' => 2500, 'description' => 'Kassasystem, licens'],
                ],
                'employees' => [
                    ['first_name' => 'Sara', 'last_name' => 'Kock', 'birth' => '19880412', 'position_title' => 'Köksmästare', 'salary_form' => 'MONTHLY', 'salary' => '36 500', 'employment_type' => 'TILLSVIDARE', 'employment_rate' => 100],
                    ['first_name' => 'Ali', 'last_name' => 'Hassan', 'birth' => '19950923', 'position_title' => 'Kock', 'salary_form' => 'MONTHLY', 'salary' => '29 800', 'employment_type' => 'TILLSVIDARE', 'employment_rate' => 100],
                    ['first_name' => 'Lina', 'last_name' => 'Berg', 'birth' => '19990217', 'position_title' => 'Servitris', 'salary_form' => 'MONTHLY', 'salary' => '27 200', 'employment_type' => 'TILLSVIDARE', 'employment_rate' => 100],
                    ['first_name' => 'Johan', 'last_name' => 'Ek', 'birth' => '19920630', 'position_title' => 'Hovmästare', 'salary_form' => 'MONTHLY', 'salary' => '31 000', 'employment_type' => 'TILLSVIDARE', 'employment_rate' => 100],
                    ['first_name' => 'Maja', 'last_name' => 'Lund', 'birth' => '20040508', 'position_title' => 'Servitris', 'salary_form' => 'HOURLY', 'salary' => '158,50', 'employment_type' => 'TIMANSTALLD', 'employment_rate' => 50],
                    ['first_name' => 'Omar', 'last_name' => 'Nilsson', 'birth' => '20011112', 'position_title' => 'Diskare', 'salary_form' => 'HOURLY', 'salary' => '152,00', 'employment_type' => 'TIMANSTALLD', 'employment_rate' => 50],
                    ['first_name' => 'Elin', 'last_name' => 'Sund', 'birth' => '19970303', 'position_title' => 'Bartender', 'salary_form' => 'HOURLY', 'salary' => '160,00', 'employment_type' => 'TIMANSTALLD', 'employment_rate' => 40],
                    ['first_name' => 'Nils', 'last_name' => 'Ström', 'birth' => '20050901', 'position_title' => 'Kallskänka', 'salary_form' => 'HOURLY', 'salary' => '149,00', 'employment_type' => 'TIMANSTALLD', 'employment_rate' => 30],
                ],
                'staff_ledger' => 'RESTAURANT',
            ],
            'bokforing-for-cafeer' => [
                'org_serial' => '02',
                'salary_account' => 7010,
                'name' => 'Café Hörnet Demo AB',
                'city' => 'Uppsala',
                'postal_code' => '753 20',
                'vat_period' => 'QUARTERLY',
                'firm' => 'for-redovisningsbyraer',
                'opening_bank' => 85_000,
                'sales' => ['kind' => 'daily', 'closed_weekdays' => [], 'gross_per_day' => [4_000, 9_000], 'rates' => [1200 => 94, 2500 => 6], 'cash_share' => 6],
                'articles' => [
                    ['number' => '10', 'name' => 'Fikabricka, beställning', 'unit' => 'st', 'price' => '65', 'vat_rate' => 1200],
                    ['number' => '20', 'name' => 'Smörgåstårta, 12 bitar', 'unit' => 'st', 'price' => '890', 'vat_rate' => 1200],
                ],
                'customers' => [
                    ['name' => 'Exempelskolan AB', 'org_number' => self::orgNumber('13'), 'city' => 'Uppsala', 'payment_terms_days' => 30],
                ],
                'suppliers' => [
                    ['name' => 'Bageri Demo AB', 'org_number' => self::orgNumber('56'), 'bankgiro' => '5050-1055', 'payment_terms_days' => 14, 'default_account' => 4010],
                    ['name' => 'Kafferosteriet Demo AB', 'org_number' => self::orgNumber('57'), 'bankgiro' => '5050-1055', 'payment_terms_days' => 30, 'default_account' => 4010],
                    ['name' => 'Fastighets AB Demo', 'org_number' => self::orgNumber('53'), 'bankgiro' => '5050-1055', 'payment_terms_days' => 0, 'default_account' => 5010],
                    ['name' => 'Kassasystem Demo AB', 'org_number' => self::orgNumber('55'), 'bankgiro' => '5050-1055', 'payment_terms_days' => 30, 'default_account' => 5420],
                ],
                'costs' => [
                    ['supplier' => 'Bageri Demo AB', 'account' => 4010, 'every' => 'week', 'amount' => [9_000, 15_000], 'vat_rate' => 1200, 'description' => 'Bröd och bakverk'],
                    ['supplier' => 'Kafferosteriet Demo AB', 'account' => 4010, 'every' => 'month', 'amount' => [4_500, 7_000], 'vat_rate' => 1200, 'description' => 'Kaffebönor'],
                    ['supplier' => 'Fastighets AB Demo', 'account' => 5010, 'every' => 'month', 'amount' => [18_500, 18_500], 'vat_rate' => 2500, 'description' => 'Lokalhyra'],
                    ['supplier' => 'Kassasystem Demo AB', 'account' => 5420, 'every' => 'month', 'amount' => [499, 499], 'vat_rate' => 2500, 'description' => 'Kassasystem, licens'],
                ],
                'employees' => [
                    ['first_name' => 'Ida', 'last_name' => 'Holm', 'birth' => '19900814', 'position_title' => 'Barista', 'salary_form' => 'MONTHLY', 'salary' => '27 500', 'employment_type' => 'TILLSVIDARE', 'employment_rate' => 100],
                    ['first_name' => 'Leo', 'last_name' => 'Falk', 'birth' => '19980126', 'position_title' => 'Bagare', 'salary_form' => 'MONTHLY', 'salary' => '28 900', 'employment_type' => 'TILLSVIDARE', 'employment_rate' => 100],
                    ['first_name' => 'Wilma', 'last_name' => 'Sjö', 'birth' => '20060411', 'position_title' => 'Kafébiträde', 'salary_form' => 'HOURLY', 'salary' => '145,00', 'employment_type' => 'TIMANSTALLD', 'employment_rate' => 30],
                    ['first_name' => 'Hugo', 'last_name' => 'Ros', 'birth' => '20030719', 'position_title' => 'Kafébiträde', 'salary_form' => 'HOURLY', 'salary' => '150,00', 'employment_type' => 'TIMANSTALLD', 'employment_rate' => 40],
                ],
                'staff_ledger' => 'RESTAURANT',
            ],
            'bokforing-for-butiker' => [
                'org_serial' => '03',
                'salary_account' => 7010,
                'name' => 'Butik Lindgården Demo AB',
                'city' => 'Västerås',
                'postal_code' => '722 11',
                'vat_period' => 'QUARTERLY',
                'firm' => 'for-redovisningsbyraer',
                'opening_bank' => 140_000,
                'sales' => ['kind' => 'daily', 'closed_weekdays' => [7], 'gross_per_day' => [6_000, 22_000], 'rates' => [2500 => 100], 'cash_share' => 3],
                'articles' => [
                    ['number' => 'P-500', 'name' => 'Presentkort 500 kr', 'unit' => 'st', 'price' => '400', 'vat_rate' => 2500],
                    ['number' => 'INR-1', 'name' => 'Inredningsuppdrag', 'unit' => 'tim', 'price' => '750', 'vat_rate' => 2500],
                ],
                'customers' => [
                    ['name' => 'Hotell Exempel AB', 'org_number' => self::orgNumber('14'), 'city' => 'Västerås', 'payment_terms_days' => 30],
                ],
                'suppliers' => [
                    ['name' => 'Inredningsgrossisten Demo AB', 'org_number' => self::orgNumber('58'), 'bankgiro' => '5050-1055', 'payment_terms_days' => 30, 'default_account' => 4010],
                    ['name' => 'Textilhuset Demo AB', 'org_number' => self::orgNumber('59'), 'bankgiro' => '5050-1055', 'payment_terms_days' => 30, 'default_account' => 4010],
                    ['name' => 'Fastighets AB Demo', 'org_number' => self::orgNumber('53'), 'bankgiro' => '5050-1055', 'payment_terms_days' => 0, 'default_account' => 5010],
                    ['name' => 'Kassasystem Demo AB', 'org_number' => self::orgNumber('55'), 'bankgiro' => '5050-1055', 'payment_terms_days' => 30, 'default_account' => 5420],
                ],
                'costs' => [
                    ['supplier' => 'Inredningsgrossisten Demo AB', 'account' => 4010, 'every' => 'twoweeks', 'amount' => [45_000, 80_000], 'vat_rate' => 2500, 'description' => 'Varuinköp'],
                    ['supplier' => 'Textilhuset Demo AB', 'account' => 4010, 'every' => 'month', 'amount' => [20_000, 40_000], 'vat_rate' => 2500, 'description' => 'Textilier'],
                    ['supplier' => 'Fastighets AB Demo', 'account' => 5010, 'every' => 'month', 'amount' => [26_000, 26_000], 'vat_rate' => 2500, 'description' => 'Butikslokal'],
                    ['supplier' => 'Kassasystem Demo AB', 'account' => 5420, 'every' => 'month', 'amount' => [699, 699], 'vat_rate' => 2500, 'description' => 'Kassasystem, licens'],
                ],
                'employees' => [
                    ['first_name' => 'Karin', 'last_name' => 'Lind', 'birth' => '19830605', 'position_title' => 'Butikschef', 'salary_form' => 'MONTHLY', 'salary' => '33 000', 'employment_type' => 'TILLSVIDARE', 'employment_rate' => 100],
                    ['first_name' => 'Emil', 'last_name' => 'Dahl', 'birth' => '19960218', 'position_title' => 'Säljare', 'salary_form' => 'MONTHLY', 'salary' => '26 800', 'employment_type' => 'TILLSVIDARE', 'employment_rate' => 80],
                    ['first_name' => 'Alva', 'last_name' => 'Ek', 'birth' => '20050122', 'position_title' => 'Säljare helg', 'salary_form' => 'HOURLY', 'salary' => '148,00', 'employment_type' => 'TIMANSTALLD', 'employment_rate' => 25],
                ],
            ],
            'bokforing-for-frisorer' => [
                'org_serial' => '04',
                'salary_account' => 7010,
                'name' => 'Salong Saxen Demo AB',
                'city' => 'Malmö',
                'postal_code' => '211 22',
                'vat_period' => 'QUARTERLY',
                'firm' => 'for-ekonomikonsulter',
                'opening_bank' => 60_000,
                'sales' => ['kind' => 'daily', 'closed_weekdays' => [7], 'gross_per_day' => [2_500, 6_500], 'rates' => [2500 => 100], 'cash_share' => 2],
                'articles' => [
                    ['number' => 'K1', 'name' => 'Klippning', 'unit' => 'st', 'price' => '520', 'vat_rate' => 2500],
                    ['number' => 'F1', 'name' => 'Färgning', 'unit' => 'st', 'price' => '1240', 'vat_rate' => 2500],
                ],
                'customers' => [
                    ['name' => 'Teatern Exempel AB', 'org_number' => self::orgNumber('15'), 'city' => 'Malmö', 'payment_terms_days' => 30],
                ],
                'suppliers' => [
                    ['name' => 'Salongsprodukter Demo AB', 'org_number' => self::orgNumber('60'), 'bankgiro' => '5050-1055', 'payment_terms_days' => 30, 'default_account' => 4010],
                    ['name' => 'Fastighets AB Demo', 'org_number' => self::orgNumber('53'), 'bankgiro' => '5050-1055', 'payment_terms_days' => 0, 'default_account' => 5010],
                    ['name' => 'Bokningssystem Demo AB', 'org_number' => self::orgNumber('61'), 'bankgiro' => '5050-1055', 'payment_terms_days' => 30, 'default_account' => 5420],
                ],
                'costs' => [
                    ['supplier' => 'Salongsprodukter Demo AB', 'account' => 4010, 'every' => 'month', 'amount' => [6_000, 11_000], 'vat_rate' => 2500, 'description' => 'Färg och hårvård'],
                    ['supplier' => 'Fastighets AB Demo', 'account' => 5010, 'every' => 'month', 'amount' => [14_000, 14_000], 'vat_rate' => 2500, 'description' => 'Salongslokal'],
                    ['supplier' => 'Bokningssystem Demo AB', 'account' => 5420, 'every' => 'month', 'amount' => [590, 590], 'vat_rate' => 2500, 'description' => 'Bokningssystem'],
                ],
                'employees' => [
                    ['first_name' => 'Nora', 'last_name' => 'Sax', 'birth' => '19870911', 'position_title' => 'Frisör', 'salary_form' => 'MONTHLY', 'salary' => '29 000', 'employment_type' => 'TILLSVIDARE', 'employment_rate' => 100],
                    ['first_name' => 'Felix', 'last_name' => 'Kam', 'birth' => '19940402', 'position_title' => 'Frisör', 'salary_form' => 'MONTHLY', 'salary' => '27 500', 'employment_type' => 'TILLSVIDARE', 'employment_rate' => 80],
                ],
                'staff_ledger' => 'BEAUTY_CARE',
            ],
            'bokforing-for-hantverkare' => [
                'org_serial' => '05',
                'salary_account' => 7010,
                'name' => 'Snickeri Norrby Demo AB',
                'city' => 'Örebro',
                'postal_code' => '702 10',
                'vat_period' => 'QUARTERLY',
                'firm' => 'for-redovisningsbyraer',
                'opening_bank' => 210_000,
                'sales' => ['kind' => 'invoices', 'invoices_per_month' => 8, 'hours' => [16, 80]],
                'articles' => [
                    ['number' => 'ARB', 'name' => 'Arbete snickare', 'unit' => 'tim', 'price' => '595', 'vat_rate' => 2500],
                    ['number' => 'MAT', 'name' => 'Material enligt specifikation', 'unit' => 'st', 'price' => '2400', 'vat_rate' => 2500],
                    ['number' => 'RES', 'name' => 'Resa och etablering', 'unit' => 'st', 'price' => '450', 'vat_rate' => 2500],
                ],
                'customers' => [
                    ['name' => 'Anna Andersson', 'personal_number' => self::personalNumber('19780314', '281'), 'city' => 'Örebro', 'payment_terms_days' => 20],
                    ['name' => 'Per Persson', 'personal_number' => self::personalNumber('19650927', '193'), 'city' => 'Kumla', 'payment_terms_days' => 20],
                    ['name' => 'Byggbolaget Exempel AB', 'org_number' => self::orgNumber('16'), 'vat_number' => self::vatNumber('16'), 'city' => 'Örebro', 'payment_terms_days' => 30, 'reverse_charge_construction' => true],
                    ['name' => 'Bostadsrättsföreningen Exemplet', 'org_number' => self::orgNumber('17', '769'), 'city' => 'Örebro', 'payment_terms_days' => 30],
                ],
                'suppliers' => [
                    ['name' => 'Byggvaruhuset Demo AB', 'org_number' => self::orgNumber('62'), 'bankgiro' => '5050-1055', 'payment_terms_days' => 30, 'default_account' => 4010],
                    ['name' => 'Verktygsuthyrning Demo AB', 'org_number' => self::orgNumber('63'), 'bankgiro' => '5050-1055', 'payment_terms_days' => 30, 'default_account' => 5410],
                    ['name' => 'Drivmedel Demo AB', 'org_number' => self::orgNumber('64'), 'bankgiro' => '5050-1055', 'payment_terms_days' => 30, 'default_account' => 5611],
                    ['name' => 'Försäkringsbolaget Demo AB', 'org_number' => self::orgNumber('65', '516'), 'bankgiro' => '5050-1055', 'payment_terms_days' => 30, 'default_account' => 6310],
                ],
                'costs' => [
                    ['supplier' => 'Byggvaruhuset Demo AB', 'account' => 4010, 'every' => 'week', 'amount' => [6_000, 24_000], 'vat_rate' => 2500, 'description' => 'Byggmaterial'],
                    ['supplier' => 'Verktygsuthyrning Demo AB', 'account' => 5410, 'every' => 'month', 'amount' => [2_000, 5_500], 'vat_rate' => 2500, 'description' => 'Verktyg och maskinhyra'],
                    ['supplier' => 'Drivmedel Demo AB', 'account' => 5611, 'every' => 'month', 'amount' => [4_000, 7_000], 'vat_rate' => 2500, 'description' => 'Drivmedel servicebilar'],
                    ['supplier' => 'Försäkringsbolaget Demo AB', 'account' => 6310, 'every' => 'quarter', 'amount' => [6_800, 6_800], 'vat_rate' => 0, 'description' => 'Företagsförsäkring'],
                ],
                'employees' => [
                    ['first_name' => 'Erik', 'last_name' => 'Norrby', 'birth' => '19810722', 'position_title' => 'Snickare, arbetsledare', 'salary_form' => 'MONTHLY', 'salary' => '38 000', 'employment_type' => 'TILLSVIDARE', 'employment_rate' => 100],
                    ['first_name' => 'Adam', 'last_name' => 'Kvist', 'birth' => '19930115', 'position_title' => 'Snickare', 'salary_form' => 'MONTHLY', 'salary' => '34 500', 'employment_type' => 'TILLSVIDARE', 'employment_rate' => 100],
                    ['first_name' => 'Theo', 'last_name' => 'Björk', 'birth' => '20040610', 'position_title' => 'Lärling', 'salary_form' => 'HOURLY', 'salary' => '165,00', 'employment_type' => 'PROVANSTALLNING', 'employment_rate' => 100],
                ],
            ],
            'bokforing-for-konsulter' => [
                'org_serial' => '06',
                'salary_account' => 7220,
                'name' => 'Konsultbolaget Exempel Demo AB',
                'city' => 'Stockholm',
                'postal_code' => '111 22',
                'vat_period' => 'QUARTERLY',
                'firm' => 'for-ekonomikonsulter',
                'opening_bank' => 120_000,
                'sales' => ['kind' => 'invoices', 'invoices_per_month' => 2, 'hours' => [50, 80]],
                'articles' => [
                    ['number' => 'KONS', 'name' => 'Konsulttjänster', 'unit' => 'tim', 'price' => '1150', 'vat_rate' => 2500],
                    ['number' => 'WS', 'name' => 'Workshop, halvdag', 'unit' => 'st', 'price' => '9500', 'vat_rate' => 2500],
                ],
                'customers' => [
                    ['name' => 'Kunden Exempel AB', 'org_number' => self::orgNumber('18'), 'email' => 'faktura@kunden.test', 'city' => 'Stockholm', 'payment_terms_days' => 30],
                    ['name' => 'Mjukvaruhuset Exempel AB', 'org_number' => self::orgNumber('19'), 'city' => 'Solna', 'payment_terms_days' => 30],
                ],
                'suppliers' => [
                    ['name' => 'Kontorshotellet Demo AB', 'org_number' => self::orgNumber('66'), 'bankgiro' => '5050-1055', 'payment_terms_days' => 0, 'default_account' => 5010],
                    ['name' => 'Telefonbolaget Demo AB', 'org_number' => self::orgNumber('67'), 'bankgiro' => '5050-1055', 'payment_terms_days' => 30, 'default_account' => 6212],
                    ['name' => 'Molntjänster Demo AB', 'org_number' => self::orgNumber('68'), 'bankgiro' => '5050-1055', 'payment_terms_days' => 30, 'default_account' => 5420],
                ],
                'costs' => [
                    ['supplier' => 'Kontorshotellet Demo AB', 'account' => 5010, 'every' => 'month', 'amount' => [4_900, 4_900], 'vat_rate' => 2500, 'description' => 'Kontorsplats'],
                    ['supplier' => 'Telefonbolaget Demo AB', 'account' => 6212, 'every' => 'month', 'amount' => [399, 520], 'vat_rate' => 2500, 'description' => 'Mobilabonnemang'],
                    ['supplier' => 'Molntjänster Demo AB', 'account' => 5420, 'every' => 'month', 'amount' => [650, 650], 'vat_rate' => 2500, 'description' => 'Programvara'],
                ],
                'employees' => [
                    ['first_name' => 'Anna', 'last_name' => 'Andersson', 'birth' => '19840229', 'position_title' => 'VD och konsult', 'salary_form' => 'MONTHLY', 'salary' => '65 000', 'employment_type' => 'TILLSVIDARE', 'employment_rate' => 100],
                ],
            ],
            'for-redovisningsbyraer' => [
                'org_serial' => '07',
                'salary_account' => 7210,
                'name' => 'Redovisningsbyrån Exempel Demo AB',
                'city' => 'Linköping',
                'postal_code' => '582 22',
                'vat_period' => 'QUARTERLY',
                'is_firm' => true,
                'opening_bank' => 160_000,
                'sales' => ['kind' => 'invoices', 'invoices_per_month' => 4, 'hours' => [1, 6]],
                'articles' => [
                    ['number' => 'LB', 'name' => 'Löpande bokföring, månadsarvode', 'unit' => 'st', 'price' => '5900', 'vat_rate' => 2500, 'quantity' => [1, 1]],
                    ['number' => 'LON', 'name' => 'Lönehantering per anställd', 'unit' => 'st', 'price' => '290', 'vat_rate' => 2500, 'quantity' => [1, 8]],
                    ['number' => 'BS', 'name' => 'Bokslut och årsredovisning', 'unit' => 'st', 'price' => '14500', 'vat_rate' => 2500, 'quantity' => [1, 1]],
                    ['number' => 'TIM', 'name' => 'Rådgivning', 'unit' => 'tim', 'price' => '950', 'vat_rate' => 2500],
                ],
                // Clients that keep their own books elsewhere; the demo clients in NONOX are added to these.
                'customers' => [
                    ['name' => 'Bageriet Exempel AB', 'org_number' => self::orgNumber('20'), 'city' => 'Linköping', 'payment_terms_days' => 15],
                    ['name' => 'Måleri Exempel AB', 'org_number' => self::orgNumber('21'), 'city' => 'Norrköping', 'payment_terms_days' => 15],
                    ['name' => 'Tandläkare Exempel AB', 'org_number' => self::orgNumber('22'), 'city' => 'Linköping', 'payment_terms_days' => 15],
                    ['name' => 'Transport Exempel AB', 'org_number' => self::orgNumber('23'), 'city' => 'Motala', 'payment_terms_days' => 15],
                    ['name' => 'Arkitektkontoret Exempel AB', 'org_number' => self::orgNumber('24'), 'city' => 'Linköping', 'payment_terms_days' => 15],
                    ['name' => 'Blomsterhandel Exempel AB', 'org_number' => self::orgNumber('25'), 'city' => 'Mjölby', 'payment_terms_days' => 15],
                    ['name' => 'Bilverkstad Exempel AB', 'org_number' => self::orgNumber('26'), 'city' => 'Norrköping', 'payment_terms_days' => 15],
                    ['name' => 'IT-konsult Exempel AB', 'org_number' => self::orgNumber('27'), 'city' => 'Linköping', 'payment_terms_days' => 15],
                ],
                'suppliers' => [
                    ['name' => 'Kontorsfastigheter Demo AB', 'org_number' => self::orgNumber('69'), 'bankgiro' => '5050-1055', 'payment_terms_days' => 0, 'default_account' => 5010],
                    ['name' => 'Branschföreningen Demo', 'org_number' => self::orgNumber('70', '802'), 'bankgiro' => '5050-1055', 'payment_terms_days' => 30, 'default_account' => 6981],
                    ['name' => 'Telefonbolaget Demo AB', 'org_number' => self::orgNumber('67'), 'bankgiro' => '5050-1055', 'payment_terms_days' => 30, 'default_account' => 6212],
                ],
                'costs' => [
                    ['supplier' => 'Kontorsfastigheter Demo AB', 'account' => 5010, 'every' => 'month', 'amount' => [16_000, 16_000], 'vat_rate' => 2500, 'description' => 'Kontorslokal'],
                    ['supplier' => 'Branschföreningen Demo', 'account' => 6981, 'every' => 'year', 'amount' => [4_200, 4_200], 'vat_rate' => 0, 'description' => 'Medlemsavgift'],
                    ['supplier' => 'Telefonbolaget Demo AB', 'account' => 6212, 'every' => 'month', 'amount' => [1_100, 1_400], 'vat_rate' => 2500, 'description' => 'Telefoni'],
                ],
                'employees' => [
                    ['first_name' => 'Maria', 'last_name' => 'Konsult', 'birth' => '19790518', 'position_title' => 'Auktoriserad redovisningskonsult', 'salary_form' => 'MONTHLY', 'salary' => '42 000', 'employment_type' => 'TILLSVIDARE', 'employment_rate' => 100],
                    ['first_name' => 'Jonas', 'last_name' => 'Siffra', 'birth' => '19910827', 'position_title' => 'Redovisningsekonom', 'salary_form' => 'MONTHLY', 'salary' => '33 000', 'employment_type' => 'TILLSVIDARE', 'employment_rate' => 100],
                ],
            ],
            'for-ekonomikonsulter' => [
                'org_serial' => '08',
                'salary_account' => 7210,
                'name' => 'Ekonomikonsult Exempel Demo AB',
                'city' => 'Umeå',
                'postal_code' => '903 26',
                'vat_period' => 'QUARTERLY',
                'is_firm' => true,
                'opening_bank' => 45_000,
                'sales' => ['kind' => 'invoices', 'invoices_per_month' => 2, 'hours' => [8, 24]],
                'articles' => [
                    ['number' => 'LB', 'name' => 'Löpande bokföring', 'unit' => 'tim', 'price' => '695', 'vat_rate' => 2500],
                    ['number' => 'MOMS', 'name' => 'Momsdeklaration', 'unit' => 'st', 'price' => '850', 'vat_rate' => 2500, 'quantity' => [1, 1]],
                ],
                'customers' => [],
                'suppliers' => [
                    ['name' => 'Telefonbolaget Demo AB', 'org_number' => self::orgNumber('67'), 'bankgiro' => '5050-1055', 'payment_terms_days' => 30, 'default_account' => 6212],
                ],
                'costs' => [
                    ['supplier' => 'Telefonbolaget Demo AB', 'account' => 6212, 'every' => 'month', 'amount' => [349, 349], 'vat_rate' => 2500, 'description' => 'Mobilabonnemang'],
                ],
                'employees' => [],
            ],
        ];
    }

    /**
     * A synthetic, Luhn-valid organisation number: prefix (the legal form's
     * first digits), a middle part that keeps the demo numbers together
     * (9998 for the demo companies, 9997 for their customers and suppliers)
     * and a two-digit serial.
     */
    public static function orgNumber(string $serial, string $prefix = '559', string $middle = '9997'): string
    {
        $nine = $prefix.$middle.$serial;

        return substr($nine, 0, 6).'-'.substr($nine, 6).Luhn::checkDigit($nine);
    }

    /** The Swedish VAT number of a synthetic organisation number: SE, the ten digits, 01. */
    public static function vatNumber(string $serial, string $prefix = '559', string $middle = '9997'): string
    {
        return 'SE'.str_replace('-', '', self::orgNumber($serial, $prefix, $middle)).'01';
    }

    /** A synthetic, Luhn-valid personal number (ÅÅÅÅMMDDNNNK). */
    public static function personalNumber(string $birth, string $serial): string
    {
        $ten = substr($birth, 2).$serial;

        return $birth.$serial.Luhn::checkDigit($ten);
    }
}
