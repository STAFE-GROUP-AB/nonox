<?php

declare(strict_types=1);

/*
 * ADR-058 stage E.2: the K2 annual report of an aktiebolag as Inline XBRL,
 * rendered from the same AnnualReportDocument::data() as the PDF.
 *
 * Synthetic K2 company (kronor), two years:
 *   2026: share capital 25 000, retained 50 000 (opening); sales 100 000,
 *   costs 20 000, tax 16 480 → result 63 520; closed.
 *   2027: the meeting on 20 May 2027 disposes of 63 520: dividend 30 000,
 *   33 520 carried forward. Sales 150 000 (+50 %), other external costs
 *   25 000 (5 000 of them unpaid), personnel 30 000, a building 200 000
 *   bought with a bank loan, depreciation 4 000, interest 1 500, tax 10 000.
 */

use App\Actions\AnnualReport\SaveAnnualReport;
use App\Actions\Banking\BankReconciliations;
use App\Actions\Closing\YearEndClosing;
use App\Domain\Accounting\AccountType;
use App\Domain\AnnualReport\AnnualReportDocument;
use App\Domain\AnnualReport\K2Taxonomy;
use App\Domain\Closing\ClosingChecklist;
use App\Domain\Company\LegalForm;
use App\Domain\Company\VatPeriod;
use App\Livewire\Accounting\AnnualReportPage;
use App\Models\Account;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\OpeningBalance;
use App\Support\Ixbrl\IxbrlRefused;
use App\Support\Ixbrl\K2AnnualReportIxbrl;
use App\Support\Ixbrl\TaxonomyConcepts;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Toast;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2028-03-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['vat_period' => VatPeriod::None, 'org_number' => '556000-0001', 'legal_name' => 'Exempelbolaget AB'])->save();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $previous = FiscalYear::query()->whereDate('start_date', '2026-01-01')->firstOrFail();
    $closing = app(YearEndClosing::class);

    foreach ([1930 => 7_500_000, 2081 => -2_500_000, 2091 => -5_000_000] as $number => $ore) {
        $account = Account::query()->firstOrCreate(['number' => $number], ['name' => "Konto {$number}", 'type' => AccountType::forNumber($number)]);
        OpeningBalance::create(['fiscal_year_id' => $previous->id, 'account_id' => $account->id, 'amount_ore' => $ore]);
    }

    Bookkeeping::post($this->owner, $this->company, 10_000_000, 1930, 3001, '2026-03-01');
    Bookkeeping::post($this->owner, $this->company, 2_000_000, 5010, 1930, '2026-04-01');
    app(BankReconciliations::class)->save($this->owner, $this->company, Account::query()->where('number', 1930)->firstOrFail(), '2026-12-31', '155 000', [], null);

    foreach (array_keys(ClosingChecklist::MANUAL) as $key) {
        $closing->check($this->owner, $previous, $key, true);
    }

    $closing->bookTax($this->owner, $previous, '16 480');
    $closing->bookResult($this->owner, $previous);
    $closing->close($this->owner, $previous);
    $closing->dispose($this->owner, $previous, '2027-05-20', '30 000');

    foreach ([1110 => 'Byggnader', 1119 => 'Ackumulerade avskrivningar på byggnader', 2350 => 'Skulder till kreditinstitut', 7820 => 'Avskrivningar på byggnader', 8410 => 'Räntekostnader'] as $number => $name) {
        Account::query()->firstOrCreate(['number' => $number], ['name' => $name, 'type' => AccountType::forNumber($number)]);
    }

    Bookkeeping::post($this->owner, $this->company, 15_000_000, 1930, 3001, '2027-03-01');
    Bookkeeping::post($this->owner, $this->company, 2_000_000, 5010, 1930, '2027-04-01');
    Bookkeeping::post($this->owner, $this->company, 500_000, 5010, 2440, '2027-12-15');
    Bookkeeping::post($this->owner, $this->company, 3_000_000, 7010, 1930, '2027-06-25');
    Bookkeeping::post($this->owner, $this->company, 20_000_000, 1110, 2350, '2027-02-01');
    Bookkeeping::post($this->owner, $this->company, 400_000, 7820, 1119, '2027-12-31');
    Bookkeeping::post($this->owner, $this->company, 150_000, 8410, 1930, '2027-12-31');

    $this->year = FiscalYear::query()->whereDate('start_date', '2027-01-01')->firstOrFail();
    $closing->bookTax($this->owner, $this->year, '10 000');

    $this->report = app(SaveAnnualReport::class)($this->owner, $this->year, [
        'business_description' => "Bolaget driver ett kafé i Exempelstad.\nSätet är i Exempelstad.",
        'significant_events' => 'Bolaget köpte sin lokal.',
        'net_sales_comment' => 'Kaféet hade öppet hela året & fick en ny stor kund.',
        'dividend' => '20 000',
        'average_employees' => '1',
        'signing_place' => 'Exempelstad',
        'signing_date' => '2028-03-01',
        'signatories' => "Anna Andersson, Styrelseledamot\nBo Berg, Verkställande direktör",
        'adoption_date' => '2028-04-15',
        'certifier_name' => 'Anna Andersson',
        'certifier_role' => 'Styrelseledamot',
        'pledged' => ['company_mortgage' => '100 000'],
        'contingent_liabilities' => '5 000',
        'contingent_liabilities_text' => 'Borgen för en leverantörs lån',
        'events_after' => 'Bolaget har öppnat ett andra kafé.',
    ]);
});

