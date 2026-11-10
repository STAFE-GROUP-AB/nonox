<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Accounting\CarryForwardBalances;
use App\Actions\Accounting\ChangeFirstFiscalYear;
use App\Actions\Accounting\CreateNextFiscalYear;
use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Actions\AnnualReport\BookK3Transition;
use App\Actions\AnnualReport\K3NoteOverrides;
use App\Actions\AnnualReport\K3TransitionItems;
use App\Actions\AnnualReport\SaveAnnualReport;
use App\Actions\AnnualReport\SaveK3ReportInputs;
use App\Actions\AnnualReport\SetAccountingPolicy;
use App\Actions\Assets\FixedAssets;
use App\Actions\Company\ChangeReportingFramework;
use App\Actions\Company\CreateCompany;
use App\Domain\Accounting\AccountType;
use App\Domain\Accounting\FiscalYearStatus;
use App\Domain\AnnualReport\K3PolicyKey;
use App\Domain\AnnualReport\K3TransitionProposal;
use App\Domain\Company\ReportingFramework;
use App\Models\Account;
use App\Models\AnnualReport;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\OpeningBalance;
use App\Models\PriorYearSizeFigure;
use App\Models\User;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Seeder;

/**
 * A synthetic mindre aktiebolag in its first K3 year, for the screenshots of
 * Bokslut → Årsredovisning (ADR-059, S1-P10): 2024 under K2 (closed), 2025
 * under K3 with the transition booked (the workshop building split into
 * components, deferred tax against 2091), the K3 policies, the year's
 * vouchers and the annual report's texts and answers, so the K3 document is
 * complete. Every name and amount is invented; the organisation number is a
 * made-up 559999-series number that passes Luhn, never a real company's.
 *
 * The K3 year is 2025, the latest year that has ended, so the page opens it.
 * Run with `php artisan db:seed --class=K3DemoSeeder` after IndustryDemoSeeder,
 * in a demo database only. It adds the company when it is missing and changes
 * nothing else.
 */
class K3DemoSeeder extends Seeder
{
    public const string ORG_NUMBER = '559999-7011';

    public function run(CreateCompany $createCompany): void
    {
        if (CompanyScope::bypass(fn (): bool => Company::query()->where('org_number', self::ORG_NUMBER)->exists())) {
            return;
        }

        $owner = User::query()->firstOrCreate(['email' => IndustryDemoSeeder::OWNER['email']], ['name' => IndustryDemoSeeder::OWNER['name'], 'email_verified_at' => now()]);
        $company = $createCompany->create($owner, [
            'name' => 'Verkstadshuset Demo AB',
            'legal_form' => 'AB',
            'org_number' => self::ORG_NUMBER,
            'address_line1' => 'Exempelvägen 4',
            'postal_code' => '722 10',
            'city' => 'Västerås',
            'vat_period' => 'YEARLY',
            'reporting_framework' => 'K2',
            'fiscal_year_start_month' => 1,
            'accounting_method' => 'FAKTURAMETODEN',
            'vat_registered' => true,
            'framework_confirmed' => true,
            'f_skatt' => true,
            'employer_registered' => true,
        ]);
        $company->forceFill(['legal_name' => 'Verkstadshuset Demo AB'])->save();
        $owner->forceFill(['current_team_id' => $company->id])->save();

        CurrentCompany::run($company, function () use ($owner, $company): void {
            $this->accounts();
            $k2 = FiscalYear::query()->orderBy('start_date')->firstOrFail();
            app(ChangeFirstFiscalYear::class)($owner, $k2, '2024-01-01', '2024-12-31');
            $k2 = $k2->refresh();
            $this->k2Year($owner, $company, $k2);

            app(CreateNextFiscalYear::class)($owner, $company);
            app(CarryForwardBalances::class)($owner, $k2);
            $k2->forceFill(['status' => FiscalYearStatus::Closed, 'closed_at' => now(), 'closed_by' => $owner->id])->save();
            $k3 = FiscalYear::query()->whereDate('start_date', '2025-01-01')->firstOrFail();
            app(ChangeReportingFramework::class)($owner, $k3, ReportingFramework::K3, 'Första året enligt K3.', ['framework_confirmed' => true]);

            $this->policies($owner, $company, $k3->refresh());
            $this->transition($owner, $company, $k3);
            $this->k3Year($owner, $company);
            $this->reports($owner, $k2, $k3);
        });
    }

    private function accounts(): void
    {
        foreach ([1110 => 'Byggnader', 1119 => 'Ackumulerade avskrivningar på byggnader', 1130 => 'Mark', 1220 => 'Inventarier och verktyg', 1229 => 'Ackumulerade avskrivningar på inventarier och verktyg', 1370 => 'Uppskjuten skattefordran', 2091 => 'Balanserad vinst eller förlust', 2099 => 'Årets resultat', 2240 => 'Avsättningar för uppskjutna skatter', 2350 => 'Andra långfristiga skulder till kreditinstitut', 2512 => 'Beräknad inkomstskatt', 7820 => 'Avskrivningar på byggnader och markanläggningar', 7832 => 'Avskrivningar på inventarier och verktyg', 8910 => 'Skatt som belastar årets resultat', 8999 => 'Årets resultat'] as $number => $name) {
            Account::query()->firstOrCreate(['number' => $number], ['name' => $name, 'type' => AccountType::forNumber($number), 'is_active' => true]);
        }
    }

