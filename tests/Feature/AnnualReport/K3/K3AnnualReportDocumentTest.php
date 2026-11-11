<?php

declare(strict_types=1);

use App\Actions\Accounting\ChangeFirstFiscalYear;
use App\Actions\AnnualReport\K3NoteOverrides;
use App\Actions\AnnualReport\SaveK3ReportInputs;
use App\Actions\AnnualReport\SetAccountingPolicy;
use App\Actions\Assets\FixedAssets;
use App\Domain\Accounting\AccountType;
use App\Domain\AnnualReport\K3AnnualReportDocument;
use App\Domain\AnnualReport\K3PolicyKey;
use App\Domain\AnnualReport\K3TransitionProposal;
use App\Domain\AnnualReport\PrintedFigures;
use App\Domain\Company\LegalForm;
use App\Domain\Company\ReportingFramework;
use App\Livewire\Accounting\AnnualReportPage;
use App\Models\Account;
use App\Models\AnnualReport;
use App\Models\AnnualReportNoteOverride;
use App\Models\ClosingCheck;
use App\Models\CompanyAccountingPolicy;
use App\Models\FiscalYear;
use App\Models\PriorYearSizeFigure;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\K3DocumentFixture;
use Tests\Support\S1Fixture;

/*
 * S1-P10 (ADR-059 A.4): the K3 annual report of a mindre aktiebolag, driven by
 * F-S1-b (K2 2025 → K3 2026, the transition booked as S1-P12 books it). The
 * printed figures are compared with tests/Fixtures/s1/f-s1-b/document/
 * expected.json, the whole HTML with document.html (rewritten only with
 * NONOX_UPDATE_K3_GOLDEN=1, reviewed before committing). The K2 golden files
 * (tests/Fixtures/annual-report/**) are AnnualReportGoldenTest's and are not
 * touched. Every refusal reason is tested with its rule id.
 */

/** Öre as a person types it: "3600000,00". */
function k3DocKronor(int $ore): string
{
    return K3DocumentFixture::kronor($ore);
}

/** F-S1-b ready for the K3 document; see K3DocumentFixture::load(). */
function loadK3DocumentFixture(object $test, bool $book = true): void
{
    K3DocumentFixture::load($test, $book);
}

/** Answers every open question of F-S1-b the way a person would. */
function answerK3DocumentQuestions(object $test): void
{
    K3DocumentFixture::answerQuestions($test);
}

function k3Document(object $test): K3AnnualReportDocument
{
    return K3AnnualReportDocument::for($test->company->refresh(), $test->year->refresh(), K3AnnualReportDocument::report($test->year));
}

/** The rule ids of a document's refusals. */
function k3DocumentRules(K3AnnualReportDocument $document): array
{
    return array_column($document->refusals(), 'rule');
}

/**
 * The amounts of the row labelled $label in the part with the heading id
 * $part, as printed (whole kronor) in öre; an empty cell is null.
 *
 * @return list<?int>
 */
function k3DocumentRow(DOMXPath $xpath, string $part, string $label): array
{
    $rows = $xpath->query("//section[@aria-labelledby='{$part}']//tr[th[@scope='row' and normalize-space(.)='{$label}']]");

    expect($rows->length)->toBe(1, "{$part}: one row {$label}");

    $amounts = [];

    foreach ($xpath->query(".//td[contains(@class, 'num')]", $rows->item(0)) as $cell) {
        $text = str_replace(["\u{a0}", ' '], '', trim($cell->textContent));
        $amounts[] = $text === '' ? null : (int) $text * 100;
    }

    return $amounts;
}

/** The text of the note column of the row labelled $label. */
function k3DocumentNoteReference(DOMXPath $xpath, string $label): string
{
    $cell = $xpath->query("//tr[th[@scope='row' and normalize-space(.)='{$label}']]/td[contains(@class, 'note')]")->item(0);

    return trim((string) $cell?->textContent);
}

function k3DocumentDom(string $html): DOMXPath
{
    $dom = new DOMDocument;
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();

    return new DOMXPath($dom);
}

