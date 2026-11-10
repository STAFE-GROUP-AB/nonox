<?php

declare(strict_types=1);

use App\Actions\AnnualReport\BookK3Transition;
use App\Actions\AnnualReport\K3TransitionItems;
use App\Actions\AnnualReport\SetAccountingPolicy;
use App\Actions\Assets\FixedAssets;
use App\Domain\Accounting\FiscalYearStatus;
use App\Domain\AnnualReport\K3CompanySize;
use App\Domain\AnnualReport\K3Notes;
use App\Domain\AnnualReport\K3PolicyKey;
use App\Domain\AnnualReport\K3SizeClass;
use App\Domain\AnnualReport\K3TransitionProposal;
use App\Domain\Company\LegalForm;
use App\Models\AnnualReport;
use App\Models\PriorYearSizeFigure;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Tests\Support\S1Fixture;

/*
 * S1-P9 driven by the S1 fixtures (ADR-059 A.5):
 * - F-S1-b, a mindre aktiebolag in its first K3 year (2026) after K2: the
 *   notes of rows 1, 4, 15, 22, 23 and 25 with the fixture's data, the
 *   transition booked as S1-P12 books it;
 * - F-S1-a, a större företag: the notes are refused until human decision
 *   S1-H1 lifts the refusal (S1-P13, outside S1).
 */

/** Öre as a person types it: "3600000,00". */
function notesFixtureKronor(int $ore): string
{
    return sprintf('%d,%02d', intdiv($ore, 100), $ore % 100);
}

/** F-S1-b with its policies, the building split, the transition items entered and booked. */
function loadNotesFixtureB(object $test): void
{
    $test->travelTo(CarbonImmutable::parse('2027-02-10 10:00:00'));
    $test->fixture = S1Fixture::load('f-s1-b', fn (array $voucher): bool => ($voucher['proposal_kind'] ?? null) !== 'k3_transition');
    $test->company = $test->fixture->company;
    $test->owner = $test->fixture->owner;
    $test->company->forceFill(['legal_form' => LegalForm::Ab])->save();
    CurrentCompany::set($test->company);
    $test->actingAs($test->owner);
    $test->k2 = $test->fixture->years[2025];
    $test->year = $test->fixture->years[2026];
    $data = json_decode((string) file_get_contents(base_path('tests/Fixtures/s1/f-s1-b/company.json')), true);
    $test->companyData = $data;

    foreach ($data['accounting_policies_2026'] as $key => $value) {
        app(SetAccountingPolicy::class)($test->owner, $test->company, $test->year->id, K3PolicyKey::from($key), $value);
    }

    $test->k2->forceFill(['status' => FiscalYearStatus::Closed, 'closed_at' => now()])->save();

    $building = $data['fixed_asset_register'][0];
    $assets = app(FixedAssets::class);
    $asset = $assets->save($test->owner, $test->company, [
        'name' => $building['name'], 'category' => $building['category'], 'acquired_on' => $building['acquired_on'],
        'acquisition' => notesFixtureKronor($building['acquisition_ore']), 'opening_accumulated' => notesFixtureKronor($building['opening_accumulated_ore']),
        'life_months' => (string) $building['life_months'], 'depreciation_start' => $building['depreciation_start'],
        'asset_account' => (string) $building['asset_account'], 'accumulated_account' => (string) $building['accumulated_account'], 'expense_account' => (string) $building['expense_account'],
    ]);
    $assets->split($test->owner, $test->company, $asset->id, $building['split_on'], array_map(fn (array $component): array => [
        'name' => $component['name'], 'kind' => $component['kind'], 'cost' => notesFixtureKronor($component['cost_ore']),
        'life_months' => (string) $component['remaining_life_months'], 'residual' => '', 'asset_account' => '',
    ], $building['components']));

    foreach ($data['transition_items'] as $item) {
        $input = ['kind' => $item['kind'], 'description' => $item['description']];
        $input += $item['kind'] === 'DEFERRED_TAX'
            ? [
                'deferred_tax_kind' => $item['deferred_tax_kind'],
                'account_from' => (string) ($item['account_from'] ?? ''),
                'account_to' => (string) ($item['account_to'] ?? ''),
                'carrying' => isset($item['carrying_ore']) ? notesFixtureKronor($item['carrying_ore']) : '',
                'tax_value' => isset($item['tax_value_ore']) ? notesFixtureKronor($item['tax_value_ore']) : '',
                'recognised' => isset($item['recognised_ore']) ? notesFixtureKronor($item['recognised_ore']) : '',
                'recognition_reason' => $item['recognition_reason'] ?? '',
                'relief' => $item['relief_35_27'] ?? false,
                'relief_reason' => $item['relief_reason'] ?? '',
            ]
            : ['account' => (string) $item['account'], 'amount' => notesFixtureKronor($item['amount_ore']), 'k3_reference' => $item['k3_reference']];

        app(K3TransitionItems::class)->save($test->owner, $test->year, $input);
    }

    app(BookK3Transition::class)($test->owner, $test->year, K3TransitionProposal::for($test->company, $test->year)->fingerprint());

    // The size test reads 2025 from the ledger and its annual report, 2024 from the figures a person entered.
    $prior = $data['prior_year_figures_2024'];
    PriorYearSizeFigure::create([
        'start_date' => '2024-01-01', 'end_date' => '2024-12-31', 'average_employees_fte' => $prior['average_employees_fte'],
        'balance_sheet_total_ore' => (int) str_replace(' ', '', $prior['balance_sheet_total']) * 100,
        'net_turnover_ore' => (int) str_replace(' ', '', $prior['net_turnover']) * 100,
        'entered_by' => $test->owner->id,
    ]);
    AnnualReport::create(['fiscal_year_id' => $test->k2->id, 'average_employees' => 3, 'average_employees_fte' => '3.00', 'signatories' => [], 'updated_by' => $test->owner->id]);
    $test->report = AnnualReport::create(['fiscal_year_id' => $test->year->id, 'average_employees' => 3, 'average_employees_fte' => '3.00', 'signatories' => [], 'updated_by' => $test->owner->id]);
}

