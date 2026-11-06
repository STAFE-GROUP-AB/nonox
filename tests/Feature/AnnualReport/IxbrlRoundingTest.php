<?php

declare(strict_types=1);

/*
 * Review of ADR-058 E.2 (2026-11-06): printed figures in whole kronor that
 * add up, signs checked against a hand-written table, user text that is
 * not XML, and a contingent note without an amount.
 *
 * Loss-making fixture with öre, no previous year in NONOX (kronor):
 *   Opening balances 2026: bank 50 000,50, share capital 25 000, retained
 *   earnings 25 000,50.
 *   Bank loan 100 000,25 (2350). Sales 30 000,40. Other external costs
 *   90 000,30 paid and 1 000,49 unpaid (2440). Wages 12 345,67.
 *   Exact result -73 346,06; bank 77 655,18.
 * Each line is rounded to whole kronor (half away from zero) and every sum
 * is the sum of the rounded lines:
 *   income: 30 000 - 91 001 - 12 346 = -73 347
 *   assets: bank 77 655
 *   equity: 25 000 + 25 001 - 73 347 = -23 346
 *   liabilities: 100 000 + 1 000 = 101 000, so equity and liabilities are
 *   77 654 and the 1 krona of rounding goes to Övriga skulder: 77 655.
 *   Opening column: bank 50 001 = 25 000 + 25 001.
 */

use App\Actions\AnnualReport\SaveAnnualReport;
use App\Domain\Accounting\AccountType;
use App\Domain\AnnualReport\AnnualReportDocument;
use App\Domain\Company\VatPeriod;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\OpeningBalance;
use App\Support\Ixbrl\IxbrlRefused;
use App\Support\Ixbrl\K2AnnualReportIxbrl;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-03-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['vat_period' => VatPeriod::None, 'org_number' => '556000-0002', 'legal_name' => 'Förlustbolaget AB'])->save();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->firstOrFail();

    foreach ([1930 => 5_000_050, 2081 => -2_500_000, 2091 => -2_500_050] as $number => $ore) {
        $account = Account::query()->firstOrCreate(['number' => $number], ['name' => "Konto {$number}", 'type' => AccountType::forNumber($number)]);
        OpeningBalance::create(['fiscal_year_id' => $this->year->id, 'account_id' => $account->id, 'amount_ore' => $ore]);
    }

    Account::query()->firstOrCreate(['number' => 2350], ['name' => 'Skulder till kreditinstitut', 'type' => AccountType::forNumber(2350)]);
    Bookkeeping::post($this->owner, $this->company, 10_000_025, 1930, 2350, '2026-02-01');
    Bookkeeping::post($this->owner, $this->company, 3_000_040, 1930, 3001, '2026-03-01');
    Bookkeeping::post($this->owner, $this->company, 9_000_030, 5010, 1930, '2026-04-01');
    Bookkeeping::post($this->owner, $this->company, 100_049, 5010, 2440, '2026-11-01');
    Bookkeeping::post($this->owner, $this->company, 1_234_567, 7010, 1930, '2026-06-25');

    $this->report = app(SaveAnnualReport::class)($this->owner, $this->year, [
        'business_description' => 'Bolaget säljer kaffe i Exempelstad.',
        'average_employees' => '0',
        'adoption_date' => '2027-05-01',
    ]);
});

/** @return array{0: DOMDocument, 1: DOMXPath} */
function ixbrlRoundingParse(string $xhtml): array
{
    $document = new DOMDocument;
    expect($document->loadXML($xhtml, LIBXML_NONET))->toBeTrue();
    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('ix', 'http://www.xbrl.org/2013/inlineXBRL');
    $xpath->registerNamespace('xbrli', 'http://www.xbrl.org/2003/instance');
    $xpath->registerNamespace('h', 'http://www.w3.org/1999/xhtml');

    return [$document, $xpath];
}