describe('F-S1-b, a mindre aktiebolag in its first K3 year', function () {
    beforeEach(function () {
        loadK3DocumentFixture($this);
        $this->expected = json_decode((string) file_get_contents(base_path('tests/Fixtures/s1/f-s1-b/document/expected.json')), true, flags: JSON_THROW_ON_ERROR);
    });

    it('S1-R9 and S1-P10 make the report of F-S1-b without a refusal, as a draft until the questions are answered', function () {
        $document = k3Document($this);

        expect($document->refusals())->toBe([])
            ->and($document->questions())->not->toBe([])
            ->and($document->complete())->toBeFalse()
            ->and($document->html())->toContain('Utkast: det finns frågor kvar att besvara');

        answerK3DocumentQuestions($this);

        expect(k3Document($this)->questions())->toBe([])
            ->and(k3Document($this)->complete())->toBeTrue();
    });

    it('S1-R8.2 prints the parts of F-S1-b in the order of K3 3.10, with no cash flow statement under the policy none', function () {
        answerK3DocumentQuestions($this);
        $xpath = k3DocumentDom(k3Document($this)->html(preview: false));
        $headings = array_map(fn (DOMNode $node): string => trim($node->textContent), iterator_to_array($xpath->query('//h2')));

        expect($headings)->toBe($this->expected['parts_in_order'])
            ->and($this->expected['cash_flow_statement'])->toBeFalse();
    });

    it('S1-R8.2 prints F-S1-b\'s income statement and balance sheet as expected.json, to the krona, with the K2 year as comparative', function () {
        answerK3DocumentQuestions($this);
        $xpath = k3DocumentDom(k3Document($this)->html(preview: false));

        foreach ($this->expected['income_statement'] as $label => $amounts) {
            expect(k3DocumentRow($xpath, 'k3-income', $label))->toBe($amounts, "income {$label}");
        }

        foreach ($this->expected['balance_sheet'] as $label => $amounts) {
            expect(k3DocumentRow($xpath, 'k3-balance', $label))->toBe($amounts, "balance {$label}");
        }

        // The totals are the fixture's reconciliation sheet, printed in whole kronor.
        $reconciliation = $this->fixture->expected['transition']['reconciliation'];

        expect($this->expected['balance_sheet']['Summa tillgångar'][0])->toBe(PrintedFigures::round($reconciliation['balance_sheet_total_k3_ore']))
            ->and($this->expected['balance_sheet']['Summa eget kapital'][0])->toBe(PrintedFigures::round($reconciliation['equity_k3_ore']))
            ->and($this->expected['balance_sheet']['Summa eget kapital'][1])->toBe(PrintedFigures::round($reconciliation['equity_k2_ore']));
    });

    it('S1-R8.3 prints the transition in row b of F-S1-b\'s equity statement and no residual row', function () {
        answerK3DocumentQuestions($this);
        $html = k3Document($this)->html(preview: false);
        $xpath = k3DocumentDom($html);
        $columns = array_map(fn (DOMNode $node): string => trim($node->textContent), iterator_to_array($xpath->query("//section[@aria-labelledby='k3-equity']//thead/tr/th[position() > 1]")));

        expect($columns)->toBe($this->expected['equity_statement']['columns']);

        foreach ($this->expected['equity_statement']['rows'] as $label => $amounts) {
            expect(k3DocumentRow($xpath, 'k3-equity', $label))->toBe($amounts, "equity {$label}");
        }

        expect($this->expected['equity_statement']['rows']['Effekter av retroaktiv tillämpning och rättelse av fel'][3])->toBe(PrintedFigures::round($this->fixture->expected['transition']['equity_effect_ore']));
    });

    it('S1-R8.1 and S1-R7.5 print F-S1-b\'s notes in K3 8.2 order, with note numbers on the statement lines and the comparability note', function () {
        answerK3DocumentQuestions($this);
        $html = k3Document($this)->html(preview: false);
        $notes = array_map(fn ($note): array => ['number' => $note->number, 'heading' => $note->heading], k3Document($this)->notes()->notes());

        expect($notes)->toBe($this->expected['notes']);

        $xpath = k3DocumentDom($html);

        foreach ($this->expected['note_references'] as $label => $number) {
            expect(k3DocumentNoteReference($xpath, $label))->toBe((string) $number, $label);
        }

        // Money is formatted with no-break spaces; the texts are compared as read.
        $plain = str_replace("\u{a0}", ' ', $html);

        foreach ($this->expected['texts'] as $text) {
            expect($plain)->toContain($text);
        }

        foreach ($this->expected['absent'] as $text) {
            expect($plain)->not->toContain($text);
        }
    });

    it('renders F-S1-b\'s whole K3 annual report exactly as the golden file', function () {
        answerK3DocumentQuestions($this);
        $html = k3Document($this)->html(preview: false);
        $path = base_path('tests/Fixtures/s1/f-s1-b/document/document.html');

        if (getenv('NONOX_UPDATE_K3_GOLDEN') === '1') {
            file_put_contents($path, $html);
        }

        expect($html)->toBe((string) file_get_contents($path));
    });

    it('S1 Behaviour 6 marks a note in a person\'s own words in the preview, and prints only the text in the PDF', function () {
        answerK3DocumentQuestions($this);
        $document = k3Document($this);

        expect($document->html())->toContain('data-override="fixed_assets.equipment"')
            ->and($document->html(preview: false))->not->toContain('data-override')
            ->and($document->html(preview: false))->toContain('Inventarier skrivs av linjärt över fem år.');

        // The books change after the override was written: the preview says the text may be out of date.
        app(K3NoteOverrides::class)->set($this->owner, $this->year, 'average_employees', 'Medelantalet anställda har varit tre.', 'Egen formulering.');
        app(SaveK3ReportInputs::class)($this->owner, $this->year, ['average_employees_fte' => '4']);

        expect(k3Document($this)->notes()->note('average_employees')->stale)->toBeTrue()
            ->and(k3Document($this)->html())->toContain('Bokföringen har ändrats sedan texten skrevs');
    });

    it('serves the preview and, once complete, the PDF of F-S1-b; the K2 iXBRL file is refused', function () {
        $this->get(route('accounting.annualReport.show', $this->year))->assertOk()->assertSee('Rapport över förändringar i eget kapital');
        $this->get(route('accounting.annualReport.pdf', $this->year))->assertRedirect();

        answerK3DocumentQuestions($this);

        $this->get(route('accounting.annualReport.pdf', $this->year))->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="Arsredovisning-K3-'.preg_replace('/\D/', '', (string) $this->company->org_number).'-2026.pdf"');
        $this->get(route('accounting.annualReport.ixbrl', $this->year))->assertForbidden();
    });

    it('A.5 reads the report and renders the page with a fixed number of queries, however many accounts, assets, notes and owners', function () {
        answerK3DocumentQuestions($this);
        $count = function (callable $read): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $read();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };
        $document = fn (): int => $count(fn () => k3Document($this)->html());
        $page = fn (): int => $count(fn () => Livewire::test(AnnualReportPage::class, ['year' => $this->year->id]));
        $assets = app(FixedAssets::class);
        $accounts = [5010, 5060, 5410, 5460, 5610, 6071, 6110, 6212, 6230, 6310, 6530, 6570, 6991, 7690];

        foreach ($accounts as $number) {
            Account::query()->firstOrCreate(['number' => $number], ['name' => "Kostnad {$number}", 'type' => AccountType::forNumber($number), 'is_active' => true]);
        }

        // Short: one voucher, one register asset, one owner, no optional note.
        Bookkeeping::post($this->owner, $this->company, 1_000_000, 1930, 3001, '2026-02-01');
        $assets->save($this->owner, $this->company, ['name' => 'Svarv 1', 'category' => 'EQUIPMENT', 'acquired_on' => '2026-02-01', 'acquisition' => '50000,00', 'opening_accumulated' => '', 'life_months' => '60', 'depreciation_start' => '2026-02-01', 'asset_account' => '1220', 'accumulated_account' => '1229', 'expense_account' => '7832']);
        app(SaveK3ReportInputs::class)($this->owner, $this->year, ['k3_major_owners' => [['name' => 'Ägarbolaget Demo AB', 'owner_type' => 'legal', 'votes_bp' => 10_000, 'capital_bp' => 10_000]]]);
        [$shortDocument, $shortPage] = [$document(), $page()];

        // Long: many vouchers on many accounts, more register assets, more owners and the optional notes.
        foreach ($accounts as $index => $number) {
            Bookkeeping::post($this->owner, $this->company, 100_000 + $index, $number, 1930, sprintf('2026-03-%02d', $index + 1));
            Bookkeeping::post($this->owner, $this->company, 200_000 + $index, 1930, 3001, sprintf('2026-04-%02d', $index + 1));
        }

        foreach (range(2, 6) as $n) {
            $assets->save($this->owner, $this->company, ['name' => "Svarv {$n}", 'category' => 'EQUIPMENT', 'acquired_on' => '2026-02-01', 'acquisition' => '50000,00', 'opening_accumulated' => '', 'life_months' => (string) (36 + $n * 12), 'depreciation_start' => '2026-02-01', 'asset_account' => '1220', 'accumulated_account' => '1229', 'expense_account' => '7832']);
        }

        app(SaveK3ReportInputs::class)($this->owner, $this->year, [
            'k3_major_owners' => [
                ['name' => 'Ägarbolaget Demo AB', 'owner_type' => 'legal', 'votes_bp' => 4_000, 'capital_bp' => 4_000],
                ['name' => 'Andra Ägarbolaget Demo AB', 'owner_type' => 'legal', 'votes_bp' => 3_000, 'capital_bp' => 3_000],
                ['name' => 'Testa Testsson', 'owner_type' => 'natural', 'votes_bp' => 3_000, 'capital_bp' => 3_000],
            ],
            'k3_exceptional_items' => [['description' => 'Försäkringsersättning', 'amount' => '120000'], ['description' => 'Nedskrivning av fordran', 'amount' => '80000']],
            'k3_off_balance_arrangements' => 'Hyresavtal för en lagerlokal.',
            'k3_parent_company' => 'Ägarbolaget Demo AB, 559999-7029, Exempelstad.',
        ]);
        AnnualReport::query()->where('fiscal_year_id', $this->year->id)->update(['events_after' => 'Ny maskin beställd.', 'contingent_liabilities_ore' => 5_000_000, 'pledged_assets' => json_encode(['business_mortgages' => 200_000_000])]);

        expect(count(k3Document($this)->notes()->notes()))->toBeGreaterThan(10)
            ->and($document())->toBe($shortDocument)
            ->and($page())->toBe($shortPage);
    });

    it('S1-R1.4 refuses a year that follows an earlier version of K3, such as a broken year 2025-07-01 to 2026-06-30', function () {
        [$owner, $company] = Bookkeeping::company();
        $company->forceFill(['legal_form' => LegalForm::Ab, 'reporting_framework' => ReportingFramework::K3])->save();
        CurrentCompany::set($company);
        $this->actingAs($owner);
        $year = FiscalYear::query()->firstOrFail();
        app(ChangeFirstFiscalYear::class)($owner, $year, '2025-07-01', '2026-06-30');
        $year = $year->refresh();
        $year->forceFill(['reporting_framework' => ReportingFramework::K3])->save();

        $document = K3AnnualReportDocument::for($company->refresh(), $year->refresh(), K3AnnualReportDocument::report($year));

        expect(k3DocumentRules($document))->toContain('S1-R1.4')
            ->and(collect($document->refusals())->firstWhere('rule', 'S1-R1.4')['message'])->toContain('BFNAR 2025:3');
    });

    it('is accessible: Swedish, one h1, headings in order, every table with column and row headers', function () {
        answerK3DocumentQuestions($this);
        $xpath = k3DocumentDom(k3Document($this)->html());

        expect($xpath->query('/html/@lang')->item(0)?->nodeValue)->toBe('sv')
            ->and(trim((string) $xpath->query('//title')->item(0)?->textContent))->not->toBe('')
            ->and($xpath->query('//h1')->length)->toBe(1)
            ->and($xpath->query('//main')->length)->toBe(1);

        $level = 1;

        foreach ($xpath->query('//h1|//h2|//h3|//h4') as $heading) {
            $next = (int) substr($heading->nodeName, 1);
            expect($next - $level)->toBeLessThanOrEqual(1, 'heading level skipped at '.trim($heading->textContent));
            $level = $next;
        }

        foreach ($xpath->query("//table[not(@role='presentation')]") as $table) {
            expect($xpath->query('.//thead/tr/th[not(@scope="col")]', $table)->length)->toBe(0)
                ->and($xpath->query('.//thead/tr/th', $table)->length)->toBeGreaterThan(0)
                ->and($xpath->query('.//tbody/tr[not(th[@scope="row" or @scope="rowgroup"])]', $table)->length)->toBe(0);
        }
    });
});

