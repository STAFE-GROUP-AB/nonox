<?php

declare(strict_types=1);

/*
 * Boxes 06, 07, 08 and 37 of the momsdeklaration (#64, AUD-MOMS-7).
 * Skatteverket, "Fylla i momsdeklarationen" (read 2026-11-02):
 * https://skatteverket.se/foretag/moms/deklareramoms/fyllaimomsdeklarationen.4.3a2a542410ab40a421c80004214.html
 * - 06 Momspliktiga uttag: the value of uttag, excluding VAT.
 * - 07 Vinstmarginalbeskattning: the base the VAT is calculated on.
 * - 08 Hyresinkomster vid frivillig beskattning, excluding VAT.
 * - 37 Mellanmans inköp av varor vid trepartshandel.
 * Accounts from BAS 2026 v1.1: 3401–3403 Egna uttag momspliktiga, 3211
 * Försäljning positiv VMB 25 %, 2616 Utgående moms VMB 25 %, 3913
 * Frivilligt momspliktiga hyresintäkter, 2612/2613. BAS has no account for
 * box 37, so the company ties one of its own to it.
 * Expected values are computed by hand in the comments.
 */

use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Actions\Accounting\UpdateAccount;
use App\Domain\Company\VatPeriod;
use App\Domain\Vat\EskdFile;
use App\Domain\Vat\EuTrade;
use App\Domain\Vat\VatReturn;
use App\Livewire\Accounting\ChartOfAccounts;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-31 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['vat_period' => VatPeriod::Quarterly, 'org_number' => '556334-3689'])->save();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->sole();

    foreach ([
        3401 => ['Egna uttag momspliktiga, 25 %', 'REVENUE'],
        3211 => ['Försäljning positiv VMB 25 %', 'REVENUE'],
        3913 => ['Frivilligt momspliktiga hyresintäkter', 'REVENUE'],
        2012 => ['Avräkning för skatter och avgifter', 'LIABILITY_EQUITY'],
        2612 => ['Utgående moms på egna uttag, 25 %', 'LIABILITY_EQUITY'],
        2613 => ['Utgående moms för uthyrning, 25 %', 'LIABILITY_EQUITY'],
        2616 => ['Utgående moms VMB 25 %', 'LIABILITY_EQUITY'],
        4519 => ['Inköp av varor, mellanman i trepartshandel', 'EXPENSE'],
    ] as $number => [$name, $type]) {
        Account::query()->firstOrCreate(['number' => $number], ['name' => $name, 'type' => $type]);
    }
});

/** @param  list<array<string, mixed>>  $rows */
function specialBoxBook(User $user, Company $company, string $date, array $rows): void
{
    app(PostVoucher::class)($user, app(SaveDraftVoucher::class)($user, $company, ['voucher_date' => $date, 'description' => 'Moms', 'rows' => $rows]));
}