/**
 * Every numeric fact as concept (without prefix) => context id => value,
 * and, for the printed ones, [concept, printed minus outside the tag, sign="-", value].
 *
 * @return array{0: array<string, array<string, float>>, 1: list<array{0: string, 1: bool, 2: bool, 3: float}>}
 */
function ixbrlRoundingFacts(DOMXPath $xpath): array
{
    $facts = [];
    $signs = [];

    foreach ($xpath->query('//ix:nonFraction') ?: [] as $fact) {
        /** @var DOMElement $fact */
        $name = explode(':', $fact->getAttribute('name'))[1];
        $negative = $fact->getAttribute('sign') === '-';
        $value = (float) str_replace([' ', "\u{a0}", ','], ['', '', '.'], trim($fact->textContent)) * 10 ** (int) ($fact->getAttribute('scale') ?: 0) * ($negative ? -1 : 1);
        $before = $fact->previousSibling;
        $minus = $before instanceof DOMText && str_ends_with($before->wholeText, '-');
        $context = $fact->getAttribute('contextRef');

        if (isset($facts[$name][$context])) {
            expect($facts[$name][$context])->toEqualWithDelta($value, 0.000001, "{$name} in {$context} is tagged twice with different values");
        }

        $facts[$name][$context] = $value;
        // A hidden fact (a subtotal the report does not print) has no printed sign to compare.
        if ($fact->parentNode?->localName !== 'hidden') {
            $signs[] = [$name, $minus, $negative, $value];
        }
    }

    return [$facts, $signs];
}

