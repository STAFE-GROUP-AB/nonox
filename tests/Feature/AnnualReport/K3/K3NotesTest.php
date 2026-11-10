<?php

declare(strict_types=1);

use App\Actions\Accounting\CorrectVoucher;
use App\Actions\AnnualReport\SaveK3ReportInputs;
use App\Actions\AnnualReport\SetAccountingPolicy;
use App\Actions\Assets\FixedAssets;
use App\Domain\Accounting\AccountType;
use App\Domain\AnnualReport\K3Notes;
use App\Domain\AnnualReport\K3PolicyKey;
use App\Domain\AnnualReport\K3SizeClass;
use App\Domain\Company\LegalForm;
use App\Domain\Company\ReportingFramework;
use App\Models\Account;
use App\Models\AnnualReport;
use App\Models\ClosingCheck;
use App\Models\CompanyAccountingPolicy;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Models\OpeningBalance;
use App\Models\Voucher;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\Bookkeeping;

/*
 * S1-P9 (ADR-059 A.2.7, A.4): the K3 notes library for a mindre aktiebolag.
 * One test per row of the rule sheet's note checklist S1-R8.1 (rows 1–27):
 * the trigger holds → the note is there with its data; it does not → the
 * note is absent. Sources are quoted in docs/specs/rules/s1.md, S1-R8.1.
 */

/** The 2026 policies of a company that applies K3 as NONOX supports it (A.2.3). */
const K3_NOTES_POLICIES = [
    'financial_instruments' => 'chapter_11',
    'intangibles' => 'expense',
    'leasing' => 'operational_in_legal_entity',
    'borrowing_costs' => 'expense',
    'fixed_price_contracts' => 'none',
    'cash_flow_statement' => 'none',
    'components' => 'buildings_always_others_by_choice',
];

/**
 * A mindre aktiebolag whose 2026 fiscal year is K3, with the policies in
 * force and an annual report row.
 *
 * @param  array<string, string>  $policies  overrides of K3_NOTES_POLICIES
 */
function k3NotesCompany(object $test, array $policies = [], ?FiscalYear $previous = null): void
{
    $test->travelTo(CarbonImmutable::parse('2027-02-10 10:00:00'));
    [$owner, $company] = Bookkeeping::company();
    $company->forceFill(['legal_form' => LegalForm::Ab])->save();
    $test->actingAs($owner);
    CurrentCompany::set($company);
    $test->owner = $owner;
    $test->company = $company->refresh();
    $test->year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->firstOrFail();
    $test->year->forceFill(['reporting_framework' => ReportingFramework::K3])->save();

    foreach ([...K3_NOTES_POLICIES, ...$policies] as $key => $value) {
        app(SetAccountingPolicy::class)($owner, $test->company, $test->year->id, K3PolicyKey::from($key), $value);
    }

    $test->report = AnnualReport::create([
        'fiscal_year_id' => $test->year->id,
        'average_employees' => 2,
        'signatories' => [],
        'updated_by' => $owner->id,
    ]);
}

/** A 2025 fiscal year before the K3 year, with the framework given. */
function k3NotesPreviousYear(object $test, ReportingFramework $framework): FiscalYear
{
    $previous = FiscalYear::create(['start_date' => '2025-01-01', 'end_date' => '2025-12-31']);
    $previous->forceFill(['reporting_framework' => $framework])->save();

    return $previous->refresh();
}

/**
 * Posts a voucher in the company; rows as account => signed öre (debit positive).
 * Accounts missing from the chart are created first.
 *
 * @param  array<int, int>  $rows
 */
function k3NotesPost(object $test, array $rows, string $date = '2026-03-01'): Voucher
{
    foreach (array_keys($rows) as $number) {
        if (! Account::query()->where('number', $number)->exists()) {
            Account::create(['number' => $number, 'name' => "Konto {$number}", 'type' => AccountType::forNumber($number), 'is_active' => true]);
        }
    }

    return Bookkeeping::postRows($test->owner, $test->company, $date, $rows);
}

function k3NotesFor(object $test, K3SizeClass $size = K3SizeClass::Mindre, array $mergedItems = []): K3Notes
{
    return K3Notes::for($test->company->refresh(), $test->year->refresh(), $test->report->refresh(), $size, mergedItems: $mergedItems);
}

/** @return list<string> */
function k3NoteKeys(K3Notes $notes): array
{
    return array_map(fn ($note): string => $note->key, $notes->notes());
}

beforeEach(function () {
    k3NotesCompany($this);
});

