<?php

declare(strict_types=1);

/*
 * #89 (BS-AUD-BOK-9): BAS 4000–4999 split over INK2R 3.2 (7411/7510), 3.5
 * (7511) and 3.6 (7512), and over the K2 income statement lines Förändring
 * av lager av produkter i arbete, färdiga varor och pågående arbete för
 * annans räkning, Råvaror och förnödenheter and Handelsvaror.
 * Sources: SKV field table INK2R_SKV2002-34-01-26-04 (read 2026-11-02);
 * BFNAR 2016:10 uppställningsform resultaträkning; ÅRL bilaga 2.
 * Expected values computed by hand.
 */

use App\Actions\Accounting\UpdateAccount;
use App\Domain\Accounting\AccountType;
use App\Domain\AnnualReport\AnnualAccounts;
use App\Domain\Company\VatPeriod;
use App\Domain\IncomeTax\Ink2;
use App\Livewire\Accounting\ChartOfAccounts;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\FiscalYear;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-03-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['vat_period' => VatPeriod::None, 'org_number' => '556000-0001'])->save();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->firstOrFail();

    foreach ([1450, 1460, 4010, 4950, 4960, 4910, 1410] as $number) {
        Account::query()->firstOrCreate(['number' => $number], ['name' => "Konto {$number}", 'type' => AccountType::forNumber($number)]);
    }

    // Sales 100 000 and purchases 30 000 on 4010.
    Bookkeeping::post($this->owner, $this->company, 10_000_000, 1930, 3001, '2026-03-01');
    Bookkeeping::post($this->owner, $this->company, 3_000_000, 4010, 1930, '2026-04-01');
});

function stockLine(string $key): ?int
{
    return collect(AnnualAccounts::for(test()->year)->incomeStatement())->firstWhere('key', $key)['amount'] ?? null;
}

it('reports an increase in finished goods on INK2R 3.2 + (7411) and as operating income in K2', function () {
    Bookkeeping::post($this->owner, $this->company, 400_000, 1450, 4950, '2026-12-31');
    $fields = Ink2::for($this->company, $this->year, null)->ink2r();

    // 3.2 + 4 000; 3.5 stays the purchases, 30 000; result 100 000 - 30 000 + 4 000 = 74 000.
    expect($fields)->toMatchArray([7411 => 4_000, 7511 => 30_000, 7450 => 74_000])
        ->and($fields)->not->toHaveKey(7510)
        ->and(stockLine('stock_change'))->toBe(400_000)
        ->and(stockLine('raw_materials'))->toBe(-3_000_000)
        ->and(stockLine('operating_result'))->toBe(7_400_000);
});

it('reports a decrease in finished goods on INK2R 3.2 − (7510)', function () {
    Bookkeeping::post($this->owner, $this->company, 300_000, 4950, 1450, '2026-12-31');
    $fields = Ink2::for($this->company, $this->year, null)->ink2r();

    // 3.2 − 3 000; result 100 000 - 30 000 - 3 000 = 67 000.
    expect($fields)->toMatchArray([7510 => 3_000, 7511 => 30_000, 7450 => 67_000])
        ->and($fields)->not->toHaveKey(7411)
        ->and(stockLine('stock_change'))->toBe(-300_000);
});

it('reports purchases marked as goods for resale and the stock change of goods on INK2R 3.6 (7512) and K2 Handelsvaror', function () {
    app(UpdateAccount::class)($this->owner, Account::query()->where('number', 4010)->firstOrFail(), ['sru_code' => '7512']);
    // Goods worth 1 000 are left in stock at the end of the year.
    Bookkeeping::post($this->owner, $this->company, 100_000, 1460, 4960, '2026-12-31');
    $fields = Ink2::for($this->company, $this->year, null)->ink2r();

    // 3.6 Handelsvaror: 30 000 - 1 000 = 29 000; nothing on 3.5.
    expect($fields)->toMatchArray([7512 => 29_000, 7450 => 71_000])
        ->and($fields)->not->toHaveKey(7511)
        ->and(stockLine('goods_for_resale'))->toBe(-2_900_000)
        ->and(stockLine('raw_materials'))->toBeNull()
        ->and(AnnualAccounts::for($this->year)->yearResult())->toBe(7_100_000);
});

it('warns instead of filing silently when a one-sided field like 3.5 turns negative', function () {
    // Raw materials in stock rise by 40 000 against purchases of 30 000: 3.5 = -10 000.
    Bookkeeping::post($this->owner, $this->company, 4_000_000, 1410, 4910, '2026-12-31');
    $ink2 = Ink2::for($this->company, $this->year, null);

    expect($ink2->ink2r()[7511])->toBe(-10_000)
        ->and(implode(' ', $ink2->warnings()))->toContain('Fält 7511');
});

it('lets the chart of accounts mark a purchase account as goods for resale and back, with a toast and an audit entry', function () {
    $account = Account::query()->where('number', 4010)->firstOrFail();

    Livewire::test(ChartOfAccounts::class)
        ->call('toggleGoodsForResale', $account->id)
        ->assertDispatched('toast');

    expect($account->fresh()->sru_code)->toBe('7512')
        ->and(AuditLog::query()->where('action', 'account.updated')->latest('id')->first()->payload['after'])->toMatchArray(['sru_code' => '7512']);

    Livewire::test(ChartOfAccounts::class)->call('toggleGoodsForResale', $account->id);

    expect($account->fresh()->sru_code)->toBeNull();
});
