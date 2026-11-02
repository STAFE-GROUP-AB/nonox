<?php

declare(strict_types=1);

use App\Actions\Accounting\DeleteDraftVoucher;
use App\Actions\Accounting\PostVoucher;
use App\Domain\Accounting\VoucherStatus;
use App\Models\Voucher;
use App\Modules\ModuleManager;
use App\Modules\Pos\Actions\CreateDailyDraft;
use App\Modules\Pos\Actions\RecordPosSale;
use App\Modules\Pos\Drivers\Zettle\ZettleDriver;
use App\Modules\Pos\Livewire\PosPage;
use App\Modules\Pos\Livewire\PosSettingsForm;
use App\Modules\Pos\Models\PosFee;
use App\Modules\Pos\Models\PosSale;
use App\Modules\Pos\PosModule;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Pos;

/*
 * The day's sales as a draft voucher in series K (ADR-042): balanced, never
 * posted by NONOX, every sale used once, accounts from the settings.
 */

beforeEach(function () {
    Queue::fake();
    Pos::configure();
    $this->travelTo(CarbonImmutable::parse('2026-03-11 06:00:00'));
    [$this->owner, $this->company, $this->places] = Pos::company();
    $this->actingAs($this->owner);
    $this->connection = Pos::connection($this->company, $this->owner);
    $driver = app(ZettleDriver::class);
    $record = app(RecordPosSale::class);

    // 10 March: a 25 % sale by card, a 12 % sale in cash with 50 öre rounded away, and a refund by card.
    $record($this->connection, $driver->parseSale(Pos::purchase('10000000-0000-1000-8000-000000000001', [Pos::product('Kopp', '1', 20000)], [['type' => 'IZETTLE_CARD', 'amount' => 25000]], 25000, 5000, '2026-03-10T09:00:00.000+0000')), PosSale::WEBHOOK);
    $record($this->connection, $driver->parseSale(Pos::purchase('10000000-0000-1000-8000-000000000002', [Pos::product('Bulle', '2', 10000, '12')], [['type' => 'IZETTLE_CASH', 'amount' => 11150]], 11200, 1200, '2026-03-10T10:00:00.000+0000')), PosSale::WEBHOOK);
    $record($this->connection, $driver->parseSale(Pos::purchase('10000000-0000-1000-8000-000000000003', [Pos::product('Kopp', '1', 4000)], [['type' => 'IZETTLE_CARD', 'amount' => -5000]], -5000, -1000, '2026-03-10T11:00:00.000+0000', ['refund' => true])), PosSale::WEBHOOK);
    PosFee::create(['pos_connection_id' => $this->connection->id, 'external_id' => 'pay-1', 'type' => 'PAYMENT_FEE', 'business_date' => '2026-03-10', 'amount_ore' => 425, 'payload' => []]);
});

it('creates a balanced draft in series K that is not posted, and uses each sale once', function () {
    $voucher = app(CreateDailyDraft::class)($this->owner, $this->company, CarbonImmutable::parse('2026-03-10'))->load('rows.account', 'series');
    $rows = $voucher->rows->mapWithKeys(fn ($row) => [$row->account->number.'-'.($row->debit_ore->ore > 0 ? 'D' : 'K') => $row->debit_ore->ore + $row->credit_ore->ore]);

    expect($voucher->status)->toBe(VoucherStatus::Draft)
        ->and($voucher->number)->toBeNull()
        ->and($voucher->series->code)->toBe('K')
        ->and($voucher->voucher_date->toDateString())->toBe('2026-03-10')
        ->and($voucher->rows->sum(fn ($row) => $row->debit_ore->ore))->toBe($voucher->rows->sum(fn ($row) => $row->credit_ore->ore))
        ->and($rows['3001-K'])->toBe(16000)
        ->and($rows['2611-K'])->toBe(4000)
        ->and($rows['3002-K'])->toBe(10000)
        ->and($rows['2621-K'])->toBe(1200)
        ->and($rows['1910-D'])->toBe(11150)
        ->and($rows['6570-D'])->toBe(425)
        ->and($rows['3740-D'])->toBe(50)
        ->and(PosSale::query()->whereNull('voucher_id')->count())->toBe(0)
        ->and(PosFee::query()->value('voucher_id'))->toBe($voucher->id);

    expect(fn () => app(CreateDailyDraft::class)($this->owner, $this->company, CarbonImmutable::parse('2026-03-10')))->toThrow(ValidationException::class);

    // A person posts it, in series K.
    $posted = app(PostVoucher::class)($this->owner, $voucher->fresh());
    expect($posted->status)->toBe(VoucherStatus::Posted)->and($posted->number)->toBe(1);
});

it('frees the sales again when the draft is deleted', function () {
    $voucher = app(CreateDailyDraft::class)($this->owner, $this->company, CarbonImmutable::parse('2026-03-10'));
    app(DeleteDraftVoucher::class)($this->owner, $voucher);

    expect(PosSale::query()->whereNull('voucher_id')->count())->toBe(3)
        ->and(app(CreateDailyDraft::class)($this->owner, $this->company, CarbonImmutable::parse('2026-03-10')))->toBeInstanceOf(Voucher::class);
});

it('proposes nothing when the day does not add up', function () {
    PosSale::query()->first()->forceFill(['payments' => ['CARD' => 99999]])->save();

    expect(fn () => app(CreateDailyDraft::class)($this->owner, $this->company, CarbonImmutable::parse('2026-03-10')))
        ->toThrow(ValidationException::class, 'går inte ihop');
    expect(Voucher::query()->count())->toBe(0);
});

it('uses the accounts from the settings and refuses an account that is not in the chart', function () {
    Livewire::test(PosSettingsForm::class, ['company' => $this->company])
        ->set('settings.account_payment_CASH', '1911')
        ->call('save')
        ->assertHasErrors('settings.account_payment_CASH');

    Livewire::test(PosSettingsForm::class, ['company' => $this->company])
        ->set('settings.account_payment_CASH', '1930')
        ->call('save')
        ->assertDispatched('toast', message: 'Inställningarna för Kassakopplingar är sparade.');

    expect(app(ModuleManager::class)->settings(PosModule::KEY, $this->company)['account_payment_CASH'])->toBe(1930);

    $voucher = app(CreateDailyDraft::class)($this->owner, $this->company, CarbonImmutable::parse('2026-03-10'))->load('rows.account');
    expect($voucher->rows->contains(fn ($row) => $row->account->number === 1930 && $row->debit_ore->ore === 11150))->toBeTrue();
});

it('lets only vouchers:create propose the day, from the page or every morning', function () {
    $auditor = Bookkeeping::member($this->company, 'auditor');
    expect(fn () => app(CreateDailyDraft::class)($auditor, $this->company, CarbonImmutable::parse('2026-03-10')))->toThrow(AuthorizationException::class);

    Livewire::test(PosPage::class)->assertSee('2026-03-10')->call('createDraft', '2026-03-10')->assertRedirect();
    expect(Voucher::query()->count())->toBe(1);
});

it('proposes every past day each morning in the name of the person who connected the till', function () {
    $this->artisan('pos:daily-drafts', ['--company' => $this->company->id])->expectsOutputToContain('Created 1 draft vouchers.')->assertSuccessful();

    expect(Voucher::query()->sole()->created_by)->toBe($this->owner->id);

    $this->artisan('pos:daily-drafts', ['--company' => $this->company->id])->expectsOutputToContain('Created 0 draft vouchers.')->assertSuccessful();
});