it('S1-R8.1 row 1 always states that the report follows ÅRL and K3 (K3 8.3) and lists the policies in force', function () {
    $note = k3NotesFor($this)->note('accounting_principles');

    expect($note)->not->toBeNull()
        ->and($note->row)->toBe(1)
        ->and($note->section)->toBe(K3Notes::PRINCIPLES)
        ->and($note->number)->toBe(1)
        ->and(implode(' ', $note->generatedText))->toContain('årsredovisningslagen')
        ->and(implode(' ', $note->generatedText))->toContain('BFNAR 2012:1')
        ->and(array_column($note->data['policies'], 'value', 'key'))->toBe(array_intersect_key(K3_NOTES_POLICIES, array_flip(K3Notes::PRINCIPLE_POLICIES)))
        ->and(K3Notes::PRINCIPLE_POLICIES)->not->toContain('cash_flow_statement')
        ->and(implode(' ', $note->generatedText))->toContain('Alla som operationella leasingavtal (K3 20.29)');
});

it('S1-R8.1 row 1 does not list a policy that is not in force for the year', function () {
    CompanyAccountingPolicy::query()->where('key', 'leasing')->delete();

    $note = k3NotesFor($this)->note('accounting_principles');

    expect(array_column($note->data['policies'], 'key'))->not->toContain('leasing')
        ->and(array_column($note->data['missing_policies'], 'key'))->toBe(['leasing'])
        ->and($note->facts)->not->toBe([])
        ->and($note->missing)->toBe([]);
});

it('S1-R8.1 row 2 explains the translation of foreign currency only when the year has currency differences (K3 8.3A)', function () {
    expect(k3NotesFor($this)->note('foreign_currency_principles'))->toBeNull();

    k3NotesPost($this, [1930 => 12_500, 3960 => -12_500]);
    $note = k3NotesFor($this)->note('foreign_currency_principles');

    expect($note)->not->toBeNull()
        ->and($note->row)->toBe(2)
        ->and($note->section)->toBe(K3Notes::PRINCIPLES)
        ->and($note->data['accounts'])->toBe([3960]);
});

it('S1-R8.1 row 3 orders the notes as K3 8.2: principles, notes per item in income statement and balance sheet order, other notes', function () {
    k3NotesPost($this, [1930 => 10_000_000, 3001 => -10_000_000]);
    k3NotesPost($this, [1220 => 5_000_000, 1930 => -5_000_000]);
    $this->report->forceFill([
        'k3_exceptional_items' => [['description' => 'Försäkringsersättning efter brand', 'amount_ore' => 1_200_000]],
        'k3_long_term_liabilities_ore' => 30_000_000,
        'pledged_assets' => ['company_mortgage' => 50_000_000],
    ])->save();

    $notes = k3NotesFor($this)->notes();
    $sections = array_map(fn ($note): string => $note->section, $notes);
    $keys = array_map(fn ($note): string => $note->key, $notes);

    // Sections never go back: principles, then items, then other notes.
    expect($sections)->toBe([...array_fill(0, count(array_filter($sections, fn ($s) => $s === K3Notes::PRINCIPLES)), K3Notes::PRINCIPLES), ...array_fill(0, count(array_filter($sections, fn ($s) => $s === K3Notes::ITEMS)), K3Notes::ITEMS), ...array_fill(0, count(array_filter($sections, fn ($s) => $s === K3Notes::OTHER)), K3Notes::OTHER)])
        ->and(array_search('exceptional_items', $keys, true))->toBeLessThan(array_search('fixed_assets.equipment', $keys, true))
        ->and(array_search('fixed_assets.equipment', $keys, true))->toBeLessThan(array_search('long_term_liabilities', $keys, true))
        ->and(array_search('long_term_liabilities', $keys, true))->toBeLessThan(array_search('pledged_assets', $keys, true));

    // Every principle is note 1; the rest are numbered 2, 3, … in order.
    $numbers = array_map(fn ($note): int => $note->number, array_values(array_filter($notes, fn ($note) => $note->section !== K3Notes::PRINCIPLES)));

    expect(array_unique(array_map(fn ($note): int => $note->number, array_filter($notes, fn ($note) => $note->section === K3Notes::PRINCIPLES))))->toBe([1])
        ->and($numbers)->toBe(range(2, count($numbers) + 1));
});