describe('the Årsredovisning page for F-S1-b', function () {
    beforeEach(fn () => loadK3DocumentFixture($this));

    it('lists the questions, saves the K3 inputs and offers the PDF once everything is answered', function () {
        $page = Livewire::test(AnnualReportPage::class, ['year' => $this->year->id])
            ->assertSee('Beta')
            ->assertSee('Frågor att besvara')
            ->assertSee('Uppgifter för K3')
            ->assertDontSee('Ladda ner PDF')
            ->assertDontSee('Det här hindrar årsredovisningen');

        $page->set('k3Form.answers.group_commitments', 'no')
            ->set('k3Form.answers.off_balance_arrangements', 'no')
            ->set('k3Form.answers.loans_to_executives', 'no')
            ->set('k3Form.answers.parent_company', 'no')
            ->set('k3Form.k3_long_term_liabilities', '1 000 000')
            ->set('ownersAnswered', true)
            ->call('saveK3')
            ->assertHasNoErrors()
            ->call('editNote', 'fixed_assets.equipment')
            ->set('noteText', 'Inventarier skrivs av linjärt över fem år.')
            ->set('noteReason', 'Inventarierna finns bara i bokföringen.')
            ->call('saveNote')
            ->assertHasNoErrors()
            ->assertSee('Egen formulering')
            ->assertSee('Alla frågor är besvarade')
            ->assertSee('Ladda ner PDF');

        $report = AnnualReport::query()->where('fiscal_year_id', $this->year->id)->firstOrFail();

        expect($report->k3_answers)->toEqualCanonicalizing(['group_commitments' => false, 'off_balance_arrangements' => false, 'loans_to_executives' => false, 'parent_company' => false])
            ->and($report->k3_major_owners)->toBe([])
            ->and($report->k3_long_term_liabilities_ore)->toBe(100_000_000);

        $page->call('removeNoteOverride', 'fixed_assets.equipment')->assertDontSee('Ladda ner PDF');
    });

    it('labels every field of the K3 inputs and titles the preview frame', function () {
        $xpath = k3DocumentDom('<!doctype html><html><body>'.Livewire::test(AnnualReportPage::class, ['year' => $this->year->id])->call('addOwner')->html().'</body></html>');

        foreach ($xpath->query('//input[not(@type="checkbox") and not(@type="hidden")]|//select|//textarea') as $field) {
            $id = $field->attributes->getNamedItem('id')?->nodeValue;
            expect($id)->not->toBeNull()
                ->and($xpath->query("//label[@for='{$id}']")->length)->toBe(1, "label for {$id}");
        }

        expect($xpath->query('//iframe[@title]')->length)->toBe(1);
    });
});