it('prints every line rounded to whole kronor and every sum as the sum of the printed lines, in the PDF and in the iXBRL', function () {
    [, $xpath] = ixbrlRoundingParse(K2AnnualReportIxbrl::render($this->company, $this->year, $this->report));
    [$facts] = ixbrlRoundingFacts($xpath);
    $year = 'd2026-01-01_2026-12-31';
    $end = 'i2026-12-31';
    $start = 'i2025-12-31';

    // Hand-written: concept => [context => value in kronor, with the sign of the fact].
    $expected = [
        'Nettoomsattning' => [$year => 30_000],
        'OvrigaExternaKostnader' => [$year => 91_001],
        'Personalkostnader' => [$year => 12_346],
        'Rorelseresultat' => [$year => -73_347],
        'ResultatEfterFinansiellaPoster' => [$year => -73_347],
        'ResultatForeSkatt' => [$year => -73_347],
        'AretsResultat' => [$year => -73_347],
        'RorelseintakterLagerforandringarMm' => [$year => 30_000],
        'Rorelsekostnader' => [$year => 103_347],
        'KassaBank' => [$end => 77_655, $start => 50_001],
        'Omsattningstillgangar' => [$end => 77_655, $start => 50_001],
        'Tillgangar' => [$end => 77_655, $start => 50_001],
        'Aktiekapital' => [$end => 25_000, $start => 25_000],
        'BundetEgetKapital' => [$end => 25_000, $start => 25_000],
        'BalanseratResultat' => [$end => 25_001, $start => 25_001],
        'AretsResultatEgetKapital' => [$end => -73_347, $start => 0],
        'FrittEgetKapital' => [$end => -48_346, $start => 25_001],
        'EgetKapital' => [$end => -23_346, $start => 50_001],
        'OvrigaLangfristigaSkulderKreditinstitut' => [$end => 100_000, $start => 0],
        'LangfristigaSkulder' => [$end => 100_000, $start => 0],
        'Leverantorsskulder' => [$end => 1_000, $start => 0],
        'OvrigaKortfristigaSkulder' => [$end => 1, $start => 0],
        'KortfristigaSkulder' => [$end => 1_001, $start => 0],
        'EgetKapitalSkulder' => [$end => 77_655, $start => 50_001],
        'ForandringEgetKapitalAretsResultatAretsResultat' => [$year => -73_347],
        'ForandringEgetKapitalTotaltAretsResultat' => [$year => -73_347],
        'ForslagDispositionBalanserasINyRakning' => [$end => -48_346],
        'ForslagDisposition' => [$end => -48_346],
        'Soliditet' => [$end => -0.301],
    ];

    foreach ($expected as $concept => $values) {
        foreach ($values as $context => $value) {
            expect($facts[$concept][$context] ?? null)->toEqualWithDelta((float) $value, 0.000001, "{$concept} {$context}");
        }
    }

    // The calculation linkbases of K2 AB 2024-09-12 (se-k2-ab-fisbn and -fbs), read
    // from the taxonomy package, written out here: parent => [child => weight].
    $calculations = [
        'AretsResultat' => ['ResultatForeSkatt' => 1, 'SkattAretsResultat' => -1, 'OvrigaSkatter' => -1],
        'ResultatForeSkatt' => ['ResultatEfterFinansiellaPoster' => 1, 'Bokslutsdispositioner' => 1],
        'ResultatEfterFinansiellaPoster' => ['Rorelseresultat' => 1, 'FinansiellaPoster' => 1],
        'Rorelseresultat' => ['RorelseintakterLagerforandringarMm' => 1, 'Rorelsekostnader' => -1],
        'RorelseintakterLagerforandringarMm' => ['Nettoomsattning' => 1, 'ForandringLagerProdukterIArbeteFardigaVarorPagaendeArbetenAnnansRakning' => 1, 'AktiveratArbeteEgenRakning' => 1, 'OvrigaRorelseintakter' => 1],
        'Rorelsekostnader' => ['OvrigaExternaKostnader' => 1, 'Personalkostnader' => 1, 'NedskrivningarOmsattningstillgangarUtoverNormalaNedskrivningar' => 1, 'OvrigaRorelsekostnader' => 1, 'AvskrivningarNedskrivningarMateriellaImmateriellaAnlaggningstillgangar' => 1, 'RavarorFornodenheterKostnader' => 1, 'HandelsvarorKostnader' => 1],
        'FinansiellaPoster' => ['ResultatAndelarKoncernforetag' => 1, 'ResultatAndelarIntresseforetagGemensamtStyrda' => 1, 'OvrigaRanteintakterLiknandeResultatposter' => 1, 'NedskrivningarFinansiellaAnlaggningstillgangarKortfristigaPlaceringar' => -1, 'RantekostnaderLiknandeResultatposter' => -1, 'ResultatOvrigaforetagAgarintresse' => 1, 'ResultatOvrigaFinansiellaAnlaggningstillgangar' => 1],
        'EgetKapitalSkulder' => ['EgetKapital' => 1, 'ObeskattadeReserver' => 1, 'Avsattningar' => 1, 'LangfristigaSkulder' => 1, 'KortfristigaSkulder' => 1],
        'EgetKapital' => ['FrittEgetKapital' => 1, 'BundetEgetKapital' => 1],
        'FrittEgetKapital' => ['Overkursfond' => 1, 'BalanseratResultat' => 1, 'AretsResultatEgetKapital' => 1],
        'BundetEgetKapital' => ['Aktiekapital' => 1, 'EjRegistreratAktiekapital' => 1, 'Reservfond' => 1, 'Uppskrivningsfond' => 1, 'OverkursfondBunden' => 1],
        'LangfristigaSkulder' => ['Obligationslan' => 1, 'CheckrakningskreditLangfristig' => 1, 'OvrigaLangfristigaSkulderKreditinstitut' => 1, 'OvrigaLangfristigaSkulder' => 1],
        'KortfristigaSkulder' => ['CheckrakningskreditKortfristig' => 1, 'OvrigaKortfristigaSkulderKreditinstitut' => 1, 'ForskottFranKunder' => 1, 'PagaendeArbetenAnnansRakningKortfristigaSkulder' => 1, 'FaktureradEjUpparbetadIntakt' => 1, 'Leverantorsskulder' => 1, 'Skatteskulder' => 1, 'OvrigaKortfristigaSkulder' => 1, 'UpplupnaKostnaderForutbetaldaIntakter' => 1],
        'Tillgangar' => ['Omsattningstillgangar' => 1, 'Anlaggningstillgangar' => 1],
        'Omsattningstillgangar' => ['VarulagerMm' => 1, 'KortfristigaFordringar' => 1, 'KortfristigaPlaceringar' => 1, 'KassaBank' => 1],
        'KortfristigaFordringar' => ['Kundfordringar' => 1, 'ForutbetaldaKostnaderUpplupnaIntakter' => 1, 'OvrigaFordringarKortfristiga' => 1],
        'Anlaggningstillgangar' => ['ImmateriellaAnlaggningstillgangar' => 1, 'MateriellaAnlaggningstillgangar' => 1, 'FinansiellaAnlaggningstillgangar' => 1],
        'MateriellaAnlaggningstillgangar' => ['ByggnaderMark' => 1, 'ForbattringsutgifterAnnansFastighet' => 1, 'PagaendeNyanlaggningarForskottMateriellaAnlaggningstillgangar' => 1],
    ];
    $checked = 0;

    foreach ($calculations as $parent => $children) {
        foreach ($facts[$parent] ?? [] as $context => $total) {
            $present = array_filter($children, fn (string $child): bool => isset($facts[$child][$context]), ARRAY_FILTER_USE_KEY);

            if ($present === []) {
                continue;
            }

            $sum = array_sum(array_map(fn (string $child, int $weight): float => $weight * $facts[$child][$context], array_keys($present), $present));
            expect($total)->toEqual($sum, "{$parent} in {$context} is not the sum of its lines");
            $checked++;
        }
    }

    expect($checked)->toBeGreaterThan(20);

    // The PDF prints the same rounded figures.
    $html = AnnualReportDocument::html($this->company, $this->year, $this->report);
    expect($html)->toContain('-73'."\u{a0}".'347')
        ->toContain('77'."\u{a0}".'655')
        ->toContain('25'."\u{a0}".'001')
        ->not->toContain('-73'."\u{a0}".'346');
});