it('S1-R8.1 row 4 gives each fixed asset item its movements, method and useful life (ÅRL 5:8, K3 17.23)', function () {
    expect(collect(k3NoteKeys(k3NotesFor($this)))->filter(fn (string $key) => str_starts_with($key, 'fixed_assets.'))->all())->toBe([]);

    k3NotesPost($this, [1220 => 6_000_000, 1930 => -6_000_000], '2026-01-10');
    app(FixedAssets::class)->save($this->owner, $this->company, [
        'name' => 'Svarv', 'category' => 'EQUIPMENT', 'acquired_on' => '2026-01-10',
        'acquisition' => '60000,00', 'opening_accumulated' => '', 'life_months' => '60', 'depreciation_start' => '2026-01-01',
        'asset_account' => '1220', 'accumulated_account' => '1229', 'expense_account' => '7832',
    ]);
    k3NotesPost($this, [7832 => 1_200_000, 1229 => -1_200_000], '2026-12-31');

    $note = k3NotesFor($this)->note('fixed_assets.equipment');

    expect($note)->not->toBeNull()
        ->and($note->row)->toBe(4)
        ->and($note->section)->toBe(K3Notes::ITEMS)
        ->and($note->data['acquisition'])->toBe(['opening' => 0, 'additions' => 6_000_000, 'disposals' => 0, 'closing' => 6_000_000])
        ->and($note->data['depreciation']['charge'])->toBe(1_200_000)
        ->and($note->data['book_value'])->toBe(4_800_000)
        ->and($note->data['method'])->toBe('linear')
        ->and($note->data['lives'])->toBe([['category' => 'EQUIPMENT', 'label' => 'Inventarier och verktyg', 'years' => '5']])
        ->and(implode(' ', $note->generatedText))->toContain('5 år')
        ->and($note->missing)->toBe([]);
});

it('S1-R8.1 row 4 asks for the useful life when the register has no asset for a line with a balance', function () {
    k3NotesPost($this, [1220 => 6_000_000, 1930 => -6_000_000]);

    $note = k3NotesFor($this)->note('fixed_assets.equipment');

    expect($note->data['lives'])->toBe([])
        ->and($note->missing)->toHaveCount(1);
});

it('S1-R8.1 row 4 reads only the register assets held during the year', function () {
    k3NotesPost($this, [1220 => 6_000_000, 1930 => -6_000_000], '2026-01-10');
    $save = fn (string $name, string $acquired, string $life) => app(FixedAssets::class)->save($this->owner, $this->company, [
        'name' => $name, 'category' => 'EQUIPMENT', 'acquired_on' => $acquired,
        'acquisition' => '1000,00', 'opening_accumulated' => '', 'life_months' => $life, 'depreciation_start' => substr($acquired, 0, 8).'01',
        'asset_account' => '1220', 'accumulated_account' => '1229', 'expense_account' => '7832',
    ]);
    $save('Svarv', '2026-01-10', '60');
    $save('Köpt efter året', '2027-01-15', '120');
    $old = $save('Såld före året', '2024-01-10', '36');
    $old->forceFill(['status' => FixedAsset::DISPOSED, 'disposed_on' => '2025-06-30'])->save();

    expect(k3NotesFor($this)->note('fixed_assets.equipment')->data['lives'])->toBe([['category' => 'EQUIPMENT', 'label' => 'Inventarier och verktyg', 'years' => '5']]);
});

it('S1-R8.1 row 4 never asks for the useful life of construction in progress or advances', function () {
    k3NotesPost($this, [1180 => 6_000_000, 1088 => 1_000_000, 1930 => -7_000_000]);
    $notes = k3NotesFor($this);

    expect($notes->note('fixed_assets.construction_in_progress')->missing)->toBe([])
        ->and($notes->note('fixed_assets.intangible_advances')->missing)->toBe([]);
});

it('S1-R8.1 row 5 gives a note on goodwill only when there is goodwill, and asks for the period and reasons (ÅRL 5:9)', function () {
    expect(k3NotesFor($this)->note('goodwill'))->toBeNull();

    k3NotesPost($this, [1070 => 20_000_000, 1930 => -20_000_000]);
    $note = k3NotesFor($this)->note('goodwill');

    expect($note)->not->toBeNull()
        ->and($note->row)->toBe(5)
        ->and($note->data['carrying_ore'])->toBe(20_000_000)
        ->and($note->missing)->toHaveCount(1);

    $this->report->forceFill(['k3_goodwill_note' => 'Goodwill skrivs av på tio år eftersom kundrelationerna bedöms bestå så länge.'])->save();
    $note = k3NotesFor($this)->note('goodwill');

    expect($note->missing)->toBe([])
        ->and(implode(' ', $note->generatedText))->toContain('tio år');
});

