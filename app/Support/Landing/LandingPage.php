<?php

declare(strict_types=1);

namespace App\Support\Landing;

use App\Support\Build\BuildInfo;

/**
 * The content of the public landing page (resources/views/start/landing).
 * Links that differ between installations come from config('app.landing');
 * a link that is not configured is left out of the page.
 */
final class LandingPage
{
    /**
     * The hosted plans: the highest number of employees each covers and the
     * price per company and year excluding VAT, in kronor (null: on request).
     *
     * The comparison is the design's estimate of the largest cloud service's
     * public list prices for bookkeeping, invoicing and payroll in October
     * 2026, per month: a base price plus a price per employee (none for a
     * company without employees).
     */
    public const array PLANS = [
        ['name' => 'Företag', 'employees' => '0–10', 'max' => 10, 'price' => 995, 'popular' => true, 'compare_base' => 422, 'compare_per_employee' => 30],
        ['name' => 'Större företag', 'employees' => '11–25', 'max' => 25, 'price' => 1995, 'popular' => false, 'compare_base' => 858, 'compare_per_employee' => 30],
        ['name' => 'Offert', 'employees' => '26+', 'max' => null, 'price' => null, 'popular' => false, 'compare_base' => 0, 'compare_per_employee' => 0],
    ];

    public const int DEFAULT_EMPLOYEES = 3;

    /** @return array<string, mixed> */
    public static function data(): array
    {
        $ph = self::links();

        return [
            'ph' => $ph,
            'dockerCommands' => "git clone {$ph['github']}",
            'securityReview' => SecurityReview::data(),
            'securityTotals' => SecurityReview::totals(),
            'trust' => ['Följer Bokföringslagen', 'BAS-kontoplan', 'SIE4 in och ut', 'Peppol e-faktura', 'Arbetsgivardeklaration (AGI)', 'Data i Sverige/EU'],
            'features' => self::features(),
            'clients' => self::clients(),
            'plans' => array_map(fn (array $plan): array => self::plan($plan, $ph), self::PLANS),
            'includes' => ['Drift på servrar i Sverige/EU', 'Daglig backup', 'Uppdateringar och säkerhet', 'Support för driften', 'Fri export av all data'],
            'calculator' => self::calculator(self::DEFAULT_EMPLOYEES),
            // Every plan but the last has a price; the last means "contact us".
            'calculatorPlans' => array_map(fn (array $plan): array => [
                'name' => $plan['name'],
                'max' => $plan['max'],
                'price' => $plan['price'],
                'compareBase' => $plan['compare_base'],
                'comparePerEmployee' => $plan['compare_per_employee'],
            ], array_slice(self::PLANS, 0, -1)),
            'steps' => self::steps(),
            'faqs' => self::faqs(),
        ];
    }

    /** @return array{label: string, aria: string, employees: int, plan: string, price: string, compare: string, saving: string} */
    public static function calculator(int $employees): array
    {
        $plan = collect(self::PLANS)->first(fn (array $plan): bool => $plan['max'] !== null && $employees <= $plan['max']);
        $label = $employees > 25 ? '26+' : (string) $employees;
        $aria = $employees > 25 ? '26 eller fler anställda' : "{$employees} anställda";

        if ($plan === null) {
            return ['label' => $label, 'aria' => $aria, 'employees' => $employees, 'plan' => '', 'price' => '', 'compare' => '', 'saving' => ''];
        }

        $compare = self::comparePerYear($plan['compare_base'], $plan['compare_per_employee'], $employees);
        $saving = (int) round(($compare - (int) $plan['price']) / 100) * 100;

        return [
            'label' => $label,
            'aria' => $aria,
            'employees' => $employees,
            'plan' => $plan['name'],
            'price' => self::kronor((int) $plan['price']).'/år',
            'compare' => self::kronor($compare).'/år',
            'saving' => self::kronor($saving),
        ];
    }

    /** The hosted price per company and year for this many employees, or null when it is on request. */
    public static function hostedPerYear(int $employees): ?int
    {
        $plan = collect(self::PLANS)->first(fn (array $plan): bool => $plan['max'] !== null && $employees <= $plan['max']);

        return $plan === null ? null : (int) $plan['price'];
    }