/** Parses the iXBRL file, failing on any XML error. */
function ixbrlK2Document(string $xhtml): DOMDocument
{
    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument;
    $loaded = $document->loadXML($xhtml, LIBXML_NONET);
    $errors = array_map(fn (LibXMLError $error): string => trim($error->message)." (line {$error->line})", libxml_get_errors());
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    expect($loaded)->toBeTrue()->and($errors)->toBe([]);

    return $document;
}

function ixbrlK2Xpath(DOMDocument $document): DOMXPath
{
    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('h', 'http://www.w3.org/1999/xhtml');
    $xpath->registerNamespace('ix', 'http://www.xbrl.org/2013/inlineXBRL');
    $xpath->registerNamespace('xbrli', 'http://www.xbrl.org/2003/instance');
    $xpath->registerNamespace('link', 'http://www.xbrl.org/2003/linkbase');
    $xpath->registerNamespace('xlink', 'http://www.w3.org/1999/xlink');

    return $xpath;
}

/**
 * Every numeric fact as "concept|period" => list of values in kronor (or
 * the ratio), with sign and scale applied, the period written as
 * "i:2027-12-31" or "d:2027-01-01..2027-12-31".
 *
 * @return array<string, list<float>>
 */
function ixbrlK2Facts(DOMDocument $document): array
{
    $xpath = ixbrlK2Xpath($document);
    $periods = [];

    foreach ($xpath->query('//xbrli:context') ?: [] as $context) {
        /** @var DOMElement $context */
        $instant = $xpath->evaluate('string(xbrli:period/xbrli:instant)', $context);
        $periods[$context->getAttribute('id')] = $instant !== ''
            ? "i:{$instant}"
            : 'd:'.$xpath->evaluate('string(xbrli:period/xbrli:startDate)', $context).'..'.$xpath->evaluate('string(xbrli:period/xbrli:endDate)', $context);
    }

    $facts = [];

    foreach ($xpath->query('//ix:nonFraction') ?: [] as $fact) {
        /** @var DOMElement $fact */
        $number = str_replace([' ', "\u{a0}", ','], ['', '', '.'], trim($fact->textContent));
        expect($number)->toMatch('/^\d+(\.\d+)?$/');
        $value = (float) $number * 10 ** (int) ($fact->getAttribute('scale') ?: 0) * ($fact->getAttribute('sign') === '-' ? -1 : 1);
        $facts[$fact->getAttribute('name').'|'.$periods[$fact->getAttribute('contextRef')]][] = $value;
    }

    return $facts;
}

