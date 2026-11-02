<?php

declare(strict_types=1);

namespace App\Support\Landing;

use InvalidArgumentException;

/**
 * The single list of what NONOX does on the public pages, and whether it is
 * built (live) or still coming (soon). Every public page that names a
 * feature refers to it by its key here, and a test checks each key against
 * the README: a live feature's "readme" text must stand under "Funktioner"
 * before "På väg", a soon feature's under "På väg". So the pages, the README
 * and this list cannot drift apart without a failing test.
 */
final class Features
{
    public const string LIVE = 'live';

    public const string SOON = 'soon';

    /**
     * @var array<string, array{name: string, status: string, readme: string, module?: string}>
     */
    private const array FEATURES = [
        'vouchers' => ['name' => 'Verifikationer som aldrig kan ändras', 'status' => self::LIVE, 'readme' => 'En bokförd verifikation kan aldrig ändras'],
        'templates' => ['name' => 'Konteringsmallar', 'status' => self::LIVE, 'readme' => 'konteringsmallar och underlag'],
        'reports' => ['name' => 'Rapporter per period och kostnadsställe', 'status' => self::LIVE, 'readme' => 'huvudbok, kontoanalys och verifikationslista per period och kostnadsställe'],
        'cost_centres' => ['name' => 'Kostnadsställen och projekt', 'status' => self::LIVE, 'readme' => 'kostnadsställen och projekt'],
        'vat_return' => ['name' => 'Momsdeklaration som fil', 'status' => self::LIVE, 'readme' => 'Momsdeklaration med fil till Skatteverket'],
        'eu_reports' => ['name' => 'Periodisk sammanställning, OSS och VIES', 'status' => self::LIVE, 'readme' => 'periodisk sammanställning, underlag för OSS i kronor och euro'],
        'bank_reconciliation' => ['name' => 'Bankavstämning', 'status' => self::LIVE, 'readme' => 'Bankavstämning, periodiseringar, anläggningsregister'],
        'accruals_assets' => ['name' => 'Periodiseringar och anläggningsregister', 'status' => self::LIVE, 'readme' => 'periodiseringar, anläggningsregister med avskrivningar'],
        'year_end' => ['name' => 'Bokslut, årsredovisning K2 och INK2', 'status' => self::LIVE, 'readme' => 'årsredovisning enligt K2 som PDF, och inkomstdeklaration 2 som SRU-filer'],
        'invoices' => ['name' => 'Fakturor med OCR-nummer', 'status' => self::LIVE, 'readme' => 'Fakturor med OCR-nummer, PDF och utskick via e-post'],
        'quotes_orders' => ['name' => 'Offerter och order', 'status' => self::LIVE, 'readme' => 'Offerter och order som blir fakturor'],
        'recurring_invoices' => ['name' => 'Avtalsfakturering', 'status' => self::LIVE, 'readme' => 'avtalsfakturering varje månad, kvartal, halvår eller år'],
        'reminders_credit' => ['name' => 'Kreditfakturor och påminnelser', 'status' => self::LIVE, 'readme' => 'kreditfakturor och påminnelser'],
        'incoming_payments' => ['name' => 'Inbetalningar matchas på OCR', 'status' => self::LIVE, 'readme' => 'läses in och matchas på OCR-numret'],
        'rot_rut' => ['name' => 'ROT- och RUT-avdrag', 'status' => self::LIVE, 'readme' => 'ROT- och RUT-avdrag'],
        'construction_reverse_charge' => ['name' => 'Omvänd betalningsskyldighet för byggtjänster', 'status' => self::LIVE, 'readme' => 'omvänd betalningsskyldighet för byggtjänster'],
        'eu_invoices' => ['name' => 'Fakturor till andra EU-länder och export', 'status' => self::LIVE, 'readme' => 'fakturor till andra EU-länder och export med rätt konto, momsruta och fakturatext'],
        'supplier_invoices' => ['name' => 'Leverantörsfakturor med attest', 'status' => self::LIVE, 'readme' => 'attest av en annan person än den som registrerade'],
        'payment_files' => ['name' => 'Betalfiler till banken', 'status' => self::LIVE, 'readme' => 'Betalfiler till banken (ISO 20022 pain.001)'],
        'eu_purchases' => ['name' => 'Inköp från andra EU-länder', 'status' => self::LIVE, 'readme' => 'Kreditnotor från leverantörer, inköp från andra EU-länder'],
        'mobile_check_in' => ['name' => 'Mobil incheckning', 'status' => self::LIVE, 'readme' => 'med kod, personlig QR-kod eller mobil incheckning', 'module' => 'Personalliggare'],
        'registers' => ['name' => 'Kunder, artiklar och leverantörer', 'status' => self::LIVE, 'readme' => 'Kunder, artiklar och leverantörer med pris, moms och konto'],
        'company_lookup' => ['name' => 'Företagsuppgifter från Bolagsverket', 'status' => self::LIVE, 'readme' => 'namn, adress och företagsform hämtas från Bolagsverket'],
        'payroll' => ['name' => 'Lön med skattetabeller', 'status' => self::LIVE, 'readme' => 'Skatteverkets skattetabeller, månads- och timlön', 'module' => 'Lön'],
        'hourly_pay' => ['name' => 'Timlön från godkända tidrapporter', 'status' => self::LIVE, 'readme' => 'Timlön räknas från godkända tidrapporter, även från personalliggaren', 'module' => 'Lön'],
        'payroll_file_import' => ['name' => 'Löneunderlag från schemasystem (PAXml)', 'status' => self::LIVE, 'readme' => 'Löneunderlag från schemasystem som PAXml eller CSV', 'module' => 'Lön'],
        'agreements' => ['name' => 'Kollektivavtal i lönen', 'status' => self::LIVE, 'readme' => 'Kollektivavtal i lönen: avtalets värden som versionerad data med källa', 'module' => 'Avtal'],
        'young_employer_contributions' => ['name' => 'Arbetsgivaravgifter med sänkningen för unga', 'status' => self::LIVE, 'readme' => 'arbetsgivaravgifter med sänkningen för unga', 'module' => 'Lön'],
        'vacation_sick_pay' => ['name' => 'Semester och sjuklön', 'status' => self::LIVE, 'readme' => 'Semester enligt semesterlagen', 'module' => 'Lön'],
        'travel_expenses' => ['name' => 'Traktamente, milersättning och utlägg', 'status' => self::LIVE, 'readme' => 'traktamente och milersättning, tjänstepension, utlägg med kvitto', 'module' => 'Lön'],
        'agi' => ['name' => 'Arbetsgivardeklaration (AGI)', 'status' => self::LIVE, 'readme' => 'Arbetsgivardeklaration (AGI) som XML', 'module' => 'Lön'],
        'employee_portal' => ['name' => 'Min sida för anställda', 'status' => self::LIVE, 'readme' => 'Min sida för anställda: lönebesked, sjukanmälan', 'module' => 'Lön'],
        'staff_ledger' => ['name' => 'Personalliggare med kiosk', 'status' => self::LIVE, 'readme' => 'in- och utcheckning i en kiosk på en surfplatta, som fungerar även utan internet', 'module' => 'Personalliggare'],
        'planning' => ['name' => 'Schema med lönekostnad', 'status' => self::LIVE, 'readme' => 'Schema med lönekostnad: veckovy och dagvy', 'module' => 'Planering'],
        'planning_payroll' => ['name' => 'Schema och personalliggare blir löneunderlag', 'status' => self::LIVE, 'readme' => 'Schema och personalliggare blir löneunderlag', 'module' => 'Planering'],
        'planning_staffing' => ['name' => 'Lönekostnad i procent av försäljningen', 'status' => self::LIVE, 'readme' => 'lönekostnad i procent av försäljningen ur bokföringen', 'module' => 'Planering'],
        'planning_self_service' => ['name' => 'Mitt schema, öppna pass och byten på Min sida', 'status' => self::LIVE, 'readme' => 'Min sida visar mitt schema, lediga pass att ta, byten och kalenderlänk', 'module' => 'Planering'],
        'inventory' => ['name' => 'Lager med lagerställen och EAN', 'status' => self::LIVE, 'readme' => 'Lagerställen i lager, zoner, hyllor och fack', 'module' => 'Lager'],
        'inventory_flow' => ['name' => 'Inleveranser, plocklistor och inventering', 'status' => self::LIVE, 'readme' => 'Inventering per hylla eller hela lagret', 'module' => 'Lager'],
        'inventory_valuation' => ['name' => 'Lagervärde vid bokslut', 'status' => self::LIVE, 'readme' => 'Lagervärde vid bokslut enligt FIFO eller vägt genomsnittspris', 'module' => 'Lager'],
        'inventory_images' => ['name' => 'Artikelbilder från mobilen och NONOX AI', 'status' => self::LIVE, 'readme' => 'Bilder på artiklarna, tagna direkt med mobilens kamera', 'module' => 'Lager'],
        'control_visit' => ['name' => 'Kontrollbesök i personalliggaren', 'status' => self::LIVE, 'readme' => 'Kontrollbesöket ("Skatteverket är här") visar och exporterar liggaren', 'module' => 'Personalliggare'],
        'inbox' => ['name' => 'Inkorg för fakturor och kvitton', 'status' => self::LIVE, 'readme' => 'En egen e-postadress per företag för leverantörsfakturor, kvitton och dagsrapporter från kassasystem', 'module' => 'Inkorg'],
        'daily_reports' => ['name' => 'Dagsrapporter från kassasystemet', 'status' => self::LIVE, 'readme' => 'dagsrapporter läses utan AI, med mallar som en person godkänt', 'module' => 'Inkorg'],
        'bank_feed' => ['name' => 'Bankkoppling med BankID', 'status' => self::LIVE, 'readme' => 'Företagets bankkonton kopplas med BankID genom öppen bankdata (PSD2)', 'module' => 'Bank'],
        'ai' => ['name' => 'NONOX AI läser underlag', 'status' => self::LIVE, 'readme' => 'Läser leverantörsfakturor, kvitton och utlägg direkt när de laddats upp eller fotograferats, och föreslår konton och belopp med företagets egen nyckel', 'module' => 'NONOX AI'],
        'multi_company' => ['name' => 'Flera företag per användare', 'status' => self::LIVE, 'readme' => 'Flera företag per användare med roller'],
        'roles' => ['name' => 'Roller för konsult, attestant och revisor', 'status' => self::LIVE, 'readme' => 'ägare, administratör, redovisningskonsult, bokförare, attestant och revisor'],
        'firm_overview' => ['name' => 'Mina företag', 'status' => self::LIVE, 'readme' => 'summor och en gemensam "Att göra" för alla klienter, med filter per konsult, deadline och typ'],
        'month_close' => ['name' => 'Månadsstängning per klient', 'status' => self::LIVE, 'readme' => '**Månadsstängning:** en rad per klient'],
        'tasks' => ['name' => 'Uppgifter mellan byrå och kund', 'status' => self::LIVE, 'readme' => 'delegera mellan byrån och kundens användare åt båda hållen'],
        'search' => ['name' => 'Sök med ⌘K', 'status' => self::LIVE, 'readme' => '**Sök med ⌘K:** en sökning över allt du har behörighet att se'],
        'overview' => ['name' => 'Översikt med att göra och prognos', 'status' => self::LIVE, 'readme' => 'Nyckeltal för företagaren'],
        'firm_branding' => ['name' => 'Byråns varumärke hos kunderna', 'status' => self::LIVE, 'readme' => 'Redovisningsbyråns varumärke: en byrå kan visa sin logga och sina färger för sina kunder'],
        'company_archive' => ['name' => 'Överlåtelse och arkivering av företag', 'status' => self::LIVE, 'readme' => 'ett företag kan arkiveras så att det bara kan läsas'],
        'installable_app' => ['name' => 'App på hemskärmen', 'status' => self::LIVE, 'readme' => 'NONOX installeras som app på hemskärmen'],
        'passkeys' => ['name' => 'Passkey för känsliga uppgifter', 'status' => self::LIVE, 'readme' => 'Passkey som andra steg vid inloggning'],
        'foreign_currency_purchases' => ['name' => 'Leverantörsfakturor i utländsk valuta', 'status' => self::LIVE, 'readme' => 'kursvinst eller kursförlust bokförs när fakturan betalas'],
        'foreign_currency_sales' => ['name' => 'Kundfakturor i utländsk valuta', 'status' => self::LIVE, 'readme' => 'Kundfakturor i utländsk valuta'],
        'sie' => ['name' => 'SIE4 in och ut', 'status' => self::LIVE, 'readme' => 'inläsning av SIE4 i två steg med avstämning'],
        'fortnox_import' => ['name' => 'Direkt flytt från Fortnox', 'status' => self::LIVE, 'readme' => 'direkt flytt från Fortnox via deras API'],
        'archive' => ['name' => 'Arkivpaket per räkenskapsår', 'status' => self::LIVE, 'readme' => 'Arkivpaket per räkenskapsår'],
        'audit_log' => ['name' => 'Behandlingshistorik', 'status' => self::LIVE, 'readme' => 'Behandlingshistorik över allt som görs'],
        'branding' => ['name' => 'Egen logga och färger', 'status' => self::LIVE, 'readme' => 'Eget varumärke: logga och färger'],
        'payment_file_matching' => ['name' => 'Automatisk avstämning av betalfiler', 'status' => self::LIVE, 'readme' => 'Automatisk avstämning av betalfiler mot bankens svar'],
        'non_eu_goods_import' => ['name' => 'Import av varor från länder utanför EU', 'status' => self::SOON, 'readme' => 'Import av varor från länder utanför EU'],
        'ixbrl' => ['name' => 'Digital inlämning av årsredovisningen', 'status' => self::SOON, 'readme' => 'Digital inlämning av årsredovisningen till Bolagsverket (iXBRL)'],
        'public_api' => ['name' => 'Öppet API med webhooks', 'status' => self::LIVE, 'readme' => 'webhooks som signeras', 'module' => 'NONOX Connect'],
        'pos_integrations' => ['name' => 'Färdiga kopplingar från kassasystem', 'status' => self::SOON, 'readme' => 'Färdiga kopplingar från kassasystem'],
    ];

