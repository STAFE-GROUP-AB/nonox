<?php

declare(strict_types=1);

use App\Actions\Accounting\InitialiseBookkeeping;
use App\Actions\Accounting\UpgradeBasChart;
use App\Domain\Accounting\AccountType;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\File;

/*
 * The yearly BAS routine (queue item 29). Synthetic template year 2098 so
 * nothing depends on a real future BAS version.
 */

function basFolder(): string
{
    return sys_get_temp_dir().'/nonox-bas-'.getmypid();
}

beforeEach(function () {
    File::deleteDirectory(basFolder());
    File::ensureDirectoryExists(basFolder().'/2026');
    File::ensureDirectoryExists(basFolder().'/2098');
    config(['nonox.bas_path' => basFolder()]);

    File::copy(database_path('data/bas/2026/previous-names.json'), basFolder().'/2026/previous-names.json');

    foreach (['k1', 'k2', 'k3'] as $framework) {
        File::copy(database_path("data/bas/2026/{$framework}.json"), basFolder()."/2026/{$framework}.json");

        $data = json_decode((string) file_get_contents(database_path("data/bas/2026/{$framework}.json")), true);
        $data['version'] = 'BAS 2098';

        foreach ($data['accounts'] as &$account) {
            if ($account['number'] === 1930) {
                $account['name'] = 'Bankkonto (ny BAS)';
            }

            if ($account['number'] === 3001) {
                $account['name'] = 'Försäljning Sverige (ny BAS)';
            }
        }

        unset($account);
        $data['accounts'][] = ['number' => 1931, 'name' => 'Nytt konto i BAS 2098'];
        file_put_contents(basFolder()."/2098/{$framework}.json", json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    $this->owner = User::factory()->withCompany()->create();
    $this->company = $this->owner->currentCompany();
    app(InitialiseBookkeeping::class)($this->company);
});

afterEach(fn () => File::deleteDirectory(basFolder()));

function accountName(Company $company, int $number): ?string
{
    return CurrentCompany::run($company, fn () => Account::query()->where('number', $number)->value('name'));
}

it('renames an account only where it still has the previous template name', function () {
    CurrentCompany::run($this->company, fn () => Account::query()->where('number', 3001)->update(['name' => 'Min egen försäljning']));
    $accounts = CurrentCompany::run($this->company, fn () => Account::query()->count());

    $result = app(UpgradeBasChart::class)($this->company, '2098');

    expect($result)->toBe(['renamed' => 1, 'kept' => 1, 'skipped' => null])
        ->and(accountName($this->company, 1930))->toBe('Bankkonto (ny BAS)')
        ->and(accountName($this->company, 3001))->toBe('Min egen försäljning')
        ->and(accountName($this->company, 1931))->toBeNull()
        ->and(CurrentCompany::run($this->company, fn () => Account::query()->count()))->toBe($accounts)
        ->and($this->company->fresh()->bas_version)->toBe('BAS 2098');

    $log = AuditLog::forCompany($this->company)->whereIn('action', ['account.updated', 'company.bas_upgraded'])->orderBy('id')->get();

    expect($log->pluck('action')->all())->toContain('company.bas_upgraded')
        ->and($log->firstWhere('action', 'account.updated')->payload['before'])->toBe(['name' => 'Företagskonto'])
        ->and($log->firstWhere('action', 'account.updated')->payload['after'])->toBe(['name' => 'Bankkonto (ny BAS)']);
});

it('changes nothing in a dry run and is safe to run twice', function () {
    $this->artisan('bas:upgrade', ['version' => '2098', '--dry-run' => true])->assertSuccessful();

    expect(accountName($this->company, 1930))->toBe('Företagskonto')
        ->and($this->company->fresh()->bas_version)->toBe('BAS 2026');

    $this->artisan('bas:upgrade', ['version' => '2098'])->assertSuccessful();
    $this->artisan('bas:upgrade', ['version' => 'BAS 2098'])->assertSuccessful();

    expect(accountName($this->company, 1930))->toBe('Bankkonto (ny BAS)')
        ->and(AuditLog::forCompany($this->company)->where('action', 'company.bas_upgraded')->count())->toBe(1);
});

it('prints counts per company without names', function () {
    $this->artisan('bas:upgrade', ['version' => '2098', '--dry-run' => true])
        ->expectsOutputToContain("Företag {$this->company->id}: 2 namn byts")
        ->doesntExpectOutputToContain('Företagskonto')
        ->assertSuccessful();
});

it('refuses a version without a template folder', function () {
    $this->artisan('bas:upgrade', ['version' => '2097'])->assertFailed();
    $this->artisan('bas:upgrade', ['version' => 'abc'])->assertFailed();
});

it('never touches another company or an archived one', function () {
    $other = User::factory()->withCompany()->create();
    $otherCompany = $other->currentCompany();
    app(InitialiseBookkeeping::class)($otherCompany);
    CurrentCompany::run($otherCompany, fn () => Account::query()->where('number', 1930)->update(['name' => 'Annat namn']));

    $archived = User::factory()->withCompany()->create()->currentCompany();
    app(InitialiseBookkeeping::class)($archived);
    $archived->forceFill(['archived_at' => now()])->save();

    app(UpgradeBasChart::class)($this->company, '2098');
    $this->artisan('bas:upgrade', ['version' => '2098'])->assertSuccessful();

    expect(accountName($otherCompany, 1930))->toBe('Annat namn')
        ->and($otherCompany->fresh()->bas_version)->toBe('BAS 2098')
        ->and(accountName($archived, 1930))->toBe('Företagskonto')
        ->and($archived->fresh()->bas_version)->toBe('BAS 2026')
        ->and(accountName($otherCompany, 3001))->toBe('Försäljning Sverige (ny BAS)')
        ->and(AuditLog::forCompany($otherCompany)->where('action', 'account.updated')->count())->toBe(1)
        ->and(AuditLog::forCompany($this->company)->where('action', 'account.updated')->count())->toBeGreaterThan(0);
});

it('states on the chart-of-accounts page which BAS it builds on', function () {
    $this->actingAs($this->owner->fresh());

    $this->get(route('accounting.accounts'))
        ->assertOk()
        ->assertSee('Kontoplanen bygger på BAS kontoplan 2026 från BAS-kontogruppen, bas.se.');
});

function renameAccounts(Company $company, array $names): void
{
    CurrentCompany::run($company, function () use ($names) {
        foreach ($names as $number => $name) {
            Account::query()->where('number', $number)->update(['name' => $name]);
        }
    });
}

it('corrects names seeded before the official-list check, and leaves a user rename alone', function () {
    renameAccounts($this->company, [1930 => 'Företagskonto/checkkonto/affärskonto', 4010 => 'Inköp material och varor', 6250 => 'Mitt eget porto']);

    $this->artisan('bas:upgrade', ['version' => '2026', '--fix-names' => true, '--dry-run' => true])
        ->expectsOutputToContain("Företag {$this->company->id}: 2 namn byts, 1 egna namn lämnas")
        ->assertSuccessful();

    expect(accountName($this->company, 1930))->toBe('Företagskonto/checkkonto/affärskonto');

    $this->artisan('bas:upgrade', ['version' => '2026', '--fix-names' => true])->assertSuccessful();

    expect(accountName($this->company, 1930))->toBe('Företagskonto')
        ->and(accountName($this->company, 4010))->toBe('Inköp av handelsvaror i Sverige')
        ->and(accountName($this->company, 6250))->toBe('Mitt eget porto')
        ->and($this->company->fresh()->bas_version)->toBe('BAS 2026')
        ->and(AuditLog::forCompany($this->company)->where('action', 'account.updated')->count())->toBe(2)
        ->and(AuditLog::forCompany($this->company)->where('action', 'company.bas_upgraded')->count())->toBe(0);

    $this->artisan('bas:upgrade', ['version' => '2026', '--fix-names' => true])->assertSuccessful();

    expect(AuditLog::forCompany($this->company)->where('action', 'account.updated')->count())->toBe(2);
});

it('does not correct names for a company that is on another version', function () {
    renameAccounts($this->company, [1930 => 'Företagskonto/checkkonto/affärskonto']);

    $result = app(UpgradeBasChart::class)($this->company, '2098', false, true);

    expect($result['skipped'])->toBe('not on BAS 2098')
        ->and(accountName($this->company, 1930))->toBe('Företagskonto/checkkonto/affärskonto');
});

it('still treats a pre-correction name as the template name when moving to the next year', function () {
    renameAccounts($this->company, [1930 => 'Företagskonto/checkkonto/affärskonto', 6250 => 'Mitt eget porto']);

    $result = app(UpgradeBasChart::class)($this->company, '2098');

    expect($result['renamed'])->toBe(2)
        ->and(accountName($this->company, 1930))->toBe('Bankkonto (ny BAS)')
        ->and(accountName($this->company, 6250))->toBe('Mitt eget porto');
});

it('also corrects an account NONOX created itself that is not in the templates', function () {
    CurrentCompany::run($this->company, fn () => Account::create(['number' => 7519, 'name' => 'Sociala avgifter för semester- och löneskulder', 'type' => AccountType::forNumber(7519)]));

    $this->artisan('bas:upgrade', ['version' => '2026', '--fix-names' => true])->assertSuccessful();

    expect(accountName($this->company, 7519))->toBe('Arbetsgivaravgifter för semester- och löneskulder');
});
