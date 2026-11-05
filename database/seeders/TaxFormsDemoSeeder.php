<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Actions\Company\CreateCompany;
use App\Actions\IncomeTax\BusinessIncomeTaxReturns;
use App\Domain\Accounting\AccountType;
use App\Models\Account;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Models\Voucher;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Seeder;

/**
 * Two synthetic demo companies for the screenshots of the NE-bilaga and
 * Inkomstdeklaration 4 (ADR-053): a sole trader and a handelsbolag with a
 * year of vouchers, owned by the same made-up owner as IndustryDemoSeeder.
 * Every name is invented; the personnummer are Skatteverket's well-known test
 * numbers (811218-9876, 121212-1212), never a real person's.
 *
 * Run with `php artisan db:seed --class=TaxFormsDemoSeeder`, in a demo
 * database only. It adds what is missing and changes nothing else.
 */
class TaxFormsDemoSeeder extends Seeder
{
    public const string SOLE_TRADER = '811218-9876';

    public const string PARTNERSHIP = '969999-0017';

    public function run(CreateCompany $createCompany): void
    {
        $owner = User::query()->firstOrCreate(['email' => IndustryDemoSeeder::OWNER['email']], ['name' => IndustryDemoSeeder::OWNER['name'], 'email_verified_at' => now()]);

        $soleTrader = $this->company($createCompany, $owner, self::SOLE_TRADER, ['name' => 'Ekens Snickeri Demo', 'legal_form' => 'EF', 'vat_period' => 'YEARLY', 'reporting_framework' => 'K1', 'postal_code' => '222 33', 'city' => 'Lund']);
        CurrentCompany::run($soleTrader, function () use ($owner, $soleTrader): void {
            if (Voucher::query()->exists()) {
                return;
            }

            foreach (range(1, 12) as $month) {
                $this->post($owner, $soleTrader, sprintf('2026-%02d-15', $month), "Fakturor snickeri {$month}/2026", [1930 => 4_375_000 + $month * 50_000, 3001 => -(3_500_000 + $month * 40_000), 2611 => -(875_000 + $month * 10_000)]);
                $this->post($owner, $soleTrader, sprintf('2026-%02d-20', $month), "Virke och material {$month}/2026", [4010 => 1_100_000, 2641 => 275_000, 1930 => -1_375_000]);
                $this->post($owner, $soleTrader, sprintf('2026-%02d-25', $month), "Verkstadshyra {$month}/2026", [5010 => 600_000, 1930 => -600_000]);
            }

            $this->post($owner, $soleTrader, '2026-03-05', 'Bandsåg', [1220 => 4_800_000, 2641 => 1_200_000, 1930 => -6_000_000]);
            $this->post($owner, $soleTrader, '2026-06-30', 'Ränta sparkonto', [1930 => 120_000, 8310 => -120_000]);
            $this->post($owner, $soleTrader, '2026-12-31', 'Avskrivning bandsåg', [7832 => 960_000, 1229 => -960_000]);

            app(BusinessIncomeTaxReturns::class)->saveNe($owner, $this->year(), ['7709' => '40 000', '7610' => '32 000', '7713' => '30 000'], ['activity' => 'Snickeri och möbeltillverkning', 'capital_base' => '150 000'], '19', false);
        });

        $partnership = $this->company($createCompany, $owner, self::PARTNERSHIP, ['name' => 'Björk & Ek Bygg Demo HB', 'legal_form' => 'HB', 'vat_period' => 'QUARTERLY', 'reporting_framework' => 'K2', 'postal_code' => '111 22', 'city' => 'Stockholm']);
        CurrentCompany::run($partnership, function () use ($owner, $partnership): void {
            if (Voucher::query()->exists()) {
                return;
            }

            foreach ([2010 => 'Eget kapital, delägare 1', 2013 => 'Övriga egna uttag, delägare 1', 2019 => 'Årets resultat, delägare 1', 2020 => 'Eget kapital, delägare 2', 2023 => 'Övriga egna uttag, delägare 2', 2028 => 'Övriga egna insättningar, delägare 2', 2029 => 'Årets resultat, delägare 2'] as $number => $name) {
                Account::query()->firstOrCreate(['number' => $number], ['name' => $name, 'type' => AccountType::LiabilityEquity, 'is_active' => true]);
            }

            foreach (range(1, 12) as $month) {
                $this->post($owner, $partnership, sprintf('2026-%02d-15', $month), "Byggtjänster {$month}/2026", [1930 => 9_375_000, 3001 => -7_500_000, 2611 => -1_875_000]);
                $this->post($owner, $partnership, sprintf('2026-%02d-20', $month), "Material {$month}/2026", [4010 => 2_400_000, 2641 => 600_000, 1930 => -3_000_000]);
                $this->post($owner, $partnership, sprintf('2026-%02d-25', $month), "Hyra och försäkring {$month}/2026", [5010 => 900_000, 1930 => -900_000]);
            }

            $this->post($owner, $partnership, '2026-06-30', 'Uttag delägare 1', [2013 => 12_000_000, 1930 => -12_000_000]);
            $this->post($owner, $partnership, '2026-06-30', 'Uttag delägare 2', [2023 => 9_000_000, 1930 => -9_000_000]);
            $this->post($owner, $partnership, '2026-02-01', 'Insättning delägare 2', [1930 => 5_000_000, 2028 => -5_000_000]);

            // Skatteverket's test personnummer and an invented organisationsnummer, never a real person's.
            app(BusinessIncomeTaxReturns::class)->savePartners($owner, $this->year(), [
                ['position' => '1', 'name' => 'Anna Andersson', 'identity' => '19121212-1212', 'share' => '50'],
                ['position' => '2', 'name' => 'Ek Bygg Demo AB', 'identity' => '556677-8899', 'legal_person' => true, 'share' => '50'],
            ]);
        });
    }

    /** @param  array<string, string>  $details */
    private function company(CreateCompany $createCompany, User $owner, string $orgNumber, array $details): Company
    {
        $existing = CompanyScope::bypass(fn (): ?Company => Company::query()->where('org_number', $orgNumber)->first());

        return $existing ?? $createCompany->create($owner, [
            ...$details,
            'org_number' => $orgNumber,
            'address_line1' => 'Exempelvägen 2',
            'fiscal_year_start_month' => 1,
            'accounting_method' => 'FAKTURAMETODEN',
            'vat_registered' => true,
            'framework_confirmed' => true,
            'f_skatt' => true,
            'employer_registered' => false,
        ]);
    }

    private function year(): FiscalYear
    {
        return FiscalYear::query()->whereDate('start_date', '2026-01-01')->firstOrFail();
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