    /** The comparison's yearly price: the monthly base, plus a price per employee when there are any. */
    public static function comparePerYear(int $base, int $perEmployee, int $employees): int
    {
        return 12 * ($base + ($employees > 1 ? $perEmployee * $employees : 0));
    }

    public static function kronor(int $kronor): string
    {
        return number_format($kronor, 0, ',', "\u{a0}")."\u{a0}kr";
    }

    /** @return array{version: string, github: string, flytt: string, docs: string, api: string, contact: string, terms: ?string, privacy: ?string, company: ?string, orgnr: ?string, host: string} */
    public static function links(): array
    {
        /** @var array<string, ?string> $config */
        $config = (array) config('app.landing', []);
        $github = $config['github_url'] ?? 'https://github.com/STAFE-GROUP-AB/nonox';

        return [
            'version' => BuildInfo::version(),
            'github' => $github,
            'flytt' => ($config['migration_guide_url'] ?? null) ?: route('migrationGuide'),
            'docs' => $config['docs_url'] ?? "{$github}/tree/main/docs",
            'api' => ($config['api_url'] ?? null) ?: route('developers.home'),
            'contact' => ($config['contact_url'] ?? null) ?: route('waitlist'),
            'terms' => ($config['terms_url'] ?? null) ?: route('legal.terms'),
            'privacy' => ($config['privacy_url'] ?? null) ?: route('legal.privacy'),
            'company' => $config['company_name'] ?? 'Stafe Development AB',
            'orgnr' => $config['org_number'] ?? null,
            'host' => (string) parse_url(url('/'), PHP_URL_HOST),
        ];
    }

    /**
     * @param  array{name: string, employees: string, max: ?int, price: ?int, popular: bool, compare_base: int, compare_per_employee: int}  $plan
     * @param  array<string, ?string>  $ph
     * @return array<string, mixed>
     */
    private static function plan(array $plan, array $ph): array
    {
        $priced = $plan['price'] !== null;

        return [
            'name' => $plan['name'],
            'emp' => "{$plan['employees']} anställda",
            'popular' => $plan['popular'],
            'price' => $priced ? self::kronor((int) $plan['price']) : 'Pris på förfrågan',
            'unit' => $priced ? '/år' : '',
            'priceSize' => $priced ? '40px' : '26px',
            // The hosted service is behind the waiting list during the open beta (ADR-038).
            'cta' => $priced ? 'Ställ dig i kö' : 'Kontakta oss',
            'href' => $priced ? route('waitlist') : $ph['contact'],
            'border' => $plan['popular'] ? '2px solid #FF5A36' : '1px solid var(--line)',
            'btnBg' => $plan['popular'] ? 'var(--ink)' : 'transparent',
            'btnFg' => $plan['popular'] ? 'var(--bg)' : 'var(--ink)',
        ];
    }