    /** 2024 under K2: opening balances and the year in summary, the result booked to 2099. */
    private function k2Year(User $owner, Company $company, FiscalYear $year): void
    {
        $accounts = Account::query()->pluck('id', 'number');

        foreach ([1110 => 400_000_000, 1119 => -72_000_000, 1130 => 50_000_000, 1220 => 30_000_000, 1229 => -6_000_000, 1930 => 90_000_000, 2081 => -10_000_000, 2091 => -167_000_000, 2099 => -20_000_000, 2350 => -280_000_000, 2440 => -15_000_000] as $number => $ore) {
            OpeningBalance::create(['fiscal_year_id' => $year->id, 'account_id' => $accounts[$number], 'amount_ore' => $ore]);
        }

        $this->post($owner, $company, '2024-05-20', 'Årsstämma: 2023 års resultat balanseras', [2099 => 20_000_000, 2091 => -20_000_000]);
        $this->post($owner, $company, '2024-12-31', 'Försäljning 2024 (sammandrag)', [1930 => 425_000_000, 3001 => -340_000_000, 2611 => -85_000_000]);
        $this->post($owner, $company, '2024-12-31', 'Material 2024 (sammandrag)', [4010 => 110_000_000, 2640 => 27_500_000, 1930 => -137_500_000]);
        $this->post($owner, $company, '2024-12-31', 'Löner och arbetsgivaravgifter 2024', [7210 => 85_000_000, 7510 => 26_707_000, 1930 => -111_707_000]);
        $this->post($owner, $company, '2024-12-31', 'Moms 2024 redovisad och betald', [2611 => 85_000_000, 2640 => -27_500_000, 1930 => -57_500_000]);
        $this->post($owner, $company, '2024-12-31', 'Räntor 2024', [8410 => 12_000_000, 1930 => -12_000_000]);
        $this->post($owner, $company, '2024-12-31', 'Amortering 2024', [2350 => 40_000_000, 1930 => -40_000_000]);
        $this->post($owner, $company, '2024-12-31', 'Avskrivning verkstadsbyggnad 2024', [7820 => 8_000_000, 1119 => -8_000_000]);
        $this->post($owner, $company, '2024-12-31', 'Avskrivning inventarier 2024', [7832 => 6_000_000, 1229 => -6_000_000]);
        $this->post($owner, $company, '2024-12-31', 'Årets skatt 2024', [8910 => 19_012_358, 2512 => -19_012_358]);
        $this->post($owner, $company, '2024-12-31', 'Årets resultat 2024', [8999 => 73_280_642, 2099 => -73_280_642]);
    }

    private function policies(User $owner, Company $company, FiscalYear $year): void
    {
        foreach ([
            'comparatives_restated_at_transition' => 'no', 'k3_applied_before' => 'no', 'left_k2_reason' => 'voluntary',
            'financial_instruments' => 'chapter_11', 'intangibles' => 'expense', 'leasing' => 'operational_in_legal_entity',
            'borrowing_costs' => 'expense', 'fixed_price_contracts' => 'none', 'cash_flow_statement' => 'none',
            'components' => 'buildings_always_others_by_choice',
        ] as $key => $value) {
            app(SetAccountingPolicy::class)($owner, $company, $year->id, K3PolicyKey::from($key), $value);
        }
    }

    /** The building split into components on the transition date, and the deferred tax on it booked against 2091. */
    private function transition(User $owner, Company $company, FiscalYear $year): void
    {
        $assets = app(FixedAssets::class);
        $building = $assets->save($owner, $company, [
            'name' => 'Verkstadsbyggnad', 'category' => 'BUILDINGS', 'acquired_on' => '2015-01-01',
            'acquisition' => '4000000,00', 'opening_accumulated' => '800000,00',
            'life_months' => '600', 'depreciation_start' => '2015-01-01',
            'asset_account' => '1110', 'accumulated_account' => '1119', 'expense_account' => '7820',
        ]);
        $assets->split($owner, $company, $building->id, '2025-01-01', [
            ['name' => 'Stomme', 'kind' => 'part', 'cost' => '2400000,00', 'life_months' => '720', 'residual' => '', 'asset_account' => ''],
            ['name' => 'Tak', 'kind' => 'part', 'cost' => '800000,00', 'life_months' => '240', 'residual' => '', 'asset_account' => ''],
            ['name' => 'Installationer', 'kind' => 'part', 'cost' => '800000,00', 'life_months' => '192', 'residual' => '', 'asset_account' => ''],
        ]);

        app(K3TransitionItems::class)->save($owner, $year, [
            'kind' => 'DEFERRED_TAX', 'description' => 'Verkstadsbyggnad', 'deferred_tax_kind' => 'BUILDING_COMPONENTS',
            'account_from' => '1110', 'account_to' => '1119', 'carrying' => '', 'tax_value' => '2400000,00',
            'recognised' => '', 'recognition_reason' => '', 'relief' => false, 'relief_reason' => '',
        ]);

        app(BookK3Transition::class)($owner, $year, K3TransitionProposal::for($company, $year)->fingerprint());
    }