describe('permissions per role (AGENTS.md §4; spec S1: the auditor reads and exports)', function () {
    beforeEach(function () {
        loadK3DocumentFixture($this);
        answerK3DocumentQuestions($this);
    });

    it('lets the roles that read reports view and export the K3 report, and refuses the approver', function (string $role, bool $reads) {
        // Jetstream keeps the company's owner on the company itself.
        $member = $role === 'owner' ? $this->owner : Bookkeeping::member($this->company, $role);
        $this->actingAs($member);

        if (! $reads) {
            $this->get(route('accounting.annualReport.show', $this->year))->assertForbidden();
            $this->get(route('accounting.annualReport.pdf', $this->year))->assertForbidden();
            $this->get(route('accounting.annualReport.ixbrl', $this->year))->assertForbidden();
            Livewire::test(AnnualReportPage::class, ['year' => $this->year->id])->assertForbidden();

            return;
        }

        $this->get(route('accounting.annualReport.show', $this->year))->assertOk();
        $this->get(route('accounting.annualReport.pdf', $this->year))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        // K3 iXBRL is not built (ADR-059 F.4), for any role.
        $this->get(route('accounting.annualReport.ixbrl', $this->year))->assertForbidden();
        // The K2 year before is readable too.
        $this->get(route('accounting.annualReport.show', $this->k2))->assertOk();
    })->with([
        'owner' => ['owner', true],
        'admin' => ['admin', true],
        'accountant' => ['accountant', true],
        'bookkeeper' => ['bookkeeper', true],
        'auditor' => ['auditor', true],
        'approver' => ['approver', false],
    ]);

    it('keeps the texts, the K3 inputs and the notes\' wording to fiscal-years:manage', function (string $role, bool $writes) {
        // Jetstream keeps the company's owner on the company itself.
        $member = $role === 'owner' ? $this->owner : Bookkeeping::member($this->company, $role);
        $this->actingAs($member);
        $page = Livewire::test(AnnualReportPage::class, ['year' => $this->year->id]);

        if ($writes) {
            $page->assertDontSee('Du kan läsa och ladda ner årsredovisningen')
                ->call('saveK3')->assertHasNoErrors()->assertStatus(200)
                ->call('editNote', 'average_employees')->assertSet('editingNote', 'average_employees')
                ->set('noteText', 'Medelantalet anställda har varit tre.')->set('noteReason', 'Egen formulering.')
                ->call('saveNote')->assertHasNoErrors()
                ->call('removeNoteOverride', 'average_employees')->assertHasNoErrors()
                ->call('save')->assertHasNoErrors();

            return;
        }

        $page->assertSee('Du kan läsa och ladda ner årsredovisningen')
            ->assertDontSee('Skriv med egna ord')
            ->assertDontSee('Spara uppgifterna för K3');

        Livewire::test(AnnualReportPage::class, ['year' => $this->year->id])->call('save')->assertForbidden();
        Livewire::test(AnnualReportPage::class, ['year' => $this->year->id])->call('saveK3')->assertForbidden();
        Livewire::test(AnnualReportPage::class, ['year' => $this->year->id])->call('editNote', 'average_employees')->assertForbidden();
        Livewire::test(AnnualReportPage::class, ['year' => $this->year->id])->call('removeNoteOverride', 'fixed_assets.equipment')->assertForbidden();

        expect(AnnualReportNoteOverride::query()->where('note_key', 'fixed_assets.equipment')->exists())->toBeTrue();
    })->with([
        'owner' => ['owner', true],
        'admin' => ['admin', true],
        'accountant' => ['accountant', true],
        'bookkeeper' => ['bookkeeper', false],
        'auditor' => ['auditor', false],
    ]);
});