it('S1-R8.1 row 6 gives a note on fair-valued instruments only under chapter 12, which S1 refuses (ÅRL 5:10)', function () {
    expect(k3NotesFor($this)->note('fair_value_instruments'))->toBeNull();

    app(SetAccountingPolicy::class)($this->owner, $this->company, $this->year->id, K3PolicyKey::FinancialInstruments, 'chapter_12');
    $note = k3NotesFor($this)->note('fair_value_instruments');

    expect($note)->not->toBeNull()
        ->and($note->row)->toBe(6)
        ->and($note->blocking)->not->toBeNull();
});

it('S1-R8.1 row 7 reconciles the revaluation reserve only when it changed in the year (ÅRL 5:11)', function () {
    expect(k3NotesFor($this)->note('revaluation_reserve'))->toBeNull();

    k3NotesPost($this, [1110 => 50_000_000, 2085 => -50_000_000]);
    $note = k3NotesFor($this)->note('revaluation_reserve');

    expect($note)->not->toBeNull()
        ->and($note->row)->toBe(7)
        ->and($note->data)->toBe(['opening_ore' => 0, 'change_ore' => 50_000_000, 'closing_ore' => 50_000_000]);
});

it('S1-R8.1 row 8 states liabilities due after five years, and asks while long-term debt has no answer (ÅRL 5:13)', function () {
    expect(k3NotesFor($this)->note('long_term_liabilities'))->toBeNull();

    k3NotesPost($this, [1930 => 80_000_000, 2350 => -80_000_000]);
    $notes = k3NotesFor($this);

    expect($notes->note('long_term_liabilities'))->toBeNull()
        ->and(array_column($notes->questions(), 'key'))->toContain('long_term_liabilities');

    $this->report->forceFill(['k3_long_term_liabilities_ore' => 30_000_000])->save();
    $notes = k3NotesFor($this);
    $note = $notes->note('long_term_liabilities');

    expect($note->row)->toBe(8)
        ->and($note->data['due_after_five_years_ore'])->toBe(30_000_000)
        ->and(array_column($notes->questions(), 'key'))->not->toContain('long_term_liabilities');
});

it('S1-R8.1 row 8 asks when the amount due after five years exceeds the long-term debt in the books, or the books show none', function () {
    $this->report->forceFill(['k3_long_term_liabilities_ore' => 30_000_000])->save();

    expect(k3NotesFor($this)->note('long_term_liabilities')->facts)->toHaveCount(1);

    k3NotesPost($this, [1930 => 20_000_000, 2350 => -20_000_000]);

    expect(k3NotesFor($this)->note('long_term_liabilities')->facts)->toHaveCount(1);

    k3NotesPost($this, [1930 => 10_000_000, 2350 => -10_000_000]);

    expect(k3NotesFor($this)->note('long_term_liabilities')->facts)->toBe([]);
});

it('S1-R8.1 row 9 lists the security pledged (ÅRL 5:14, K3 8.6A)', function () {
    expect(k3NotesFor($this)->note('pledged_assets'))->toBeNull();

    $this->report->forceFill(['pledged_assets' => ['company_mortgage' => 50_000_000, 'property_mortgage' => 20_000_000]])->save();
    $note = k3NotesFor($this)->note('pledged_assets');

    expect($note->row)->toBe(9)
        ->and(array_column($note->data['rows'], 'amount', 'kind'))->toBe(['company_mortgage' => 50_000_000, 'property_mortgage' => 20_000_000])
        ->and($note->data['total_ore'])->toBe(70_000_000);
});

it('S1-R8.1 row 10 states contingent liabilities, and asks for an estimate when there is none (ÅRL 5:15, K3 21.16A)', function () {
    expect(k3NotesFor($this)->note('contingent_liabilities'))->toBeNull();

    $this->report->forceFill(['contingent_liabilities_text' => 'Borgen för leverantörens lån'])->save();
    $note = k3NotesFor($this)->note('contingent_liabilities');

    expect($note->row)->toBe(10)
        ->and($note->missing)->toHaveCount(1);

    $this->report->forceFill(['contingent_liabilities_ore' => 10_000_000])->save();
    $note = k3NotesFor($this)->note('contingent_liabilities');

    expect($note->data['amount_ore'])->toBe(10_000_000)
        ->and($note->missing)->toBe([]);
});

it('S1-R8.1 row 11 states commitments for group and associated companies (ÅRL 5:16)', function () {
    expect(k3NotesFor($this)->note('group_commitments'))->toBeNull();

    $this->report->forceFill(['group_commitments' => 'Borgen till förmån för dotterbolaget, 500 000 kr.'])->save();

    expect(k3NotesFor($this)->note('group_commitments')->row)->toBe(11);
});

