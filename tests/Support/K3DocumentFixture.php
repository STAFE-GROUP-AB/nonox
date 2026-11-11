<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\AnnualReport\BookK3Transition;
use App\Actions\AnnualReport\K3NoteOverrides;
use App\Actions\AnnualReport\K3TransitionItems;
use App\Actions\AnnualReport\SaveK3ReportInputs;
use App\Actions\AnnualReport\SetAccountingPolicy;
use App\Actions\Assets\FixedAssets;
use App\Domain\Accounting\FiscalYearStatus;
use App\Domain\AnnualReport\K3PolicyKey;
use App\Domain\AnnualReport\K3TransitionProposal;
use App\Domain\Company\LegalForm;
use App\Models\AnnualReport;
use App\Models\PriorYearSizeFigure;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;

/**
 * F-S1-b as the K3 document tests load it (S1-P10): the fixture with its
 * policies, the building split, the transition entered and booked, the size
 * figures and both annual reports. Shared by K3AnnualReportDocumentTest and
 * the performance test (S1-P14, tests/Performance), which runs on its own.
 */
final class K3DocumentFixture
{
    /** Öre as a person types it: "3600000,00". */
    public static function kronor(int $ore): string
    {
        return sprintf('%d,%02d', intdiv($ore, 100), $ore % 100);
    }

    /**
     * F-S1-b with its policies, the building split, the transition entered and
     * (unless $book is false) booked, the size figures and both annual reports.
     */
    public static function load(object $test, bool $book = true): void
    {
        $test->travelTo(CarbonImmutable::parse('2027-02-10 10:00:00'));
        $test->fixture = S1Fixture::load('f-s1-b', fn (array $voucher): bool => ($voucher['proposal_kind'] ?? null) !== 'k3_transition');
        $test->company = $test->fixture->company;
        $test->owner = $test->fixture->owner;
        // The fixture's synthetic organisation number, so the golden file does not depend on the factory.
        $test->company->forceFill(['legal_form' => LegalForm::Ab, 'org_number' => '559999-6062'])->save();
        CurrentCompany::set($test->company);
        $test->actingAs($test->owner);
        $test->k2 = $test->fixture->years[2025];
        $test->year = $test->fixture->years[2026];
        $data = json_decode((string) file_get_contents(base_path('tests/Fixtures/s1/f-s1-b/company.json')), true);

        foreach ($data['accounting_policies_2026'] as $key => $value) {
            app(SetAccountingPolicy::class)($test->owner, $test->company, $test->year->id, K3PolicyKey::from($key), $value);
        }

        $test->k2->forceFill(['status' => FiscalYearStatus::Closed, 'closed_at' => now()])->save();

        $building = $data['fixed_asset_register'][0];
        $assets = app(FixedAssets::class);
        $asset = $assets->save($test->owner, $test->company, [
            'name' => $building['name'], 'category' => $building['category'], 'acquired_on' => $building['acquired_on'],
            'acquisition' => self::kronor($building['acquisition_ore']), 'opening_accumulated' => self::kronor($building['opening_accumulated_ore']),
            'life_months' => (string) $building['life_months'], 'depreciation_start' => $building['depreciation_start'],
            'asset_account' => (string) $building['asset_account'], 'accumulated_account' => (string) $building['accumulated_account'], 'expense_account' => (string) $building['expense_account'],
        ]);
        $assets->split($test->owner, $test->company, $asset->id, $building['split_on'], array_map(fn (array $component): array => [
            'name' => $component['name'], 'kind' => $component['kind'], 'cost' => self::kronor($component['cost_ore']),
            'life_months' => (string) $component['remaining_life_months'], 'residual' => '', 'asset_account' => '',
        ], $building['components']));

        foreach ($data['transition_items'] as $item) {
            $input = ['kind' => $item['kind'], 'description' => $item['description']];
            $input += $item['kind'] === 'DEFERRED_TAX'
                ? [
                    'deferred_tax_kind' => $item['deferred_tax_kind'],
                    'account_from' => (string) ($item['account_from'] ?? ''),
                    'account_to' => (string) ($item['account_to'] ?? ''),
                    'carrying' => isset($item['carrying_ore']) ? self::kronor($item['carrying_ore']) : '',
                    'tax_value' => isset($item['tax_value_ore']) ? self::kronor($item['tax_value_ore']) : '',
                    'recognised' => isset($item['recognised_ore']) ? self::kronor($item['recognised_ore']) : '',
                    'recognition_reason' => $item['recognition_reason'] ?? '',
                    'relief' => $item['relief_35_27'] ?? false,
                    'relief_reason' => $item['relief_reason'] ?? '',
                ]
                : ['account' => (string) $item['account'], 'amount' => self::kronor($item['amount_ore']), 'k3_reference' => $item['k3_reference']];

            app(K3TransitionItems::class)->save($test->owner, $test->year, $input);
        }

        if ($book) {
            app(BookK3Transition::class)($test->owner, $test->year, K3TransitionProposal::for($test->company, $test->year)->fingerprint());
        }

        $prior = $data['prior_year_figures_2024'];
        PriorYearSizeFigure::create([
            'start_date' => '2024-01-01', 'end_date' => '2024-12-31', 'average_employees_fte' => $prior['average_employees_fte'],
            'balance_sheet_total_ore' => (int) str_replace(' ', '', $prior['balance_sheet_total']) * 100,
            'net_turnover_ore' => (int) str_replace(' ', '', $prior['net_turnover']) * 100,
            'entered_by' => $test->owner->id,
        ]);
        AnnualReport::create(['fiscal_year_id' => $test->k2->id, 'average_employees' => 3, 'average_employees_fte' => '3.00', 'signatories' => [], 'updated_by' => $test->owner->id]);
        $test->report = AnnualReport::create([
            'fiscal_year_id' => $test->year->id, 'average_employees' => 3, 'average_employees_fte' => '3.00',
            'business_description' => 'Bolaget äger och hyr ut en verkstadsbyggnad och utför reparationer. Bolaget har sitt säte i Exempelstad.',
            'signing_place' => 'Exempelstad', 'signing_date' => '2027-03-15',
            'signatories' => [['name' => 'Testa Testsson', 'role' => 'Styrelseledamot']], 'updated_by' => $test->owner->id,
        ]);
    }

    /** Answers every open question of F-S1-b the way a person would. */
    public static function answerQuestions(object $test): void
    {
        app(SaveK3ReportInputs::class)($test->owner, $test->year, [
            'k3_answers' => ['group_commitments' => false, 'off_balance_arrangements' => false, 'loans_to_executives' => false, 'parent_company' => false],
            'k3_major_owners' => [],
            'k3_long_term_liabilities' => '1000000',
        ]);
        app(K3NoteOverrides::class)->set($test->owner, $test->year, 'fixed_assets.equipment', 'Inventarier skrivs av linjärt över fem år.', 'Inventarierna finns bara i bokföringen, inte i anläggningsregistret.');
    }
}