it('puts a minus outside the tag or sign="-" exactly when the concept\'s balance runs against the statement', function () {
    [, $xpath] = ixbrlRoundingParse(K2AnnualReportIxbrl::render($this->company, $this->year, $this->report));
    [, $signs] = ixbrlRoundingFacts($xpath);

    // Hand-written: concept => [its balance in the taxonomy, the direction the report prints as positive].
    $directions = [
        'Nettoomsattning' => ['credit', 'credit'],
        'OvrigaExternaKostnader' => ['debit', 'credit'],
        'Personalkostnader' => ['debit', 'credit'],
        'Rorelseresultat' => ['credit', 'credit'],
        'ResultatEfterFinansiellaPoster' => ['credit', 'credit'],
        'ResultatForeSkatt' => ['credit', 'credit'],
        'AretsResultat' => ['credit', 'credit'],
        'RorelseintakterLagerforandringarMm' => ['credit', 'credit'],
        'Rorelsekostnader' => ['debit', 'credit'],
        'KassaBank' => ['debit', 'debit'],
        'Omsattningstillgangar' => ['debit', 'debit'],
        'Tillgangar' => ['debit', 'debit'],
        'Aktiekapital' => ['credit', 'credit'],
        'BundetEgetKapital' => ['credit', 'credit'],
        'BalanseratResultat' => ['credit', 'credit'],
        'AretsResultatEgetKapital' => ['credit', 'credit'],
        'FrittEgetKapital' => ['credit', 'credit'],
        'EgetKapital' => ['credit', 'credit'],
        'OvrigaLangfristigaSkulderKreditinstitut' => ['credit', 'credit'],
        'LangfristigaSkulder' => ['credit', 'credit'],
        'Leverantorsskulder' => ['credit', 'credit'],
        'OvrigaKortfristigaSkulder' => ['credit', 'credit'],
        'KortfristigaSkulder' => ['credit', 'credit'],
        'EgetKapitalSkulder' => ['credit', 'credit'],
        'ForandringEgetKapitalAretsResultatAretsResultat' => [null, 'credit'],
        'ForandringEgetKapitalTotaltAretsResultat' => [null, 'credit'],
        'ForslagDispositionBalanserasINyRakning' => [null, 'credit'],
        'ForslagDisposition' => ['credit', 'credit'],
        'Soliditet' => [null, 'credit'],
        'MedelantaletAnstallda' => [null, 'credit'],
    ];
    $checked = 0;

    foreach ($signs as [$concept, $minus, $negative, $value]) {
        expect($directions)->toHaveKey($concept);

        if ($value == 0) {
            expect($minus)->toBeFalse()->and($negative)->toBeFalse();

            continue;
        }

        [$balance, $direction] = $directions[$concept];
        expect($minus xor $negative)->toBe($balance !== null && $balance !== $direction, "{$concept}: printed minus and sign do not fit the balance");
        $checked++;
    }

    expect($checked)->toBeGreaterThan(30);
});