it('S1-R8.1 row 12 states off-balance arrangements, without the economic effect for a mindre företag (ÅRL 5:17)', function () {
    expect(k3NotesFor($this)->note('off_balance_arrangements'))->toBeNull();

    $this->report->forceFill(['k3_off_balance_arrangements' => 'Factoringavtal utan regress för kundfordringar.'])->save();
    $note = k3NotesFor($this)->note('off_balance_arrangements');

    expect($note->row)->toBe(12)
        ->and($note->data)->not->toHaveKey('economic_effect');
});

it('S1-R8.1 row 13 states loans to the board and the managing director (ÅRL 5:18)', function () {
    expect(k3NotesFor($this)->note('loans_to_executives'))->toBeNull();

    $this->report->forceFill(['k3_loans_to_executives' => 'Lån till verkställande direktören 100 000 kr, ränta 4 %, amorteras med 20 000 kr per år.'])->save();

    expect(k3NotesFor($this)->note('loans_to_executives')->row)->toBe(13);
});

it('S1-R8.1 rows 11, 12, 13 and 16 ask whether the note applies, and an answered no closes the question', function (string $key, string $column) {
    expect(array_column(k3NotesFor($this)->questions(), 'key'))->toContain($key);

    $this->report->forceFill(['k3_answers' => [$key => true]])->save();

    expect(array_column(k3NotesFor($this)->questions(), 'key'))->toContain($key)
        ->and(k3NotesFor($this)->note($key))->toBeNull();

    $this->report->forceFill(['k3_answers' => [$key => false]])->save();

    expect(array_column(k3NotesFor($this)->questions(), 'key'))->not->toContain($key);

    $this->report->forceFill(['k3_answers' => [], $column => 'Text.'])->save();

    expect(array_column(k3NotesFor($this)->questions(), 'key'))->not->toContain($key)
        ->and(k3NotesFor($this)->note($key))->not->toBeNull();
})->with([
    ['group_commitments', 'group_commitments'],
    ['off_balance_arrangements', 'k3_off_balance_arrangements'],
    ['loans_to_executives', 'k3_loans_to_executives'],
    ['parent_company', 'k3_parent_company'],
]);

it('S1-R8.1 row 14 states the nature and amount of each exceptional item (ÅRL 5:19)', function () {
    expect(k3NotesFor($this)->note('exceptional_items'))->toBeNull();

    $this->report->forceFill(['k3_exceptional_items' => [['description' => 'Försäkringsersättning efter brand', 'amount_ore' => 1_200_000]]])->save();
    $note = k3NotesFor($this)->note('exceptional_items');

    expect($note->row)->toBe(14)
        ->and($note->section)->toBe(K3Notes::ITEMS)
        ->and($note->data['items'])->toBe([['description' => 'Försäkringsersättning efter brand', 'amount_ore' => 1_200_000]]);
});

it('S1-R8.1 row 15 always gives the average number of employees, as full-time equivalents rounded to whole persons (ÅRL 5:20, K3 8.6B)', function () {
    $note = k3NotesFor($this)->note('average_employees');

    expect($note->row)->toBe(15)
        ->and($note->data['average_employees'])->toBe(2);

    $this->report->forceFill(['average_employees_fte' => '3.40'])->save();

    expect(k3NotesFor($this)->note('average_employees')->data['average_employees'])->toBe(3);
});

it('S1-R8.1 row 15 asks for the average number of employees instead of printing 0 when nothing is entered', function () {
    $this->report->delete();
    $save = fn (array $input) => app(SaveK3ReportInputs::class)($this->owner, $this->year->refresh(), $input);
    $this->report = $save(['k3_external_factors' => 'Räntor.']);
    $note = k3NotesFor($this)->note('average_employees');

    expect($note->data['average_employees'])->toBeNull()
        ->and($note->generatedText)->toBe([])
        ->and($note->facts)->toHaveCount(1);
});

it('S1-R8.1 row 15 lets a company without employees close the question through the action, and prints 0', function () {
    $this->report->delete();
    $this->report = app(SaveK3ReportInputs::class)($this->owner, $this->year->refresh(), ['k3_answers' => ['no_employees' => true]]);
    $note = k3NotesFor($this)->note('average_employees');

    expect($note->data['average_employees'])->toBe(0)
        ->and($note->facts)->toBe([])
        ->and(implode(' ', $note->generatedText))->toContain('varit 0');
});

