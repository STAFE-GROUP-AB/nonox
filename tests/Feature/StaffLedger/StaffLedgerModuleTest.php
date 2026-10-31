<?php

declare(strict_types=1);

use App\Modules\Livewire\CompanyModulesTab;
use App\Modules\ModuleManager;
use App\Modules\StaffLedger\Actions\RecordManualEntry;
use App\Modules\StaffLedger\Domain\Direction;
use App\Modules\StaffLedger\Livewire\StaffLedgerSettingsForm;
use App\Modules\StaffLedger\StaffLedgerModule;
use App\Modules\StaffLedger\StaffLedgerSettings;
use App\Support\Tenancy\CurrentCompany;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\StaffLedger;

beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    [$this->otherOwner, $this->other] = Bookkeeping::company();
    $this->modules = app(ModuleManager::class);
    $this->actingAs($this->owner);
});

const STAFF_LEDGER_PAGES = ['staff-ledger.overview', 'staff-ledger.locations', 'staff-ledger.people', 'staff-ledger.mine'];

it('is installed but off, and its pages answer 404 until turned on', function () {
    foreach (STAFF_LEDGER_PAGES as $route) {
        $this->get(route($route))->assertNotFound();
    }

    $this->get(route('dashboard'))->assertDontSee(route('staff-ledger.overview'), escape: false);

    $this->modules->enable(StaffLedgerModule::KEY, $this->company, $this->owner);

    foreach (STAFF_LEDGER_PAGES as $route) {
        $this->get(route($route))->assertOk();
    }

    $this->get(route('dashboard'))->assertSee(route('staff-ledger.overview'), escape: false);
    $this->actingAs($this->otherOwner)->get(route('staff-ledger.overview'))->assertNotFound();
});

it('is listed under Moduler and turned on and off there, typing its name to confirm', function () {
    Livewire::test(CompanyModulesTab::class, ['company' => $this->company])
        ->assertSee('Personalliggare')
        ->call('ask', StaffLedgerModule::KEY, 'enable')
        ->call('confirm')
        ->assertDispatched('toast', message: 'Modulen Personalliggare är påslagen.');

    expect($this->modules->isEnabled(StaffLedgerModule::KEY, $this->company))->toBeTrue()
        ->and($this->modules->settings(StaffLedgerModule::KEY, $this->company))->toEqual(StaffLedgerSettings::DEFAULTS);

    Livewire::test(CompanyModulesTab::class, ['company' => $this->company])
        ->call('ask', StaffLedgerModule::KEY, 'disable')
        ->assertSee('Liggaren finns kvar och kan visas och exporteras vid kontrollbesök under hela bevarandetiden.')
        ->call('confirm')
        ->assertHasErrors('typedName')
        ->set('typedName', 'personalliggare')
        ->call('confirm')
        ->assertDispatched('toast', message: 'Modulen Personalliggare är avstängd. Inga uppgifter har tagits bort.');

    expect($this->modules->isEnabled(StaffLedgerModule::KEY, $this->company))->toBeFalse();
    $this->get(route('staff-ledger.overview'))->assertNotFound();
});

it('keeps the ledger and the control visit available for the retention period after it is turned off', function () {
    CurrentCompany::set($this->company);
    StaffLedger::enable($this->company, $this->owner);
    $location = StaffLedger::location($this->owner, $this->company);
    $person = StaffLedger::person($this->owner, $this->company);
    app(RecordManualEntry::class)($this->owner, $this->company, $person, $location, Direction::In, now()->toImmutable()->subHour(), 'Test');

    $this->modules->disable(StaffLedgerModule::KEY, $this->company, $this->owner, confirmed: true);
    CurrentCompany::forget();

    $this->get(route('staff-ledger.overview'))->assertNotFound();
    $this->get(route('staff-ledger.ledger'))->assertOk()->assertSee('Testa Person123');
    $this->get(route('staff-ledger.control-visit'))->assertOk();
    $this->get(route('dashboard'))->assertSee(route('staff-ledger.ledger'), escape: false);
});

it('shows its settings under Moduler and saves them with a toast', function () {
    $this->modules->enable(StaffLedgerModule::KEY, $this->company, $this->owner);

    Livewire::test(CompanyModulesTab::class, ['company' => $this->company])
        ->call('toggleSettings', StaffLedgerModule::KEY)
        ->assertSee('Påminn om glömd utcheckning efter (timmar)');

    Livewire::test(StaffLedgerSettingsForm::class, ['company' => $this->company])
        ->set('settings.reminder_after_hours', 100)
        ->call('save')
        ->assertHasErrors('settings.reminder_after_hours')
        ->set('settings.reminder_after_hours', 10)
        ->set('settings.long_shift_hours', 11)
        ->call('save')
        ->assertDispatched('toast', message: 'Inställningarna för personalliggaren är sparade.');

    expect($this->modules->settings(StaffLedgerModule::KEY, $this->company))->toMatchArray(['reminder_after_hours' => 10, 'long_shift_hours' => 11, 'clock_skew_minutes' => 5]);
});

it('is free of N+1 queries on its pages', function () {
    CurrentCompany::set($this->company);
    StaffLedger::enable($this->company, $this->owner);
    $location = StaffLedger::location($this->owner, $this->company);

    $count = function () {
        DB::enableQueryLog();
        DB::flushQueryLog();
        foreach (['staff-ledger.overview', 'staff-ledger.people', 'staff-ledger.ledger', 'staff-ledger.control-visit', 'staff-ledger.locations'] as $route) {
            $this->get(route($route))->assertOk();
        }

        return count(DB::getQueryLog());
    };

    foreach (['19900101123', '19910202234'] as $number) {
        $person = StaffLedger::person($this->owner, $this->company, $number);
        app(RecordManualEntry::class)($this->owner, $this->company, $person, $location, Direction::In, now()->toImmutable()->subHours(2), 'Test');
    }

    $count(); // warm the per-request caches
    $few = $count();

    foreach (['19920303345', '19930404456', '19940505567', '19950606678'] as $number) {
        $person = StaffLedger::person($this->owner, $this->company, $number);
        app(RecordManualEntry::class)($this->owner, $this->company, $person, $location, Direction::In, now()->toImmutable()->subHours(2), 'Test');
    }

    CurrentCompany::forget();

    expect($count())->toBe($few);
});
