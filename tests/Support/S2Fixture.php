<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Accounting\InitialiseBookkeeping;
use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Actions\Group\ConfirmGroupMember;
use App\Actions\Group\CreateCorporateGroup;
use App\Actions\Group\RecordHolding;
use App\Domain\Accounting\AccountType;
use App\Domain\Group\ControlBasis;
use App\Models\Account;
use App\Models\Company;
use App\Models\CorporateGroup;
use App\Models\FiscalYear;
use App\Models\OpeningBalance;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * Loads an S2 fixture (tests/Fixtures/s2/f-s2-*) into new companies inside
 * the test's transaction: one company per fixture company with its fiscal
 * year, the accounts it uses, its opening balances and every voucher,
 * posted through PostVoucher; then the parent's holdings and the shared
 * group with every member confirmed, as a person who administers them all
 * would. Fixture files are read, never written.
 */
final class S2Fixture
{
    /** @var array<string, mixed> */
    public array $expected;

    /** @var array<string, Company> by company key */
    public array $companies = [];

    public CorporateGroup $group;

    private function __construct(public readonly string $name, public readonly User $admin)
    {
        $this->expected = self::json($name, 'expected.json');
    }

    public static function load(string $name): self
    {
        $fixture = new self($name, User::factory()->create(['name' => 'Koncernadministratör']));
        $group = self::json($name, 'group.json');

        foreach ($group['members'] as $member) {
            $fixture->companies[$member['company']] = $fixture->company($member['company']);
        }

        $admin = $fixture->admin->fresh() ?? throw new LogicException('The administrator is gone.');
        $parent = $fixture->companies[$group['parent']];

        foreach ($group['members'] as $member) {
            if ($member['role'] !== 'subsidiary') {
                continue;
            }

            $held = $fixture->companies[$member['company']];
            app(RecordHolding::class)($admin, $parent, [
                'held_org_number' => $held->org_number,
                'held_name' => $held->name,
                'capital' => (string) ($member['ownership_bp'] / 100),
                'voting' => (string) ($member['voting_bp'] / 100),
                'control_basis' => ControlBasis::Votes->value,
                'acquired_on' => $member['acquired_at'],
            ]);
        }

        $fixture->group = app(CreateCorporateGroup::class)($admin->refresh(), $parent, (string) $group['name']);

        foreach ($group['members'] as $member) {
            if ($member['role'] === 'subsidiary') {
                app(ConfirmGroupMember::class)($admin->refresh(), $fixture->group->id, $fixture->companies[$member['company']]);
            }
        }

        return $fixture;
    }

    private function company(string $key): Company
    {
        $data = self::json($this->name, "companies/{$key}/company.json");
        $owner = User::factory()->withCompany()->create();
        $company = $owner->currentCompany() ?? throw new LogicException('The owner has no company.');
        $company->forceFill([
            'legal_name' => $data['legal_name'],
            'name' => $data['legal_name'],
            'org_number' => $data['org_number'],
            'legal_form' => $data['legal_form'],
            'reporting_framework' => $data['reporting_framework'],
        ])->save();

        app(InitialiseBookkeeping::class)($company, $owner, CarbonImmutable::parse($data['fiscal_year']['start_date']));
        $company->users()->attach($this->admin, ['role' => 'admin']);
        $owner = $owner->fresh() ?? $owner;

        CurrentCompany::run($company, function () use ($company, $owner, $data, $key): void {
            $year = FiscalYear::query()->whereDate('start_date', $data['fiscal_year']['start_date'])->firstOrFail();
            $existing = Account::query()->pluck('id', 'number')->all();

            foreach ($data['chart_of_accounts'] as $account) {
                if (! isset($existing[$account['number']])) {
                    Account::create(['number' => $account['number'], 'name' => $account['name'], 'type' => AccountType::forNumber((int) $account['number']), 'is_active' => true]);
                }
            }

            $accounts = Account::query()->pluck('id', 'number')->all();

            foreach (self::json($this->name, "companies/{$key}/opening_balances.json")[$year->start_date->toDateString()] ?? [] as $balance) {
                OpeningBalance::create(['fiscal_year_id' => $year->id, 'account_id' => $accounts[$balance['account']], 'amount_ore' => $balance['balance_ore']]);
            }

            foreach (self::json($this->name, "companies/{$key}/vouchers.json")['vouchers'] as $voucher) {
                $draft = app(SaveDraftVoucher::class)($owner, $company, [
                    'voucher_date' => $voucher['date'],
                    'description' => $voucher['description'],
                    'series' => $voucher['series'],
                    'rows' => array_map(fn (array $row): array => ['account' => $row['account'], 'debit_ore' => $row['debit_ore'], 'credit_ore' => $row['credit_ore']], $voucher['rows']),
                ]);
                app(PostVoucher::class)($owner, $draft);
            }
        });

        return $company->refresh();
    }

    /** @return array<string, mixed> */
    private static function json(string $name, string $file): array
    {
        return json_decode((string) file_get_contents(base_path("tests/Fixtures/s2/{$name}/{$file}")), true, flags: JSON_THROW_ON_ERROR);
    }
}