describe('refusals, each with its rule id', function () {
    beforeEach(fn () => loadK3DocumentFixture($this));

    it('S1-R6 refuses chapter 12 (financial instruments at fair value)', function () {
        app(SetAccountingPolicy::class)($this->owner, $this->company, $this->year->id, K3PolicyKey::FinancialInstruments, 'chapter_12');

        expect(k3DocumentRules(k3Document($this)))->toContain('S1-R6')
            ->and(K3AnnualReportDocument::refusal($this->company, $this->year))->toContain('kapitel 12');
    });

    it('S1-R10 refuses the capitalisation model', function () {
        app(SetAccountingPolicy::class)($this->owner, $this->company, $this->year->id, K3PolicyKey::Intangibles, 'capitalise');

        expect(k3DocumentRules(k3Document($this)))->toContain('S1-R10');
    });

    it('S1-R5 refuses finance leases classified in the balance sheet', function () {
        app(SetAccountingPolicy::class)($this->owner, $this->company, $this->year->id, K3PolicyKey::Leasing, 'classify');

        expect(k3DocumentRules(k3Document($this)))->toContain('S1-R5');
    });

    it('S1-R10.3 refuses the expensing model when the ledger holds capitalised development (H9)', function () {
        Account::create(['number' => 1010, 'name' => 'Utvecklingsutgifter', 'type' => AccountType::forNumber(1010), 'is_active' => true]);
        Bookkeeping::post($this->owner, $this->company, 5_000_000, 1010, 1930, '2026-04-01');

        $document = k3Document($this);

        expect(k3DocumentRules($document))->toContain('S1-R10.3')
            ->and(collect($document->refusals())->firstWhere('rule', 'S1-R10.3')['message'])->toContain('1010');
    });

    it('S1-scope refuses an ekonomisk förening on K3', function () {
        $this->company->forceFill(['legal_form' => LegalForm::EkonomiskForening])->save();

        expect(k3DocumentRules(k3Document($this)))->toBe([K3AnnualReportDocument::RULE_LEGAL_FORM])
            ->and(K3AnnualReportDocument::refusal($this->company, $this->year))->toContain('ekonomisk förening');
    });

    it('S1-R9 refuses a company whose size cannot be determined', function () {
        PriorYearSizeFigure::query()->delete();

        expect(k3DocumentRules(k3Document($this)))->toContain('S1-R9');
    });

    it('S1-R7.7 refuses a first K3 year whose transition is not booked', function () {
        $this->fixture = null;
        // A fresh load of F-S1-b without booking the transition.
        loadK3DocumentFixture($this, book: false);

        expect(k3DocumentRules(k3Document($this)))->toContain('S1-R7.7');
    });

    it('refuses a missing policy with the policy\'s rule id', function () {
        CompanyAccountingPolicy::query()->where('key', K3PolicyKey::Leasing->value)->delete();

        $document = k3Document($this);

        expect(k3DocumentRules($document))->toContain('S1-R5')
            ->and(collect($document->refusals())->firstWhere('rule', 'S1-R5')['message'])->toContain('Bokslut → Regelverk');
    });

    it('S1-R4.6 refuses a confirmed set-off of deferred tax, which the balance sheet does not show netted yet', function () {
        ClosingCheck::create(['fiscal_year_id' => $this->year->id, 'key' => K3TransitionProposal::SET_OFF_CHECK, 'checked_by' => $this->owner->id]);

        expect(k3DocumentRules(k3Document($this)))->toContain('S1-R4.6');
    });

    it('S1-R2.1 refuses a cash flow statement that cannot be drawn up', function () {
        app(SetAccountingPolicy::class)($this->owner, $this->company, $this->year->id, K3PolicyKey::CashFlowStatement, 'indirect');

        expect(k3DocumentRules(k3Document($this)))->toContain('S1-R2.1');
    });

    it('shows the refusals on the page and no preview', function () {
        app(SetAccountingPolicy::class)($this->owner, $this->company, $this->year->id, K3PolicyKey::FinancialInstruments, 'chapter_12');

        Livewire::test(AnnualReportPage::class, ['year' => $this->year->id])
            ->assertSee('Det här hindrar årsredovisningen')
            ->assertSee('(S1-R6)')
            ->assertSee('Ingen förhandsvisning ännu');

        $this->get(route('accounting.annualReport.show', $this->year))->assertNotFound();
    });
});

it('S1-R9.3 refuses F-S1-a, a större företag, until human decision S1-H1', function () {
    $this->travelTo(CarbonImmutable::parse('2027-02-10 10:00:00'));
    $fixture = S1Fixture::load('f-s1-a');
    CurrentCompany::set($fixture->company);
    $this->actingAs($fixture->owner);
    $sizes = $fixture->expected['size_classification']['years'];

    PriorYearSizeFigure::create([
        'start_date' => '2024-01-01', 'end_date' => '2024-12-31', 'average_employees_fte' => (string) $sizes['2024']['medelantal_anstallda'],
        'balance_sheet_total_ore' => $sizes['2024']['balansomslutning'], 'net_turnover_ore' => $sizes['2024']['nettoomsattning'], 'entered_by' => $fixture->owner->id,
    ]);
    AnnualReport::create(['fiscal_year_id' => $fixture->years[2025]->id, 'average_employees' => 30, 'average_employees_fte' => '30.00', 'signatories' => [], 'updated_by' => $fixture->owner->id]);

    $refusal = K3AnnualReportDocument::refusal($fixture->company->refresh(), $fixture->years[2026]->refresh());

    expect($refusal)->toContain('(S1-R9.3)')
        ->and($refusal)->toContain('S1-H1');
});
