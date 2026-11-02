<?php

declare(strict_types=1);

use App\Actions\Accounting\ChangeFirstFiscalYear;
use App\Actions\Accounting\CreateNextFiscalYear;
use App\Actions\Accounting\InitialiseBookkeeping;
use App\Livewire\Accounting\FiscalYears;
use App\Models\FiscalYear;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-29'));
    $this->owner = User::factory()->withCompany()->create();
    $this->company = $this->owner->currentCompany();
    app(InitialiseBookkeeping::class)($this->company);
    $this->actingAs($this->owner = $this->owner->fresh());
});

it('shows the fiscal years on /rakenskapsar', function () {
    $this->get(route('accounting.fiscalYears'))
        ->assertOk()
        ->assertSee('2026-01-01')
        ->assertSee('Pågår nu');

    expect(route('accounting.fiscalYears', absolute: false))->toBe('/rakenskapsar');
});

it('creates the next twelve-month year right after the latest one', function () {
    $next = app(CreateNextFiscalYear::class)($this->owner, $this->company);

    expect($next->start_date->toDateString())->toBe('2027-01-01')
        ->and($next->end_date->toDateString())->toBe('2027-12-31');
});

it('creates the next year from the page with a toast', function () {
    Livewire::test(FiscalYears::class)
        ->call('createNext')
        ->assertDispatched('toast', message: 'Räkenskapsåret 2027 är skapat.');
});

it('lets the owner adjust the first fiscal year', function () {
    $year = FiscalYear::query()->sole();

    app(ChangeFirstFiscalYear::class)($this->owner, $year, '2025-09-01', '2026-12-31');

    expect($year->fresh()->start_date->toDateString())->toBe('2025-09-01')
        ->and($year->fresh()->label())->toBe('2025/2026');
});

it('explains why a first fiscal year is not allowed', function (string $start, string $end, string $message) {
    expect(fn () => app(ChangeFirstFiscalYear::class)($this->owner, FiscalYear::query()->sole(), $start, $end))
        ->toThrow(ValidationException::class, $message);
})->with([
    'longer than 18 months' => ['2025-01-01', '2026-12-31', 'högst 18 månader'],
    'mid-month start' => ['2025-09-15', '2026-12-31', 'första dagen i en månad'],
    'not a date' => ['igår', '2026-12-31', 'ÅÅÅÅ-MM-DD'],
]);

it('only lets the first year change while it is the only one', function () {
    app(CreateNextFiscalYear::class)($this->owner, $this->company);
    $first = FiscalYear::query()->orderBy('start_date')->first();

    expect(fn () => app(ChangeFirstFiscalYear::class)($this->owner, $first, '2025-09-01', '2026-12-31'))
        ->toThrow(ValidationException::class, 'Bara företagets första räkenskapsår');
});

it('changes the first year from the page with a toast', function () {
    $year = FiscalYear::query()->sole();

    Livewire::test(FiscalYears::class)
        ->call('startEditing', $year->id)
        ->set('startDate', '2025-09-01')
        ->set('endDate', '2026-12-31')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast', message: 'Räkenskapsåret är ändrat till 2025-09-01 – 2026-12-31.');
});

it('does not let a bookkeeper manage fiscal years', function () {
    $bookkeeper = User::factory()->create();
    $this->company->users()->attach($bookkeeper, ['role' => 'bookkeeper']);

    expect(fn () => app(CreateNextFiscalYear::class)($bookkeeper->fresh(), $this->company->fresh()))
        ->toThrow(AuthorizationException::class);
});

it('shows the voucher series on /verifikationsserier', function () {
    $this->get(route('accounting.voucherSeries'))
        ->assertOk()
        ->assertSee('Manuell')
        ->assertSee('Kundfakturor')
        ->assertSee('Används för manuella verifikationer');

    expect(route('accounting.voucherSeries', absolute: false))->toBe('/verifikationsserier');
});