it('drops characters XML cannot carry from the user\'s texts', function () {
    $this->report->forceFill(['business_description' => "Kaffe\x0Boch\x01 kakor", 'contingent_liabilities_text' => "Borgen\x1F"])->save();

    $xhtml = K2AnnualReportIxbrl::render($this->company, $this->year, $this->report->fresh());
    [, $xpath] = ixbrlRoundingParse($xhtml);

    expect(trim((string) $xpath->evaluate("string(//ix:nonNumeric[@name='se-gen-base:AllmantVerksamheten'])")))->toBe('Kaffeoch kakor')
        ->and($xhtml)->not->toContain("\x0B");
});

it('tags only the text of a contingent liability without an amount, and prints no 0 for it', function () {
    $this->report->forceFill(['contingent_liabilities_ore' => null, 'contingent_liabilities_text' => 'Borgen för en leverantörs lån'])->save();
    $report = $this->report->fresh();

    [, $xpath] = ixbrlRoundingParse(K2AnnualReportIxbrl::render($this->company, $this->year, $report));

    expect($xpath->query("//ix:nonFraction[@name='se-gen-base:EventualForpliktelser']")?->length)->toBe(0)
        ->and(trim((string) $xpath->evaluate("string(//ix:nonNumeric[@name='se-gen-base:NotEventualForpliktelserKommentar'])")))->toBe('Borgen för en leverantörs lån');

    $pdf = new DOMDocument;
    @$pdf->loadHTML('<?xml encoding="UTF-8">'.AnnualReportDocument::html($this->company, $this->year, $report));
    $row = (new DOMXPath($pdf))->query("//td[normalize-space(.)='Borgen för en leverantörs lån']/following-sibling::td[1]")?->item(0);

    expect($row)->not->toBeNull()->and(trim((string) $row?->textContent))->toBe('');
});

it('refuses a file that is not well-formed XML', function () {
    expect(fn () => K2AnnualReportIxbrl::assertWellFormed('<html><p></html>'))->toThrow(IxbrlRefused::class);
});

it('writes an XHTML shell that the XHTML schema accepts: no lang beside xml:lang, no empty class, no empty tbody', function () {
    // This fixture has no signatories, so the signatures table would be empty.
    [$document, $xpath] = ixbrlRoundingParse(K2AnnualReportIxbrl::render($this->company, $this->year, $this->report));

    expect($document->documentElement?->hasAttribute('lang'))->toBeFalse()
        ->and($document->documentElement?->getAttributeNS('http://www.w3.org/XML/1998/namespace', 'lang'))->toBe('sv')
        ->and($xpath->query("//*[@class and normalize-space(@class)='']")?->length)->toBe(0)
        ->and($xpath->query('//h:tbody[not(h:tr)]')?->length)->toBe(0);
});
