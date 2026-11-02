<?php

declare(strict_types=1);

/*
 * Box 05 holds every taxable sale in Sweden excluding VAT, whatever revenue
 * account it is booked on (#61, AUD-MOMS-3). Skatteverket, "Fylla i
 * momsdeklarationen", fält 05 (read 2026-11-02):
 * https://skatteverket.se/foretag/moms/deklareramoms/fyllaimomsdeklarationen.4.3a2a542410ab40a421c80004214.html
 * Every expected value is computed by hand in the comment beside it.
 */

use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Domain\Company\VatPeriod;
use App\Domain\Vat\VatReturn;
use App\Livewire\Vat\VatReturns;
use App\Models\Account;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-31 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['vat_period' => VatPeriod::Quarterly])->save();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->sole();

    foreach ([3590 => 'Övriga fakturerade kostnader', 3914 => 'Övriga momspliktiga hyresintäkter', 3973 => 'Vinst vid avyttring av maskiner och inventarier', 3985 => 'Erhållna statliga bidrag'] as $number => $name) {
        Account::query()->firstOrCreate(['number' => $number], ['name' => $name, 'type' => 'REVENUE']);
    }
});

/** @param  list<array<string, mixed>>  $rows */
function salesBaseBook(User $user, Company $company, string $date, array $rows): void
{
    app(PostVoucher::class)($user, app(SaveDraftVoucher::class)($user, $company, ['voucher_date' => $date, 'description' => 'Försäljning', 'rows' => $rows]));
}

function salesBaseReturn(FiscalYear $year): VatReturn
{
    return VatReturn::calculate($year, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-03-31'));
}

it('counts a 12 % sale on a fakturerade kostnader account in box 05', function () {
    // 3590 400 kr + 12 % = 48 kr on 2621; base 48 / 0.12 = 400 kr.
    salesBaseBook($this->owner, $this->company, '2026-02-01', [
        ['account' => 1510, 'debit_ore' => 44_800],
        ['account' => 3590, 'credit_ore' => 40_000],
        ['account' => 2621, 'credit_ore' => 4_800],
    ]);

    $return = salesBaseReturn($this->year);

    expect($return->boxes()[5])->toBe(400)
        ->and($return->boxes()[11])->toBe(48)
        ->and($return->sources[5])->toBe([['number' => 3590, 'name' => 'Övriga fakturerade kostnader', 'amount' => 40_000]])
        ->and($return->unexplained)->toBe(0);
});

it('counts the other account only for the base the VAT leaves after the sales accounts', function () {
    // 3001 1 000 kr at 25 % and 3985 500 kr grant without VAT: VAT 250 kr, base 1 000 kr, all already on 3001.
    salesBaseBook($this->owner, $this->company, '2026-02-02', [
        ['account' => 1930, 'debit_ore' => 175_000],
        ['account' => 3001, 'credit_ore' => 100_000],
        ['account' => 3985, 'credit_ore' => 50_000],
        ['account' => 2611, 'credit_ore' => 25_000],
    ]);
    // 3001 1 000 kr and 3990 200 kr, both at 25 %: VAT 300 kr, base 1 200 kr, 200 kr of it on 3990.
    salesBaseBook($this->owner, $this->company, '2026-02-03', [
        ['account' => 1930, 'debit_ore' => 150_000],
        ['account' => 3001, 'credit_ore' => 100_000],
        ['account' => 3990, 'credit_ore' => 20_000],
        ['account' => 2611, 'credit_ore' => 30_000],
    ]);

    $return = salesBaseReturn($this->year);

    // 1 000 + 1 000 + 200 = 2 200 kr; the grant is not a sale.
    expect($return->boxes()[5])->toBe(2_200)
        ->and($return->boxes()[10])->toBe(550)
        ->and(array_column($return->sources[5], 'amount', 'number'))->toBe([3001 => 200_000, 3990 => 20_000]);
});

it('reverses the base with a credit', function () {
    // Sale 800 kr on 3990 at 25 % (200 kr), then a credit of 300 kr (75 kr): 500 kr in box 05, 125 kr in box 10.
    salesBaseBook($this->owner, $this->company, '2026-01-10', [
        ['account' => 1510, 'debit_ore' => 100_000],
        ['account' => 3990, 'credit_ore' => 80_000],
        ['account' => 2611, 'credit_ore' => 20_000],
    ]);
    salesBaseBook($this->owner, $this->company, '2026-01-20', [
        ['account' => 3990, 'debit_ore' => 30_000],
        ['account' => 2611, 'debit_ore' => 7_500],
        ['account' => 1510, 'credit_ore' => 37_500],
    ]);

    $boxes = salesBaseReturn($this->year)->boxes();

    expect($boxes[5])->toBe(500)
        ->and($boxes[10])->toBe(125);
});

it('reads 3914 Övriga momspliktiga hyresintäkter as box 05', function () {
    // BAS 2026: 3914 is taxable letting that is not voluntary (fält 08 is only frivillig beskattning).
    salesBaseBook($this->owner, $this->company, '2026-03-01', [
        ['account' => 1930, 'debit_ore' => 62_500],
        ['account' => 3914, 'credit_ore' => 50_000],
        ['account' => 2611, 'credit_ore' => 12_500],
    ]);

    expect(salesBaseReturn($this->year)->boxes()[5])->toBe(500);
});

it('shows output VAT whose base is on no revenue account', function () {
    // A machine sold for 10 000 kr + 2 500 kr VAT: book value 8 000 kr off 1220, gain 2 000 kr on 3973.
    salesBaseBook($this->owner, $this->company, '2026-03-02', [
        ['account' => 1930, 'debit_ore' => 1_250_000],
        ['account' => 1220, 'credit_ore' => 800_000],
        ['account' => 3973, 'credit_ore' => 200_000],
        ['account' => 2611, 'credit_ore' => 250_000],
    ]);

    $return = salesBaseReturn($this->year);

    // The gain is not the price, so nothing reaches box 05; the 10 000 kr base is shown as missing.
    expect($return->boxes()[5])->toBe(0)
        ->and($return->boxes()[10])->toBe(2_500)
        ->and($return->unexplained)->toBe(1_000_000);

    Livewire::test(VatReturns::class)->set('period', '2026-01-01')
        ->assertSee('Utgående moms utan försäljning i ruta 05')
        ->assertSee('10 000 kr mer i underlag');
});

it('accepts a VAT rounded to whole kronor as rounding, not a missing base', function () {
    // 3990 99,60 kr, VAT rounded to 25 kr: implied base 100 kr, 40 öre more than booked; within 2 kr (0,50 kr / 25 %).
    salesBaseBook($this->owner, $this->company, '2026-03-03', [
        ['account' => 1930, 'debit_ore' => 12_460],
        ['account' => 3990, 'credit_ore' => 9_960],
        ['account' => 2611, 'credit_ore' => 2_500],
    ]);

    $return = salesBaseReturn($this->year);

    expect($return->exact[5])->toBe(9_960)
        ->and($return->unexplained)->toBe(0);
});
