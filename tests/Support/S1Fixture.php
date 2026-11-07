<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Accounting\InitialiseBookkeeping;
use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Domain\Accounting\AccountType;
use App\Models\Account;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\OpeningBalance;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * Loads an S1 fixture (tests/Fixtures/s1/f-s1-*) into a new company inside
 * the test's transaction (ADR-059 A.5): the company, its fiscal years with
 * the fixture's framework, the accounts it uses, the opening balances of
 * every year and every voucher, posted through PostVoucher so the ledger
 * chain holds. It relies on the test's DatabaseTransactions and never
 * rebuilds the schema; fixture files are read, never written.
 */
final class S1Fixture
{
    /** @var array<string, mixed> */
    public array $expected;

    /** @var array<int, FiscalYear> by the year of the start date */
    public array $years = [];

    private function __construct(
        public readonly string $name,
        public readonly User $owner,
        public readonly Company $company,
    ) {
        $this->expected = self::json($name, 'expected.json');
    }

    /**
     * @param  callable(array<string, mixed>): bool|null  $keep  which vouchers to post (all by default)
     */
    public static function load(string $name, ?callable $keep = null): self
    {
        $companyData = self::json($name, 'company.json');
        $owner = User::factory()->withCompany()->create();
        $company = $owner->currentCompany() ?? throw new LogicException('The owner has no company.');
        $company->forceFill([
            'legal_name' => $companyData['legal_name'],
            'name' => $companyData['legal_name'],
            'legal_form' => $companyData['legal_form'],
            'reporting_framework' => $companyData['reporting_framework'],
        ])->save();

        $years = $companyData['fiscal_years'];
        app(InitialiseBookkeeping::class)($company, $owner, CarbonImmutable::parse($years[0]['start_date']));
        $fixture = new self($name, $owner->refresh(), $company->refresh());

        CurrentCompany::run($fixture->company, function () use ($fixture, $companyData, $years, $keep): void {
            foreach ($years as $year) {
                $fiscalYear = FiscalYear::query()->whereDate('start_date', $year['start_date'])->first()
                    ?? FiscalYear::create(['start_date' => $year['start_date'], 'end_date' => $year['end_date']]);
                $fiscalYear->forceFill(['reporting_framework' => $year['framework']])->save();
                $fixture->years[(int) substr($year['start_date'], 0, 4)] = $fiscalYear->refresh();
            }

            $existing = Account::query()->pluck('id', 'number')->all();

            foreach ($companyData['chart_of_accounts'] as $account) {
                if (! isset($existing[$account['number']])) {
                    Account::create(['number' => $account['number'], 'name' => $account['name'], 'type' => AccountType::forNumber((int) $account['number']), 'is_active' => true]);
                }
            }

            $accounts = Account::query()->pluck('id', 'number')->all();
            $balances = self::json($fixture->name, 'opening_balances.json');

            foreach ($fixture->years as $fiscalYear) {
                foreach ($balances[$fiscalYear->start_date->toDateString()] ?? [] as $balance) {
                    OpeningBalance::create(['fiscal_year_id' => $fiscalYear->id, 'account_id' => $accounts[$balance['account']], 'amount_ore' => $balance['balance_ore']]);
                }
            }

            foreach (self::json($fixture->name, 'vouchers.json')['vouchers'] as $voucher) {
                if ($keep !== null && ! $keep($voucher)) {
                    continue;
                }

                $draft = app(SaveDraftVoucher::class)($fixture->owner, $fixture->company, [
                    'voucher_date' => $voucher['date'],
                    'description' => $voucher['description'],
                    'series' => $voucher['series'],
                    'rows' => array_map(fn (array $row): array => ['account' => $row['account'], 'debit_ore' => $row['debit_ore'], 'credit_ore' => $row['credit_ore']], $voucher['rows']),
                ]);
                app(PostVoucher::class)($fixture->owner, $draft);
            }
        });

        return $fixture;
    }

    /** @return array<string, mixed> */
    private static function json(string $name, string $file): array
    {
        return json_decode((string) file_get_contents(base_path("tests/Fixtures/s1/{$name}/{$file}")), true, flags: JSON_THROW_ON_ERROR);
    }
}