    /**
     * What NONOX does today, and what is coming soon (soon: true). Each card
     * names its entry in Features, the single list of what is built, and
     * shows a screenshot from the manual (img, under public/bilder/manual)
     * with a link to the manual article that explains it (man). A test
     * checks that both exist, so a new feature cannot reach the landing
     * page without a picture of it.
     *
     * @return list<array{t: string, img: string, man: string, key: string, b: string, d: string, mod?: bool, soon?: bool, new?: bool}>
     */
    private static function features(): array
    {
        return [
            ['t' => 'Bokföring', 'img' => 'bokforing/bokfor-en-verifikation', 'man' => 'bokforing/bokfor-en-verifikation', 'key' => 'vouchers', 'b' => 'Verifikationer som aldrig kan ändras, kontosaldot medan du konterar, konteringsmallar och alla rapporter med klickbara konton. SIE4 in och ut.', 'd' => 'M4 4h6a2 2 0 0 1 2 2v14a2 2 0 0 0-2-2H4zM20 4h-6a2 2 0 0 0-2 2v14a2 2 0 0 1 2-2h6z'],
            ['t' => 'Fakturering', 'img' => 'fakturering/skapa-en-faktura', 'man' => 'fakturering/skapa-en-faktura', 'key' => 'invoices', 'b' => 'Fakturor med OCR, offerter, order, avtalsfakturor och påminnelser, i kronor eller kundens valuta, med din egen logga och färg.', 'd' => 'M6 3h9l4 4v14H6zM14 3v5h5M9 12h7M9 16h5'],
            ['t' => 'Leverantörsfakturor', 'img' => 'leverantorer/attest', 'man' => 'leverantorer/attest', 'key' => 'supplier_invoices', 'b' => 'Attest, kreditnotor, utländsk valuta med kursdifferenser och betalfiler till alla stora svenska banker, som stäms av automatiskt mot bankens svar.', 'd' => 'M3 13l3-8h12l3 8v6H3zM3 13h5l1 2h6l1-2h5'],
            ['t' => 'Lager', 'img' => 'lager/plocklista', 'man' => 'lager/oversikt', 'key' => 'inventory', 'mod' => true, 'new' => true, 'b' => 'Lagerställen i hyllor och fack, inleveranser, plocklistor och etiketter med streckkod, och du skannar med mobilen. Vid bokslutet räknar NONOX fram lagervärdet.', 'd' => 'M21 8l-9-5-9 5v8l9 5 9-5zM3 8l9 5 9-5M12 13v8'],
            ['t' => 'Bank', 'img' => 'bank/bankavstamning', 'man' => 'bank/bankavstamning', 'key' => 'bank_reconciliation', 'b' => 'Inbetalningar läses in från bankens fil och matchas mot OCR. Bankavstämning per konto.', 'd' => 'M3 10l9-6 9 6M5 10v8M9.5 10v8M14.5 10v8M19 10v8M3 20h18'],
            ['t' => 'Moms och EU', 'img' => 'moms/momsdeklaration', 'man' => 'moms/momsdeklaration', 'key' => 'vat_return', 'b' => 'Momsdeklaration som fil, EU- och exportfakturor, periodisk sammanställning, OSS och VIES-kontroll.', 'd' => 'M19 5L5 19M7 9a2 2 0 1 0 0-4 2 2 0 0 0 0 4zM17 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4z'],
            ['t' => 'Bokslut och årsredovisning', 'img' => 'bokslut/checklistan', 'man' => 'bokslut/checklistan', 'key' => 'year_end', 'b' => 'Checklista, periodiseringar, anläggningsregister där tillgången skapas när inköpet bokförs, årsredovisning enligt K2 och inkomstdeklaration (INK2).', 'd' => 'M4 6h16v14H4zM4 10h16M8 3v4M16 3v4M9 15l2 2 4-4'],
            ['t' => 'Lön', 'img' => 'lon/lonekorning', 'man' => 'lon/lonekorning', 'key' => 'payroll', 'mod' => true, 'b' => 'Skattetabeller, semester, sjuklön, förmåner, lönefil till banken och arbetsgivardeklaration.', 'd' => 'M3 7h16a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H3zM3 7l12-3v3M16 13.5h2'],
            ['t' => 'Min sida för anställda', 'img' => 'personal/min-sida', 'man' => 'lon/min-sida-for-anstallda', 'key' => 'employee_portal', 'b' => 'Lönebesked, utlägg med kvitto, sjukanmälan och semesteransökan direkt i mobilen.', 'd' => 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 21a8 8 0 0 1 16 0'],
            ['t' => 'ROT, RUT och byggmoms', 'img' => 'fakturering/rot-och-rut', 'man' => 'fakturering/rot-och-rut', 'key' => 'rot_rut', 'b' => 'Avdrag direkt på fakturan och omvänd betalningsskyldighet för byggtjänster.', 'd' => 'M3 11l9-7 9 7M5 10v10h14V10M10 20v-5h4v5'],
            ['t' => 'AI', 'img' => 'leverantorer/registrera-en-leverantorsfaktura', 'man' => 'leverantorer/fota-och-las-fakturan', 'key' => 'ai', 'mod' => true, 'b' => 'Fota kvittot eller fakturan med mobilen: AI läser det direkt och föreslår kontering, med företagets egen AI-nyckel.', 'd' => 'M12 3l1.8 4.7L18.5 9.5l-4.7 1.8L12 16l-1.8-4.7L5.5 9.5l4.7-1.8zM19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z'],
            ['t' => 'Flera bolag', 'img' => 'byra/mina-foretag', 'man' => 'byra/mina-foretag', 'key' => 'multi_company', 'b' => 'Alla bolag under en inloggning, för byråer, koncerner och dig som driver flera företag. En byrå kan visa sin logga och sina färger för sina kunder.', 'd' => 'M3 21V9l6-3v15M9 21V3h8v18M17 11h4v10M3 21h18'],
            ['t' => 'Översikt och sök', 'img' => 'kom-igang/sok', 'man' => 'kom-igang/sok', 'key' => 'search', 'b' => 'Det som brådskar först, statistik med prognos och en sökning med ⌘K som hittar allt på ett ögonblick.', 'd' => 'M11 4a7 7 0 1 0 0 14 7 7 0 0 0 0-14zM20 20l-4-4'],
            ['t' => 'Byrå och kund', 'img' => 'byra/uppgifter-mellan-byra-och-kund', 'man' => 'byra/uppgifter-mellan-byra-och-kund', 'key' => 'tasks', 'b' => 'Uppgifter som delegeras mellan byrån och kunden, månadsstängning per klient och alla klienter på en sida.', 'd' => 'M9 11l3 3 8-8M20 12v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h9'],
            ['t' => 'Arkiv och export', 'img' => 'bokslut/arkivpaket', 'man' => 'bokslut/arkivpaket', 'key' => 'archive', 'b' => 'Arkivpaket per räkenskapsår med allt som Bokföringslagen kräver. Inget är inlåst.', 'd' => 'M4 7h16v13H4zM2 4h20v3H2zM10 11h4'],
            ['t' => 'Planering', 'img' => 'planering/schema', 'man' => 'planering/schemat', 'key' => 'planning', 'mod' => true, 'new' => true, 'b' => 'Schema med lönekostnad och OB enligt avtalet medan du planerar. Planen möter personalliggaren, avvikelserna attesteras och tiden går rakt in i lönen.', 'd' => 'M4 5h16v15H4zM4 9h16M8 3v4M16 3v4M8 13h3M13 13h3M8 16h3'],
            ['t' => 'Personalliggare', 'img' => 'personalliggare/oversikten-och-avvikelser', 'man' => 'personalliggare/oversikten-och-avvikelser', 'key' => 'staff_ledger', 'mod' => true, 'b' => 'Elektronisk personalliggare med incheckning via PIN eller QR-kod på en surfplatta, även utan internet.', 'd' => 'M9 4h6M8 2v4M16 2v4M4 6h16v15H4zM8 11h8M8 15h5'],
            ['t' => 'Inkorg', 'img' => 'leverantorer/inkorgen', 'man' => 'leverantorer/inkorgen', 'key' => 'inbox', 'mod' => true, 'b' => 'En egen e-postadress för fakturor, kvitton och kassarapporter. Bara post från avsändare du godkänt tolkas, resten väntar i karantän.', 'd' => 'M3 13l3-8h12l3 8v6H3zM3 13h5l1 2h6l1-2h5M12 8v3'],
        ];
    }

    /** @return array<int, array<string, string>> */
    private static function clients(): array
    {
        $background = fn (string $value): string => match (true) {
            $value === 'Försenad' => '#FF5A36',
            $value === 'Att göra', str_ends_with($value, 'poster') => '#FFE6DF',
            default => '#F0EEE8',
        };

        return array_map(fn (array $row): array => [
            'ini' => $row[0], 'name' => $row[1], 'booked' => $row[2], 'bank' => $row[3], 'moms' => $row[4], 'agi' => $row[5], 'open' => $row[6],
            'bankBg' => $background($row[3]), 'momsBg' => $background($row[4]), 'agiBg' => $background($row[5]),
        ], [
            ['EX', 'Exempelbolaget AB', '2026-10-31', 'Klar', 'Lämnad', 'Lämnad', '0 kr'],
            ['BB', 'Bageriet i Byn AB', '2026-10-24', '3 poster', 'Att göra', 'Lämnad', '14 200 kr'],
            ['NK', 'Nordljus Konsult HB', '2026-09-30', '12 poster', 'Försenad', 'Ej aktuell', '38 750 kr'],
            ['SG', 'Snickarglädje AB', '2026-10-31', 'Klar', 'Lämnad', 'Att göra', '6 900 kr'],
            ['CH', 'Café Hörnet i Exempelstad', '2026-10-17', '5 poster', 'Att göra', 'Lämnad', '0 kr'],
            ['FF', 'Fjällvind Fastigheter AB', '2026-10-31', 'Klar', 'Kvartal', 'Lämnad', '22 000 kr'],
        ]);
    }

    /** @return list<array{t: string, n: int, bg: string, fg: string, lineOp: float|int}> */
    private static function steps(): array
    {
        $steps = [
            'Exportera SIE4-filer och underlag från ditt nuvarande program.',
            'Läs in allt i NONOX, eller låt importverktyget hämta det åt dig.',
            'Stäm av: saldon, kundfordringar och leverantörsskulder kontrolleras automatiskt.',
            'Kör vidare. Spara arkivet från ditt gamla system i sju år, som lagen kräver.',
        ];

        return array_map(fn (string $text, int $index): array => [
            't' => $text,
            'n' => $index + 1,
            'bg' => $index === 3 ? '#FF5A36' : 'transparent',
            'fg' => $index === 3 ? '#15171C' : 'var(--ink)',
            'lineOp' => $index === 3 ? 0 : 0.35,
        ], $steps, array_keys($steps));
    }

    /** @return list<array{q: string, a: string}> */
    private static function faqs(): array
    {
        return [
            ['q' => 'Hur kommer jag in under den öppna betan?', 'a' => 'Ställ dig i kö och svara på några frågor om ditt företag. Vi släpper in nya företag i den takt vi hinner hjälpa dem, och du får ett mejl när du är godkänd. Har ett företag bjudit in dig behöver du inte stå i kö. Skarp lansering av version 1.0 sker den 1 januari 2027, och det du lägger in under betan ligger kvar.'],
            ['q' => 'Är NONOX ett alternativ till Fortnox?', 'a' => 'Ja. NONOX har bokföring, fakturering, lön, moms, bokslut och årsredovisning, och du flyttar hela historiken från Fortnox med SIE4-filer. Skillnaden är att NONOX är öppen källkod, att alla delar ingår och att du kan köra det själv gratis.'],
            ['q' => 'Vad skiljer NONOX från Fortnox, Visma och Bokio?', 'a' => 'Funktionerna för ett svenskt småföretag är i stort sett desamma. NONOX är öppen källkod, kostar 0 kr att köra själv eller 995 kr om året med drift, och alla delar ingår utan tillval. Jämförelsen på sidan visar funktion för funktion.'],
            ['q' => 'Är NONOX verkligen gratis?', 'a' => 'Ja. Programmet är öppen källkod och gratis att ladda ner och köra själv. Du betalar bara om du vill att vi sköter driften.'],
            ['q' => 'Kan jag köra det på mitt webbhotell?', 'a' => 'Ja, på ett webbhotell med PHP och PostgreSQL. Installationsguiden tar dig igenom stegen.'],
            ['q' => 'Vem ansvarar för vad?', 'a' => 'Kör du själv ansvarar du för installation, drift och säkerhetskopior. Väljer du ett driftpaket sköter vi driften. Programmet tillhandahålls i befintligt skick enligt licensen, och ansvaret för bokföringen ligger alltid hos företaget, precis som i alla bokföringsprogram.'],
            ['q' => 'Följer NONOX Bokföringslagen?', 'a' => 'NONOX är byggt efter Bokföringslagen och Bokföringsnämndens regler, med oföränderliga verifikationer, behandlingshistorik och arkivering.'],
            ['q' => 'Kan jag flytta från mitt nuvarande program?', 'a' => 'Ja, via SIE4 och vårt importverktyg. Se flyttguiden.'],
            ['q' => 'Kan jag sluta när jag vill?', 'a' => 'Ja. Ingen bindningstid, och du exporterar all data med ett klick.'],
            ['q' => 'Finns det en app till mobilen?', 'a' => 'Ja, som webbapp. Logga in i NONOX i mobilens webbläsare och välj Lägg till på hemskärmen (Safari) eller Installera app (Chrome), så öppnas NONOX som en app. Den finns inte i App Store eller Google Play. Ingen bokföring sparas i telefonen. Personalliggarens kiosk installeras på samma sätt på en surfplatta och fungerar även utan internet.'],
            ['q' => 'Var lagras min data?', 'a' => 'Kör du själv: där du väljer. I våra driftpaket: på servrar i Sverige/EU.'],
        ];
    }
}