describe('F-S1-b', function () {
    beforeEach(function () {
        loadNotesFixtureB($this);
        $this->size = K3CompanySize::forFiscalYear($this->year->refresh());
        $this->notes = K3Notes::for($this->company->refresh(), $this->year->refresh(), $this->report->refresh(), $this->size->class);
        $this->disclosures = $this->fixture->expected['transition']['disclosures'];
    });

    it('S1-R9 classifies F-S1-b as a mindre företag, so its notes are not refused', function () {
        expect($this->size->class)->toBe(K3SizeClass::Mindre)
            ->and($this->notes->refusal())->toBeNull();
    });

    it('S1-R8.1 row 1 states ÅRL and K3 and lists F-S1-b\'s accounting policies in force (K3 8.3)', function () {
        $note = $this->notes->note('accounting_principles');
        $policies = array_intersect_key($this->companyData['accounting_policies_2026'], array_flip(K3Notes::PRINCIPLE_POLICIES));

        expect(array_column($note->data['policies'], 'value', 'key'))->toEqualCanonicalizing($policies)
            ->and($note->data['missing_policies'])->toBe([])
            ->and(implode(' ', $note->generatedText))->toContain('BFNAR 2012:1');
    });

    it('S1-R8.1 row 4 and S1-R3.7 give the building\'s total useful lives per component and say that land is not depreciated (K3 17.23)', function () {
        $note = $this->notes->note('fixed_assets.buildings');
        $lives = array_map(fn (array $component): int => intdiv($component['total_life_months'], 12), $this->fixture->expected['transition']['components']['components']);

        expect($note->data['lives'])->toBe([['category' => 'BUILDINGS', 'label' => 'Byggnader', 'years' => min($lives).'–'.max($lives)]])
            ->and($note->data['land'])->toBeTrue()
            ->and(implode(' ', $note->generatedText))->toContain('Mark skrivs inte av');
    });

    it('S1-R8.1 row 4 asks for the useful life of F-S1-b\'s equipment, which is only in the ledger', function () {
        $note = $this->notes->note('fixed_assets.equipment');

        expect($note->data['lives'])->toBe([])
            ->and($note->missing)->toHaveCount(1);
    });

    it('S1-R8.1 row 15 gives F-S1-b\'s average number of employees (K3 8.6B)', function () {
        expect($this->notes->note('average_employees')->data['average_employees'])->toBe(3);
    });

    it('S1-R8.1 row 22 and S1-R7.5 disclose F-S1-b\'s first application of K3 with the changed principles and the equity effect', function () {
        $note = $this->notes->note('first_time_application');

        expect($note->data['changed_principles'])->toBe($this->disclosures['changed_principles'])
            ->and($note->data['reliefs'])->toBe($this->disclosures['reliefs_used'])
            ->and($note->data['equity_effect_ore'])->toBe($this->disclosures['equity_effect_ore'])
            ->and($note->data['booked'])->toBeTrue()
            ->and(implode(' ', $note->generatedText))->toContain(Money::ofOre(abs($this->disclosures['equity_effect_ore']))->format());
    });

    it('S1-R8.1 row 23 states that F-S1-b\'s 2025 comparatives are K2 figures that were not restated (ÅRL 3:5 st. 4, K3 35.7)', function () {
        $note = $this->notes->note('comparability');

        expect($this->disclosures['comparatives_not_restated'])->toBeTrue()
            ->and($note->data['previous_label'])->toBe('2025')
            ->and(implode(' ', $note->generatedText))->toContain('BFNAR 2016:10');
    });

    it('S1-R8.1 row 25 splits F-S1-b\'s closing equity into bound and free, adding up to the K3 equity of the reconciliation', function () {
        $note = $this->notes->note('equity_split');

        expect($note->data['bound_total_ore'] + $note->data['free_total_ore'])->toBe($this->fixture->expected['transition']['reconciliation']['equity_k3_ore']);
    });

    it('S1-R9.3 gives F-S1-b no större-only note', function () {
        $keys = array_map(fn ($note): string => $note->key, $this->notes->notes());

        expect(array_intersect($keys, K3Notes::LARGER_COMPANY_ONLY))->toBe([]);
    });
});