/** The text of every amount cell (td.num), in document order. */
function ixbrlK2AmountCells(DOMDocument $document): array
{
    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('h', 'http://www.w3.org/1999/xhtml');
    $isXhtml = $document->documentElement?->namespaceURI === 'http://www.w3.org/1999/xhtml';
    $query = $isXhtml ? "//h:td[contains(concat(' ', normalize-space(@class), ' '), ' num ')]" : "//td[contains(concat(' ', normalize-space(@class), ' '), ' num ')]";

    return array_map(fn (DOMNode $cell): string => trim($cell->textContent), iterator_to_array($xpath->query($query) ?: []));
}

function ixbrlK2Html(string $html): DOMDocument
{
    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument;
    $document->loadHTML('<?xml encoding="UTF-8">'.$html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return $document;
}

/** The fact value of an amount in öre printed in the report's direction. */
function ixbrlK2Expected(string $concept, int $ore, string $orientation): int
{
    [, $balance] = K2Taxonomy::concept($concept);
    $kronor = intdiv($ore, 100);

    return $balance === null || $balance === $orientation ? $kronor : -$kronor;
}

it('tags every amount the PDF prints, with the same number as in AnnualReportDocument::data()', function () {
    $xhtml = K2AnnualReportIxbrl::render($this->company, $this->year, $this->report);
    $document = ixbrlK2Document($xhtml);

    // The amount cells read the same, in the same order, as in the PDF's HTML.
    $pdfCells = ixbrlK2AmountCells(ixbrlK2Html(AnnualReportDocument::html($this->company, $this->year, $this->report)));
    expect(ixbrlK2AmountCells($document))->toBe($pdfCells)->and(count($pdfCells))->toBeGreaterThan(80);

    // Every printed amount cell holds exactly one tagged fact.
    $xpath = ixbrlK2Xpath($document);

    foreach ($xpath->query("//h:td[contains(concat(' ', normalize-space(@class), ' '), ' num ')]") ?: [] as $cell) {
        $text = trim($cell->textContent);

        if ($text !== '' && $text !== '–') {
            expect($xpath->query('.//ix:nonFraction', $cell)?->length)->toBe(1, "The cell '{$text}' is not tagged once.");
        }
    }

    // Each fact against the figures behind the PDF.
    $data = AnnualReportDocument::data($this->company, $this->year, $this->report);
    $facts = ixbrlK2Facts($document);
    $year = 'd:2027-01-01..2027-12-31';
    $before = 'd:2026-01-01..2026-12-31';
    $end = 'i:2027-12-31';
    $start = 'i:2026-12-31';
    $expected = [];
    $add = function (?string $concept, string $period, int|float $value) use (&$expected): void {
        expect($concept)->not->toBeNull();
        $expected["{$concept}|{$period}"][] = (float) $value;
    };

    foreach ($data['income'] as $line) {
        $add(K2Taxonomy::INCOME[$line['key']], $year, ixbrlK2Expected(K2Taxonomy::INCOME[$line['key']], $line['amount'], 'credit'));
        $add(K2Taxonomy::INCOME[$line['key']], $before, ixbrlK2Expected(K2Taxonomy::INCOME[$line['key']], (int) $line['previous'], 'credit'));
    }

    foreach ($data['balance']['sections'] as $section) {
        $orientation = in_array($section['key'], ['fixed', 'current'], true) ? 'debit' : 'credit';

        foreach ($section['lines'] as $line) {
            $add(K2Taxonomy::BALANCE[$line['key']], $end, intdiv($line['amount'], 100));
            $add(K2Taxonomy::BALANCE[$line['key']], $start, intdiv($line['previous'], 100));
        }

        $add(K2Taxonomy::SECTIONS[$section['key']], $end, ixbrlK2Expected(K2Taxonomy::SECTIONS[$section['key']], $section['amount'], $orientation));
        $add(K2Taxonomy::SECTIONS[$section['key']], $start, ixbrlK2Expected(K2Taxonomy::SECTIONS[$section['key']], $section['previous'], $orientation));
    }

    $add(K2Taxonomy::TOTALS['assets'], $end, intdiv($data['balance']['assets'], 100));
    $add(K2Taxonomy::TOTALS['equity_liabilities'], $end, intdiv($data['balance']['equity_liabilities'], 100));

    foreach ($data['multiYear'] as $row) {
        $add(K2Taxonomy::MULTI_YEAR['net_sales'], "d:{$row['start']}..{$row['end']}", intdiv($row['net_sales'], 100));
        $add(K2Taxonomy::MULTI_YEAR['solidity'], "i:{$row['end']}", round((float) $row['solidity'] / 100, 3));
    }

    foreach ($data['equityChanges'] as $row) {
        $period = match ($row['key']) {
            'opening' => $start,
            'closing' => $end,
            default => $year,
        };
        $add(K2Taxonomy::EQUITY[$row['key']]['total'], $period, intdiv($row['total'], 100));
    }

    $add(K2Taxonomy::DISPOSITION['dividend'], $end, 20_000);
    $add(K2Taxonomy::DISPOSITION['total'], $end, intdiv($data['disposition']['free'], 100));
    $add(K2Taxonomy::PLEDGED['company_mortgage'], $end, 100_000);
    $add(K2Taxonomy::FACTS['contingent'], $end, 5_000);

    [$building] = $data['fixedAssets'];
    $notes = K2Taxonomy::FIXED_ASSET_NOTES['buildings'];
    $add($notes['additions'], $year, 200_000);
    $add($notes['acquisition'], $end, 200_000);
    $add($notes['charge'], $year, -4_000);
    $add($notes['depreciation'], $end, 4_000);
    $add($notes['book_value'], $end, intdiv($building['book_value'], 100));

    foreach ($expected as $key => $values) {
        expect($facts)->toHaveKey($key);

        // A concept tagged twice in a context (a line and its section's sum) carries the same value.
        foreach ($facts[$key] as $value) {
            expect($value)->toEqualWithDelta($values[0], 0.000001, $key);
        }
    }

    // Hand-computed checks of the fixture.
    expect($facts['se-gen-base:Nettoomsattning|'.$year])->each->toBe(150_000.0)
        ->and($facts['se-gen-base:OvrigaExternaKostnader|'.$year])->toBe([25_000.0])
        ->and($facts['se-gen-base:Personalkostnader|'.$year])->toBe([30_000.0])
        ->and($facts['se-gen-base:RantekostnaderLiknandeResultatposter|'.$year])->toBe([1_500.0])
        ->and($facts['se-gen-base:SkattAretsResultat|'.$year])->toBe([10_000.0])
        ->and($facts['se-gen-base:AretsResultat|'.$year])->toBe([79_500.0])
        ->and($facts['se-gen-base:AretsResultat|'.$before])->toBe([63_520.0])
        ->and($facts['se-gen-base:ForandringEgetKapitalAretsResultatUtdelning|'.$year])->toBe([-30_000.0])
        ->and($facts['se-gen-base:Tillgangar|'.$end])->toBe($facts['se-gen-base:EgetKapitalSkulder|'.$end])
        ->and($facts['se-gen-base:Leverantorsskulder|'.$end])->toBe([5_000.0])
        ->and($facts['se-gen-base:Aktiekapital|'.$end])->each->toBe(25_000.0)
        // Hidden subtotals of the calculation linkbase add up to the printed lines.
        ->and($facts['se-gen-base:Rorelsekostnader|'.$year])->toBe([59_000.0])
        ->and($facts['se-gen-base:FinansiellaPoster|'.$year])->toBe([-1_500.0])
        ->and($facts['se-gen-base:MateriellaAnlaggningstillgangar|'.$end])->toBe([196_000.0]);

    // A cost is printed with its minus outside the tag; the fact is positive.
    expect($xhtml)->toContain('-<ix:nonFraction name="se-gen-base:OvrigaExternaKostnader"');
});

it('tags the texts, the company, the fiscal year and the fastställelseintyg, and leaves the signatures to Bolagsverket', function () {
    $xpath = ixbrlK2Xpath(ixbrlK2Document(K2AnnualReportIxbrl::render($this->company, $this->year, $this->report)));
    $text = fn (string $concept): string => trim((string) $xpath->evaluate("string(//ix:nonNumeric[@name='{$concept}'])"));

    expect($text('se-cd-base:ForetagetsNamn'))->toBe('Exempelbolaget AB')
        ->and($text('se-cd-base:Organisationsnummer'))->toBe('556000-0001')
        ->and($text('se-cd-base:RakenskapsarForstaDag'))->toBe('2027-01-01')
        ->and($text('se-cd-base:RakenskapsarSistaDag'))->toBe('2027-12-31')
        ->and($text('se-gen-base:AllmantVerksamheten'))->toBe("Bolaget driver ett kafé i Exempelstad.\nSätet är i Exempelstad.")
        ->and($text('se-gen-base:KommentarFlerarsoversikt'))->toBe('Kaféet hade öppet hela året & fick en ny stor kund.')
        ->and($text('se-gen-base:NotVasentligaHandelserRakenskapsaretsSlut'))->toBe('Bolaget har öppnat ett andra kafé.')
        ->and($text('se-gen-base:NotEventualForpliktelserKommentar'))->toBe('Borgen för en leverantörs lån')
        ->and($text('se-gen-base:UndertecknandeArsredovisningDatum'))->toBe('2028-03-01')
        ->and($text('se-comp-base:FaststallelseResultatBalansrakning'))->toBe(K2Taxonomy::ADOPTION_TEXT)
        ->and($text('se-comp-base:Arsstamma'))->toBe('2028-04-15')
        ->and($text('se-comp-base:IntygandeOriginalInnehall'))->toBe(K2Taxonomy::ORIGINAL_TEXT)
        ->and($text('se-cd-base:SprakHandlingUpprattadList'))->toBe('se-mem-base:SprakSvenskaMember')
        ->and($xpath->query("//ix:nonFraction[@name='se-gen-base:MedelantaletAnstallda']")?->item(0)?->textContent)->toBe('1')
        ->and($xpath->query("//*[starts-with(@name, 'se-comp-base:Underskrift')]")?->length)->toBe(0);

    // Without an adoption date, the certificate is left out of the file, with a note.
    $this->report->forceFill(['adoption_date' => null])->save();
    $xhtml = K2AnnualReportIxbrl::render($this->company, $this->year, $this->report->fresh());

    expect($xhtml)->not->toContain('se-comp-base:Arsstamma')
        ->and($xhtml)->toContain('Fastställelsedagen är inte ifylld.');
});

it('is well-formed XHTML whose facts all point at declared namespaces, contexts and units', function () {
    $document = ixbrlK2Document(K2AnnualReportIxbrl::render($this->company, $this->year, $this->report));
    $xpath = ixbrlK2Xpath($document);
    $root = $document->documentElement;

    expect($root?->namespaceURI)->toBe('http://www.w3.org/1999/xhtml')
        ->and($root?->localName)->toBe('html')
        ->and($xpath->query('//ix:header')?->length)->toBe(1)
        ->and(array_map(fn (DOMElement $ref): string => $ref->getAttributeNS('http://www.w3.org/1999/xlink', 'href'), iterator_to_array($xpath->query('//ix:references/link:schemaRef') ?: [])))
        ->toBe([K2Taxonomy::ENTRY_POINT, K2Taxonomy::CERTIFICATE_ENTRY_POINT]);

    $contexts = array_map(fn (DOMElement $element): string => $element->getAttribute('id'), iterator_to_array($xpath->query('//xbrli:context') ?: []));
    $units = array_map(fn (DOMElement $element): string => $element->getAttribute('id'), iterator_to_array($xpath->query('//xbrli:unit') ?: []));
    $ids = array_map(fn (DOMAttr $id): string => $id->value, iterator_to_array($xpath->query('//@id') ?: []));

    expect($ids)->toBe(array_values(array_unique($ids)))
        ->and($xpath->evaluate('string(//xbrli:context[1]/xbrli:entity/xbrli:identifier)'))->toBe('5560000001');

    foreach ($xpath->query('//ix:nonFraction | //ix:nonNumeric') ?: [] as $fact) {
        /** @var DOMElement $fact */
        [$prefix] = explode(':', $fact->getAttribute('name'));

        expect($fact->lookupNamespaceURI($prefix))->toBe(K2Taxonomy::NAMESPACES[$prefix] ?? null)
            ->and($contexts)->toContain($fact->getAttribute('contextRef'));

        if ($fact->localName === 'nonFraction') {
            expect($units)->toContain($fact->getAttribute('unitRef'))
                ->and($fact->getAttribute('decimals'))->not->toBe('');
        }
    }

    // The XHTML shell: no lang beside xml:lang, no empty class, no empty tbody (Arelle, ADR-058 E.3).
    expect($root?->hasAttribute('lang'))->toBeFalse()
        ->and($xpath->query("//*[@class and normalize-space(@class)='']")?->length)->toBe(0)
        ->and($xpath->query('//h:tbody[not(h:tr)]')?->length)->toBe(0);

    // Contexts and units are only those the facts use.
    $used = array_map(fn (DOMAttr $ref): string => $ref->value, iterator_to_array($xpath->query('//@contextRef') ?: []));
    expect(array_diff($contexts, $used))->toBe([]);
});

it('refuses, naming the line, when the report prints something the taxonomy map cannot tag', function () {
    // Maskiner och inventarier: NONOX prints one line, the K2 taxonomy has two.
    Bookkeeping::post($this->owner, $this->company, 1_000_000, 1220, 1930, '2027-09-01');

    expect(fn () => K2AnnualReportIxbrl::render($this->company, $this->year, $this->report))
        ->toThrow(IxbrlRefused::class, 'Balansräkning: Maskiner och inventarier');

    $this->from(route('accounting.annualReport'))
        ->get(route('accounting.annualReport.ixbrl', $this->year))
        ->assertRedirect(route('accounting.annualReport'));

    expect(session(Toast::SESSION_KEY)[0]['type'])->toBe('error')
        ->and(session(Toast::SESSION_KEY)[0]['message'])->toContain('Maskiner och inventarier');
});

it('refuses a missing business description, a note without a concept and another legal form', function () {
    $refusal = function (): array {
        try {
            K2AnnualReportIxbrl::render($this->company, $this->year, $this->report->fresh());
        } catch (IxbrlRefused $refused) {
            return $refused->items;
        }

        return [];
    };

    $this->report->forceFill(['business_description' => '', 'group_commitments' => 'Borgen för dotterbolagets lån.'])->save();

    expect($refusal())->toBe(['Allmänt om verksamheten (texten saknas)', 'Not 6 Åtaganden till förmån för koncern- och intresseföretag']);

    $this->company->forceFill(['legal_form' => LegalForm::EkonomiskForening])->save();

    expect($refusal())->toBe(['Företagsformen']);
});

it('maps every concept it uses, and every concept exists in the taxonomy when BOLAGSVERKET_TAXONOMY_DIR is set', function () {
    foreach (K2Taxonomy::mapped() as $concept) {
        expect(K2Taxonomy::CONCEPTS)->toHaveKey($concept);
        [$prefix] = explode(':', $concept);
        expect(K2Taxonomy::NAMESPACES)->toHaveKey($prefix);
    }

    $directory = (string) config('services.bolagsverket.taxonomy_dir');

    if ($directory === '' || ! is_dir($directory)) {
        $this->markTestSkipped('BOLAGSVERKET_TAXONOMY_DIR is not set. The taxonomy package is not in the repository (ADR-058 §4); set it to an unpacked package from taxonomier.se to check every concept, period type and balance.');
    }

    $problems = TaxonomyConcepts::fromDirectory($directory)->problems(array_keys(K2Taxonomy::CONCEPTS), K2Taxonomy::MEMBERS, [K2Taxonomy::ENTRY_POINT, K2Taxonomy::CERTIFICATE_ENTRY_POINT]);

    expect($problems)->toBe([]);

    // With the taxonomy configured the generated file is checked before it is offered.
    expect(K2AnnualReportIxbrl::render($this->company, $this->year, $this->report))->toContain('<ix:header>');
});

it('finds what does not agree with a taxonomy', function () {
    $directory = sys_get_temp_dir().'/nonox-ixbrl-'.bin2hex(random_bytes(4));
    $schema = $directory.'/'.K2Taxonomy::SCHEMAS['se-gen-base'];
    mkdir(dirname($schema), 0777, true);
    file_put_contents($schema, '<?xml version="1.0"?><xsd:schema xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xbrli="http://www.xbrl.org/2003/instance" targetNamespace="'.K2Taxonomy::NAMESPACES['se-gen-base'].'">'
        .'<xsd:element name="Nettoomsattning" type="xbrli:monetaryItemType" xbrli:periodType="duration" xbrli:balance="debit"/>'
        .'<xsd:element name="Tillgangar" type="xbrli:monetaryItemType" xbrli:periodType="instant" xbrli:balance="debit"/></xsd:schema>');

    $problems = TaxonomyConcepts::fromDirectory($directory)->problems(['se-gen-base:Nettoomsattning', 'se-gen-base:Tillgangar', 'se-gen-base:KassaBank'], [], [K2Taxonomy::ENTRY_POINT]);

    expect(implode("\n", $problems))->toContain('se-gen-base:Nettoomsattning stämmer inte')
        ->toContain('se-gen-base:KassaBank finns inte')
        ->toContain('se-cd-base')
        ->toContain('ingångspunkten')
        ->not->toContain('se-gen-base:Tillgangar');

    unlink($schema);
});

it('is downloaded with the annual report permission, by an aktiebolag, and never shows another company\'s year', function () {
    $response = $this->get(route('accounting.annualReport.ixbrl', $this->year));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/xhtml+xml; charset=UTF-8')
        ->and($response->headers->get('Content-Disposition'))->toContain('Arsredovisning-5560000001-2027.xhtml')
        ->and((string) $response->getContent())->toStartWith('<?xml version="1.0" encoding="UTF-8"?>');

    Livewire::test(AnnualReportPage::class)
        ->set('year', $this->year->id)
        ->assertSee('Ladda ner som iXBRL (Beta)')
        ->assertSee('NONOX lämnar inte in filen åt dig än');

    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    $other = Company::factory()->create();
    $foreign = CurrentCompany::run($other, fn () => FiscalYear::create(['start_date' => '2027-01-01', 'end_date' => '2027-12-31']));

    $this->get(route('accounting.annualReport.ixbrl', $foreign))->assertNotFound();

    $this->actingAs($bookkeeper);
    $this->get(route('accounting.annualReport.ixbrl', $this->year))->assertForbidden();

    $this->company->forceFill(['legal_form' => LegalForm::EkonomiskForening])->save();
    $this->actingAs($this->owner->fresh());

    Livewire::test(AnnualReportPage::class)
        ->set('year', $this->year->id)
        ->assertDontSee('Ladda ner som iXBRL (Beta)');
});