function specialBoxReturn(FiscalYear $year): VatReturn
{
    return VatReturn::calculate($year, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-03-31'));
}

it('reports own withdrawals in box 06, not 05', function () {
    // Goods worth 800 kr taken out by the owner: 3401 800 kr, 2612 200 kr (25 %).
    specialBoxBook($this->owner, $this->company, '2026-01-20', [
        ['account' => 2012, 'debit_ore' => 100_000],
        ['account' => 3401, 'credit_ore' => 80_000],
        ['account' => 2612, 'credit_ore' => 20_000],
    ]);

    $boxes = specialBoxReturn($this->year)->boxes();

    expect($boxes[6])->toBe(800)
        ->and($boxes[5])->toBe(0)
        ->and($boxes[10])->toBe(200)
        ->and($boxes[49])->toBe(200);
});

it('reports the margin base in box 07, derived from the VMB VAT', function () {
    // A used item bought for 6 000 kr, sold for 10 000 kr: margin 4 000 kr incl. VAT,
    // VAT 4 000 × 20 % = 800 kr on 2616, base 4 000 − 800 = 3 200 kr = 800 / 25 %.
    specialBoxBook($this->owner, $this->company, '2026-02-05', [
        ['account' => 1930, 'debit_ore' => 1_000_000],
        ['account' => 3211, 'credit_ore' => 920_000],
        ['account' => 2616, 'credit_ore' => 80_000],
    ]);

    $return = specialBoxReturn($this->year);

    // 3211 is not a sale in box 05; the VAT is in box 10.
    expect($return->boxes()[7])->toBe(3_200)
        ->and($return->boxes()[5])->toBe(0)
        ->and($return->boxes()[10])->toBe(800)
        ->and($return->sources[7])->toBe([['number' => 2616, 'name' => 'Utgående moms VMB 25 %', 'amount' => 320_000]])
        ->and($return->unexplained)->toBe(0);
});

it('reports rent under voluntary taxation in box 08', function () {
    // Rent for a commercial premises 20 000 kr + 25 % = 5 000 kr.
    specialBoxBook($this->owner, $this->company, '2026-03-01', [
        ['account' => 1510, 'debit_ore' => 2_500_000],
        ['account' => 3913, 'credit_ore' => 2_000_000],
        ['account' => 2613, 'credit_ore' => 500_000],
    ]);

    $boxes = specialBoxReturn($this->year)->boxes();

    expect($boxes[8])->toBe(20_000)
        ->and($boxes[5])->toBe(0)
        ->and($boxes[10])->toBe(5_000);
});

it('reports a middleman\'s purchase in triangular trade in box 37 through an account tied to it', function () {
    app(UpdateAccount::class)($this->owner, Account::query()->where('number', 4519)->sole(), ['vat_box' => 37]);

    // Goods bought for 5 000 kr from a German seller and delivered straight to a buyer in France: no VAT.
    specialBoxBook($this->owner, $this->company, '2026-02-10', [
        ['account' => 4519, 'debit_ore' => 500_000],
        ['account' => 2440, 'credit_ore' => 500_000],
    ]);

    $return = specialBoxReturn($this->year);

    expect($return->boxes()[37])->toBe(5_000)
        ->and($return->boxes()[20])->toBe(0)
        ->and($return->boxes()[49])->toBe(0)
        ->and(EskdFile::render($this->company, $return))->toContain('<InkopVaruMellan3p>5000</InkopVaruMellan3p>')
        ->and(EuTrade::years([$this->company->id]))->toBe(["{$this->company->id}:2026-01-01"]);
});

it('writes boxes 06, 07 and 08 to the file for Skatteverket in the DTD order', function () {
    specialBoxBook($this->owner, $this->company, '2026-01-20', [
        ['account' => 2012, 'debit_ore' => 100_000],
        ['account' => 3401, 'credit_ore' => 80_000],
        ['account' => 2612, 'credit_ore' => 20_000],
    ]);
    specialBoxBook($this->owner, $this->company, '2026-02-05', [
        ['account' => 1930, 'debit_ore' => 1_000_000],
        ['account' => 3211, 'credit_ore' => 920_000],
        ['account' => 2616, 'credit_ore' => 80_000],
    ]);
    specialBoxBook($this->owner, $this->company, '2026-03-01', [
        ['account' => 1510, 'debit_ore' => 2_500_000],
        ['account' => 3913, 'credit_ore' => 2_000_000],
        ['account' => 2613, 'credit_ore' => 500_000],
    ]);

    $xml = EskdFile::render($this->company, specialBoxReturn($this->year));

    // Box 10: 200 + 800 + 5 000 = 6 000 kr.
    expect($xml)->toContain("<UttagMoms>800</UttagMoms>\n    <UlagMargbesk>3200</UlagMargbesk>\n    <HyrinkomstFriv>20000</HyrinkomstFriv>")
        ->and($xml)->toContain('<MomsUtgHog>6000</MomsUtgHog>');
});

it('ties only revenue and cost accounts to a base box', function () {
    $update = app(UpdateAccount::class);

    expect(fn () => $update($this->owner, Account::query()->where('number', 4519)->sole(), ['vat_box' => 10]))->toThrow(ValidationException::class)
        ->and(fn () => $update($this->owner, Account::query()->where('number', 2611)->sole(), ['vat_box' => 5]))->toThrow(ValidationException::class);

    $account = Account::query()->where('number', 4519)->sole();
    $update($this->owner, $account, ['vat_box' => 37]);
    $update($this->owner, $account->refresh(), ['vat_box' => null]);

    $log = AuditLog::query()->where('action', 'account.updated')->orderBy('id')->get();

    expect($account->refresh()->vat_box)->toBeNull()
        ->and($log)->toHaveCount(2)
        ->and($log[0]->payload['after'])->toBe(['vat_box' => 37]);
});

it('lets the chart of accounts tie an account to a box with a toast', function () {
    $account = Account::query()->where('number', 4519)->sole();

    Livewire::test(ChartOfAccounts::class)
        ->call('startEditingBox', $account->id)
        ->set('editingBox', '37')
        ->call('saveBox')
        ->assertDispatched('toast', message: 'Konto 4519 redovisas i momsdeklarationens ruta 37.')
        ->call('startEditingBox', $account->id)
        ->set('editingBox', '')
        ->call('saveBox')
        ->assertDispatched('toast', message: 'Konto 4519 hör inte till någon momsruta.');

    expect($account->refresh()->vat_box)->toBeNull();
});

it('lets an auditor see but not change the box of an account', function () {
    $this->actingAs($auditor = Bookkeeping::member($this->company, 'auditor'));

    expect(fn () => app(UpdateAccount::class)($auditor, Account::query()->where('number', 4519)->sole(), ['vat_box' => 37]))
        ->toThrow(AuthorizationException::class);
});