it('S1-R8.1 row 15 takes the full-time equivalents a person enters through the action', function () {
    $this->report->delete();
    $this->report = app(SaveK3ReportInputs::class)($this->owner, $this->year->refresh(), ['average_employees_fte' => '3,40']);
    $note = k3NotesFor($this)->note('average_employees');

    expect($this->report->refresh()->average_employees_fte)->toBe('3.40')
        ->and($note->data['average_employees'])->toBe(3)
        ->and($note->facts)->toBe([]);
});

it('S1-R8.1 row 21 asks every K3 year after the first whether a material error was corrected retroactively', function () {
    expect(array_column(k3NotesFor($this)->questions(), 'key'))->not->toContain('material_error_corrected');

    k3NotesPreviousYear($this, ReportingFramework::K3);

    expect(array_column(k3NotesFor($this)->questions(), 'key'))->toContain('material_error_corrected');

    $this->report->forceFill(['k3_answers' => ['material_error_corrected' => false]])->save();

    expect(array_column(k3NotesFor($this)->questions(), 'key'))->not->toContain('material_error_corrected');
});

it('S1-R8.1 row 16 names the parent company of the smallest group that prepares group accounts (ÅRL 5:21)', function () {
    expect(k3NotesFor($this)->note('parent_company'))->toBeNull();

    $this->report->forceFill(['k3_parent_company' => 'Fiktiva Moderbolaget AB, 556036-0793, Stockholm'])->save();

    expect(k3NotesFor($this)->note('parent_company')->row)->toBe(16);
});

it('S1-R8.1 row 17 states significant events after the year, and says when the effect cannot be estimated (ÅRL 5:22, K3 32.4)', function () {
    expect(k3NotesFor($this)->note('events_after'))->toBeNull();

    $this->report->forceFill(['events_after' => 'Bolaget har tecknat ett nytt hyresavtal.', 'events_effect_unknown' => true])->save();
    $note = k3NotesFor($this)->note('events_after');

    expect($note->row)->toBe(17)
        ->and($note->data['effect_unknown'])->toBeTrue()
        ->and(implode(' ', $note->generatedText))->toContain('kan inte uppskattas');
});

it('S1-R8.1 row 18 gives related-party transactions only for a public company (ÅRL 5:23 third paragraph)', function () {
    ClosingCheck::create(['fiscal_year_id' => $this->year->id, 'key' => 'k2_1_1a_a.no', 'checked_by' => $this->owner->id]);

    expect(k3NotesFor($this)->note('related_parties'))->toBeNull();

    ClosingCheck::query()->delete();
    ClosingCheck::create(['fiscal_year_id' => $this->year->id, 'key' => 'k2_1_1a_a.yes', 'checked_by' => $this->owner->id]);
    $note = k3NotesFor($this)->note('related_parties');

    expect($note->row)->toBe(18)
        ->and($note->missing)->toHaveCount(1);
});

it('S1-R8.1 row 19 states the revenue principles when the company has revenue (K3 23.29)', function () {
    expect(k3NotesFor($this)->note('revenue_principles'))->toBeNull();

    k3NotesPost($this, [1930 => 10_000_000, 3001 => -10_000_000]);
    $note = k3NotesFor($this)->note('revenue_principles');

    expect($note->row)->toBe(19)
        ->and($note->section)->toBe(K3Notes::PRINCIPLES)
        ->and($note->data['fixed_price_contracts'])->toBe('none');
});

it('S1-R8.1 row 20 describes a change of principle in the year (K3 10.6, 10.7)', function () {
    expect(k3NotesFor($this)->note('change_of_principle'))->toBeNull();

    CompanyAccountingPolicy::query()->where('key', 'fixed_price_contracts')->delete();
    CompanyAccountingPolicy::create(['key' => 'fixed_price_contracts', 'value' => 'completed_contract_23_31', 'applies_from' => '2025-01-01', 'set_by' => $this->owner->id]);
    CompanyAccountingPolicy::create(['key' => 'fixed_price_contracts', 'value' => 'percentage_of_completion', 'applies_from' => '2026-01-01', 'set_by' => $this->owner->id]);
    $note = k3NotesFor($this)->note('change_of_principle');

    expect($note->row)->toBe(20)
        ->and($note->data['changes'])->toBe([['key' => 'fixed_price_contracts', 'rule' => 'K3 10.2']])
        ->and($note->blocking)->not->toBeNull();
});

