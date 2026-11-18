<?php

declare(strict_types=1);

use App\Actions\Accounting\CorrectVoucher;
use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Actions\Company\SetDimensionScope;
use App\Livewire\Accounting\Reports;
use App\Livewire\Accounting\VoucherList;
use App\Models\User;
use App\Models\Voucher;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Actions\NotifyPayrollResponsibles;
use App\Modules\Payroll\PayrollModule;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Notifications\Notification;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\DimensionScopeFixture;

/*
 * ADR-081 gap 10, review P1: payroll vouchers and their corrections are never
 * shown to a member limited to cost centres or projects. A unit's salary cost
 * can be one person's pay; the analysis view (E17) may later open aggregated
 * pay with its rule that hides small groups.
 */
beforeEach(function () {
    $this->fixture = DimensionScopeFixture::make();

    // A salary on cost centre 10 of 30 000 kr, booked as the payroll module books it.
    $this->payroll = CurrentCompany::run($this->fixture->company, function (): Voucher {
        $draft = app(SaveDraftVoucher::class)($this->fixture->owner, $this->fixture->company, [
            'voucher_date' => '2026-03-25', 'description' => 'Lön mars', 'series' => 'A',
            'rows' => [['account' => 7010, 'debit_ore' => 3_000_000, 'cost_center' => '10'], ['account' => 1930, 'credit_ore' => 3_000_000]],
        ]);
        $draft->forceFill(['source' => 'PAYROLL'])->save();

        return app(PostVoucher::class)($this->fixture->owner, $draft->fresh() ?? $draft);
    });
    $this->correction = CurrentCompany::run($this->fixture->company, fn (): Voucher => app(CorrectVoucher::class)($this->fixture->owner, $this->payroll, 'Fel månad', '2026-03-26'));

    $this->member = $this->fixture->member();
    $this->actingAs($this->member);
    CurrentCompany::forget();
});

it('leaves payroll vouchers and their corrections out of the voucher list', function () {
    $listed = Livewire::test(VoucherList::class, ['fiscalYearId' => null])->viewData('vouchers')->pluck('id')->all();

    expect($listed)->not->toContain($this->payroll->id)
        ->not->toContain($this->correction->id)
        ->toContain($this->fixture->vouchers['inside']->id);
});

it('answers 404 for a payroll voucher and its correction', function () {
    $this->get(route('accounting.vouchers.show', $this->payroll))->assertNotFound();
    $this->get(route('accounting.vouchers.show', $this->correction))->assertNotFound();
    $this->get(route('accounting.vouchers.create', ['kopia' => $this->payroll->id]))->assertNotFound();
});

it('leaves payroll out of the unit\'s reports', function () {
    $income = Livewire::test(Reports::class, ['rapport' => 'resultat', 'kst' => '10'])->viewData('data');
    $ledger = Livewire::test(Reports::class, ['rapport' => 'huvudbok', 'kst' => '10'])->viewData('data');

    expect($income['result'])->toBe(50_000 - 30_000)
        ->and(collect($ledger)->pluck('number')->all())->not->toContain(7010);

    $this->get(route('accounting.reports.csv', ['rapport' => 'huvudbok', 'kst' => '10']))->assertOk()->assertDontSee('7010');
});

it('still shows payroll to an unlimited member', function () {
    $this->actingAs($this->fixture->owner);

    $ledger = Livewire::test(Reports::class, ['rapport' => 'huvudbok', 'kst' => '10'])->viewData('data');

    expect(collect($ledger)->pluck('number')->all())->toContain(7010);
});

it('tells no limited member about payroll', function () {
    app(ModuleManager::class)->enable(PayrollModule::KEY, $this->fixture->company, $this->fixture->owner);
    $admin = Bookkeeping::member($this->fixture->company, 'payroll_admin');
    $limited = Bookkeeping::member($this->fixture->company, 'payroll_admin');
    app(SetDimensionScope::class)($this->fixture->owner, $this->fixture->company, $limited->id, [$this->fixture->north->id], []);

    app(NotifyPayrollResponsibles::class)(fn () => new class extends Notification
    {
        /** @return list<string> */
        public function via(object $notifiable): array
        {
            return ['database'];
        }

        /** @return array<string, string> */
        public function toArray(object $notifiable): array
        {
            return ['text' => 'Nya skattetabeller'];
        }
    });

    expect(User::query()->findOrFail($admin->id)->notifications()->count())->toBe(1)
        ->and(User::query()->findOrFail($limited->id)->notifications()->count())->toBe(0);
});