    /** 2025, the first K3 year, in summary; depreciation per component. */
    private function k3Year(User $owner, Company $company): void
    {
        $this->post($owner, $company, '2025-05-20', 'Årsstämma: 2024 års resultat balanseras', [2099 => 73_280_642, 2091 => -73_280_642]);
        $this->post($owner, $company, '2025-05-31', 'Inkomstskatt 2024 betald', [2512 => 19_012_358, 1930 => -19_012_358]);
        $this->post($owner, $company, '2025-12-31', 'Försäljning 2025 (sammandrag)', [1930 => 487_500_000, 3001 => -390_000_000, 2611 => -97_500_000]);
        $this->post($owner, $company, '2025-12-31', 'Material 2025 (sammandrag)', [4010 => 130_000_000, 2640 => 32_500_000, 1930 => -162_500_000]);
        $this->post($owner, $company, '2025-12-31', 'Löner och arbetsgivaravgifter 2025', [7210 => 95_000_000, 7510 => 29_849_000, 1930 => -124_849_000]);
        $this->post($owner, $company, '2025-12-31', 'Moms 2025 redovisad och betald', [2611 => 97_500_000, 2640 => -32_500_000, 1930 => -65_000_000]);
        $this->post($owner, $company, '2025-12-31', 'Räntor 2025', [8410 => 10_000_000, 1930 => -10_000_000]);
        $this->post($owner, $company, '2025-12-31', 'Amortering 2025', [2350 => 40_000_000, 1930 => -40_000_000]);
        $this->post($owner, $company, '2025-12-31', 'Avskrivning verkstadsbyggnad 2025 per komponent', [7820 => 10_400_000, 1119 => -10_400_000]);
        $this->post($owner, $company, '2025-12-31', 'Avskrivning inventarier 2025', [7832 => 6_000_000, 1229 => -6_000_000]);
        $this->post($owner, $company, '2025-12-31', 'Årets skatt 2025', [8910 => 22_402_706, 2512 => -22_402_706]);
    }

    private function reports(User $owner, FiscalYear $k2, FiscalYear $k3): void
    {
        // The size test reads 2024 from the books and its annual report, 2023 from the figures entered (S1-R9.1).
        PriorYearSizeFigure::create(['start_date' => '2023-01-01', 'end_date' => '2023-12-31', 'average_employees_fte' => '2.00', 'balance_sheet_total_ore' => 550_000_000, 'net_turnover_ore' => 320_000_000, 'entered_by' => $owner->id]);
        AnnualReport::create(['fiscal_year_id' => $k2->id, 'average_employees' => 2, 'average_employees_fte' => '2.00', 'signatories' => [], 'updated_by' => $owner->id]);

        app(SaveAnnualReport::class)($owner, $k3, [
            'business_description' => 'Bolaget äger en verkstadsbyggnad i Västerås och utför reparationer och service av maskiner åt företag i regionen.',
            'significant_events' => 'Bolaget tillämpar K3 från och med räkenskapsåret 2025.',
            'average_employees' => '2',
            'signing_place' => 'Västerås',
            'signing_date' => '2026-03-20',
            'signatories' => "Anna Andersson, Styrelseledamot\nBo Berg, Styrelseledamot",
        ]);
        app(SaveK3ReportInputs::class)($owner, $k3, [
            'average_employees_fte' => '2',
            'k3_answers' => ['group_commitments' => false, 'off_balance_arrangements' => false, 'loans_to_executives' => false, 'parent_company' => false],
            'k3_major_owners' => [['name' => 'Verkstadshuset Holding Demo AB', 'owner_type' => 'legal', 'votes_bp' => 10_000, 'capital_bp' => 10_000]],
            'k3_long_term_liabilities' => '400000',
        ]);
        app(K3NoteOverrides::class)->set($owner, $k3, 'fixed_assets.equipment', 'Inventarier skrivs av linjärt över fem år.', 'Inventarierna finns i bokföringen men inte i anläggningsregistret.');
    }

    /** @param  array<int, int>  $rows  account => signed öre */
    private function post(User $user, Company $company, string $date, string $text, array $rows): void
    {
        app(PostVoucher::class)($user, app(SaveDraftVoucher::class)($user, $company, [
            'voucher_date' => $date,
            'description' => $text,
            'series' => 'A',
            'rows' => collect($rows)->map(fn (int $amount, int $account): array => ['account' => $account, 'debit_ore' => max($amount, 0), 'credit_ore' => max(-$amount, 0)])->values()->all(),
        ]));
    }
}