it('S1-R8.1 row 21 is not triggered by a plain correcting voucher through this year\'s result (K3 10.14)', function () {
    k3NotesPreviousYear($this, ReportingFramework::K3);
    $wrong = k3NotesPost($this, [5010 => 500_000, 1930 => -500_000], '2025-06-01');
    app(CorrectVoucher::class)($this->owner, $wrong, 'Fel konto', '2026-02-01');

    expect(k3NotesFor($this)->note('error_correction'))->toBeNull();
});

it('S1-R8.1 row 21 states the lack of comparability after an error corrected against opening equity (K3 6.3 b, 10.14)', function () {
    k3NotesPreviousYear($this, ReportingFramework::K3);
    $wrong = k3NotesPost($this, [2091 => 500_000, 1930 => -500_000], '2025-06-01');
    app(CorrectVoucher::class)($this->owner, $wrong, 'Fel i tidigare år', '2026-02-01');
    $note = k3NotesFor($this)->note('error_correction');

    expect($note->row)->toBe(21)
        ->and($note->data['corrections'])->toHaveCount(1)
        ->and($note->data['answered'])->toBeFalse();
});

it('S1-R8.1 row 21 is triggered by the answer that a material error was corrected retroactively', function () {
    $this->report->forceFill(['k3_answers' => ['material_error_corrected' => true]])->save();
    $note = k3NotesFor($this)->note('error_correction');

    expect($note->row)->toBe(21)
        ->and($note->data['answered'])->toBeTrue();
});

it('S1-R8.1 rows 22 and 23 disclose the first application of K3 and the lacking comparability (K3 35.7, 35.31, 35.33; ÅRL 3:5)', function () {
    k3NotesPreviousYear($this, ReportingFramework::K2);

    foreach (['comparatives_restated_at_transition' => 'no', 'k3_applied_before' => 'no', 'left_k2_reason' => 'forced_2025_2'] as $key => $value) {
        app(SetAccountingPolicy::class)($this->owner, $this->company, $this->year->id, K3PolicyKey::from($key), $value);
    }

    $notes = k3NotesFor($this);
    $first = $notes->note('first_time_application');
    $comparability = $notes->note('comparability');

    expect($first->row)->toBe(22)
        ->and($first->data['transition_date'])->toBe('2026-01-01')
        ->and($first->data['equity_effect_ore'])->toBe(0)
        ->and(implode(' ', $first->generatedText))->toContain('första gången')
        ->and($comparability->row)->toBe(23)
        ->and($comparability->data['previous_label'])->not->toBeNull()
        ->and(implode(' ', $comparability->generatedText))->toContain('inte räknats om');
});

it('S1-R8.1 rows 22 and 23 are absent in a year after the first K3 year', function () {
    k3NotesPreviousYear($this, ReportingFramework::K3);

    $keys = k3NoteKeys(k3NotesFor($this));

    expect($keys)->not->toContain('first_time_application')
        ->and($keys)->not->toContain('comparability');
});

it('S1-R8.1 row 23 is absent when the comparatives are restated', function () {
    k3NotesPreviousYear($this, ReportingFramework::K2);

    foreach (['comparatives_restated_at_transition' => 'yes', 'k3_applied_before' => 'no', 'left_k2_reason' => 'voluntary'] as $key => $value) {
        app(SetAccountingPolicy::class)($this->owner, $this->company, $this->year->id, K3PolicyKey::from($key), $value);
    }

    $keys = k3NoteKeys(k3NotesFor($this));

    expect($keys)->toContain('first_time_application')
        ->and($keys)->not->toContain('comparability');
});

it('S1-R8.1 row 24 gives comparatives for merged items only when items are merged, which NONOX never does (K3 3.5 comment)', function () {
    expect(k3NotesFor($this)->note('merged_items'))->toBeNull()
        ->and(K3Notes::MERGED_ITEMS)->toBe([]);

    $note = k3NotesFor($this, mergedItems: ['other_receivables'])->note('merged_items');

    expect($note->row)->toBe(24)
        ->and($note->data['items'])->toBe(['other_receivables']);
});

it('S1-R8.1 row 25 splits an aktiebolag\'s equity into bound and free from the columns of the equity statement (ÅRL 3:10 a)', function () {
    OpeningBalance::create(['fiscal_year_id' => $this->year->id, 'account_id' => Account::query()->where('number', 1930)->value('id'), 'amount_ore' => 2_500_000]);
    OpeningBalance::create(['fiscal_year_id' => $this->year->id, 'account_id' => Account::query()->where('number', 2081)->value('id'), 'amount_ore' => -2_500_000]);
    k3NotesPost($this, [1930 => 10_000_000, 3001 => -10_000_000]);

    $note = k3NotesFor($this)->note('equity_split');

    expect($note->row)->toBe(25)
        ->and(array_column($note->data['bound'], 'amount_ore', 'key'))->toBe(['share_capital' => 2_500_000])
        ->and(array_column($note->data['free'], 'amount_ore', 'key'))->toBe(['year_result' => 10_000_000])
        ->and($note->data['bound_total_ore'])->toBe(2_500_000)
        ->and($note->data['free_total_ore'])->toBe(10_000_000);
});