    /**
     * Words a public page may only use for a feature that is built. A page
     * that mentions one of these while the feature is coming fails a test.
     *
     * @var array<string, list<string>>
     */
    private const array SOON_PHRASES = [
        'payment_file_matching' => ['avstämning av betalfiler'],
        'non_eu_goods_import' => ['import av varor'],
        'ixbrl' => ['iXBRL', 'digital inlämning av årsredovisningen'],
        'pos_integrations' => ['direktkoppling', 'kopplas direkt'],
    ];

    /** @return array<string, array{name: string, status: string, readme: string, module?: string}> */
    public static function all(): array
    {
        return self::FEATURES;
    }

    /** @return array{name: string, status: string, readme: string, module?: string} */
    public static function get(string $key): array
    {
        return self::FEATURES[$key] ?? throw new InvalidArgumentException("Unknown feature '{$key}'.");
    }

    public static function isLive(string $key): bool
    {
        return isset(self::FEATURES[$key]) && self::FEATURES[$key]['status'] === self::LIVE;
    }

    /** @return list<string> */
    public static function soonPhrases(): array
    {
        $phrases = [];

        foreach (self::SOON_PHRASES as $key => $words) {
            if (! self::isLive($key)) {
                array_push($phrases, ...$words);
            }
        }

        return $phrases;
    }
}