it('S1-H1 refuses the notes of F-S1-a, a större företag', function () {
    $this->travelTo(CarbonImmutable::parse('2027-02-10 10:00:00'));
    $fixture = S1Fixture::load('f-s1-a');
    CurrentCompany::set($fixture->company);
    $sizes = $fixture->expected['size_classification']['years'];

    PriorYearSizeFigure::create([
        'start_date' => '2024-01-01', 'end_date' => '2024-12-31', 'average_employees_fte' => (string) $sizes['2024']['medelantal_anstallda'],
        'balance_sheet_total_ore' => $sizes['2024']['balansomslutning'], 'net_turnover_ore' => $sizes['2024']['nettoomsattning'], 'entered_by' => $fixture->owner->id,
    ]);
    AnnualReport::create(['fiscal_year_id' => $fixture->years[2025]->id, 'average_employees' => 30, 'average_employees_fte' => '30.00', 'signatories' => [], 'updated_by' => $fixture->owner->id]);
    $report = AnnualReport::create(['fiscal_year_id' => $fixture->years[2026]->id, 'average_employees' => 30, 'signatories' => [], 'updated_by' => $fixture->owner->id]);
    $size = K3CompanySize::forFiscalYear($fixture->years[2026]->refresh());

    $notes = K3Notes::for($fixture->company->refresh(), $fixture->years[2026]->refresh(), $report, $size->class);

    expect($size->class)->toBe(K3SizeClass::Storre)
        ->and($notes->refusal())->toContain('S1-H1');
});