it('S1-R8.1 row 25 is absent for a company that is not an aktiebolag', function () {
    $this->company->forceFill(['legal_form' => LegalForm::EkonomiskForening])->save();

    expect(k3NotesFor($this)->note('equity_split'))->toBeNull();
});

it('S1-R8.1 row 26 states a departure from a general rule and its reasons (ÅRL 2:3)', function () {
    expect(k3NotesFor($this)->note('departures'))->toBeNull();

    $this->report->forceFill(['k3_departures' => 'Avvikelse från K3 punkt 17.24 eftersom …'])->save();

    expect(k3NotesFor($this)->note('departures')->row)->toBe(26);
});

it('S1-R8.1 row 27 states the model for internally generated intangibles when the company has development expenditure (K3 18.28 comment)', function () {
    expect(k3NotesFor($this)->note('intangibles_principles'))->toBeNull();

    k3NotesPost($this, [1012 => 3_000_000, 1930 => -3_000_000]);
    $note = k3NotesFor($this)->note('intangibles_principles');

    expect($note->row)->toBe(27)
        ->and($note->section)->toBe(K3Notes::PRINCIPLES)
        ->and($note->data['model'])->toBe('expense');
});

it('S1-R3.7 and S1-R4.7 state the depreciation and income tax principles for a mindre företag in note 1', function () {
    $notes = k3NotesFor($this);
    $tax = $notes->note('income_tax_principles');
    $fixed = $notes->note('fixed_assets_principles');

    expect($tax->section)->toBe(K3Notes::PRINCIPLES)
        ->and(implode(' ', $tax->generatedText))->toContain('uppskjuten skatt')
        ->and(implode(' ', $tax->generatedText))->toContain('obeskattade reserver')
        ->and($fixed->data['components'])->toBe('buildings_always_others_by_choice');
});

it('S1-R9.3 gives no större-only note to a mindre company, even when every trigger holds', function () {
    k3NotesPost($this, [1930 => 10_000_000, 3001 => -10_000_000]);
    k3NotesPost($this, [1930 => 80_000_000, 2350 => -80_000_000]);
    $this->report->forceFill(['k3_long_term_liabilities_ore' => 1, 'events_after' => 'x', 'k3_departures' => 'x', 'k3_parent_company' => 'x'])->save();

    $keys = k3NoteKeys(k3NotesFor($this));

    foreach (K3Notes::LARGER_COMPANY_ONLY as $key) {
        expect($keys)->not->toContain($key);
    }
});

it('S1-H1 refuses the notes of a större or undetermined company and of a K2 year', function () {
    expect(k3NotesFor($this)->refusal())->toBeNull()
        ->and(k3NotesFor($this, K3SizeClass::Storre)->refusal())->toContain('större')
        ->and(k3NotesFor($this, K3SizeClass::Undetermined)->refusal())->not->toBeNull();

    $this->year->forceFill(['reporting_framework' => ReportingFramework::K2])->save();

    expect(k3NotesFor($this)->refusal())->not->toBeNull();
});

it('reads the notes with a fixed number of queries, however long the ledger and the register', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        k3NotesFor($this)->notes();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    k3NotesPost($this, [1930 => 10_000_000, 3001 => -10_000_000]);
    k3NotesPost($this, [1220 => 1_000_000, 1930 => -1_000_000]);
    $short = $count();

    foreach (range(1, 15) as $i) {
        k3NotesPost($this, [1930 => 100_000 + $i, 3001 => -(100_000 + $i)], '2026-04-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        app(FixedAssets::class)->save($this->owner, $this->company, [
            'name' => "Maskin {$i}", 'category' => 'EQUIPMENT', 'acquired_on' => '2026-01-10',
            'acquisition' => '1000,00', 'opening_accumulated' => '', 'life_months' => (string) (12 * $i), 'depreciation_start' => '2026-01-01',
            'asset_account' => '1220', 'accumulated_account' => '1229', 'expense_account' => '7832',
        ]);
    }

    expect($count())->toBe($short);
});
