<?php

declare(strict_types=1);

use App\Models\FiscalYear;
use App\Models\SieImport;
use App\Models\User;
use App\Support\Luhn;
use App\Support\Tenancy\CurrentCompany;
use Tests\Support\Bookkeeping;

it('greets the user and guides them through the first steps', function () {
    $user = User::factory()->withCompany(fn ($factory) => $factory->state(['name' => 'Ettan AB']))
        ->create(['name' => 'Anna Andersson']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Hej Anna!')
        ->assertSee('Här är översikten för Ettan AB, '.now()->translatedFormat('F Y').'.')
        ->assertSee('Fyll i adress och bankgiro')
        ->assertSee(route('invoicing.invoices.create'), escape: false)
        ->assertDontSee('Jetstream')
        ->assertDontSee('Laravel');

    expect(route('dashboard', absolute: false))->toBe('/oversikt');
});

it('marks the details step done once address and bankgiro are filled in', function () {
    $user = User::factory()->withCompany(fn ($factory) => $factory->state([
        'address_line1' => 'Storgatan 1',
        'bankgiro' => '123-456'.Luhn::checkDigit('123456'),
    ]))->create();

    $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();

    expect(substr_count($response->getContent(), '(klart)'))->toBe(2);
});

it('links to the SIE import and ticks the step off once a year is read in', function () {
    [$owner, $company] = Bookkeeping::company();
    CurrentCompany::set($company);
    $this->actingAs($owner);

    $this->get(route('dashboard'))->assertOk()
        ->assertSee(route('accounting.sieImports.index'), escape: false)
        ->assertDontSee('Inläst: ');

    SieImport::create([
        'user_id' => $owner->id,
        'status' => 'COMPLETED',
        'original_name' => 'SIE4 2026.se',
        'file_path' => 'sie-import/test.se',
        'size' => 100,
        'sha256' => str_repeat('0', 64),
        'fiscal_year_id' => FiscalYear::query()->value('id'),
        'report' => [],
    ]);

    $this->get(route('dashboard'))->assertOk()
        ->assertSee('Inläst: 2026. Har du fler år läser du in dem på samma sätt.')
        ->assertSee('Läs in fler');
});

it('shows no import link to someone without import:sie', function () {
    [, $company] = Bookkeeping::company();
    $this->actingAs(Bookkeeping::member($company, 'bookkeeper'));

    $this->get(route('dashboard'))->assertOk()->assertDontSee(route('accounting.sieImports.index'), escape: false);
});

it('uses a fixed number of queries', function () {
    $user = User::factory()->withCompany()->create();
    $this->actingAs($user);

    DB::enableQueryLog();
    $this->get(route('dashboard'))->assertOk();
    $cold = count(DB::getQueryLog());

    DB::flushQueryLog();
    $this->get(route('dashboard'))->assertOk();
    $warm = count(DB::getQueryLog());

    // The overview is computed once and then read from the cache (ADR-027). Warm, what is left is one query per installed
    // module that keeps an archive (payroll, staff ledger) to decide whether its archive link shows, one per Livewire
    // component for the role fingerprint (LivewireCompanyGuard), the getting started steps and one for the overview's own fingerprint.
    // Cold also includes one lookup of reported VAT periods for Nästa datum (DeadlineCompletion), whatever the history,
    // and Momsbufferten's fiscal-year lookup (it reads the balances only when a year has begun).
    expect($cold)->toBeLessThan(47)
        ->and($warm)->toBeLessThan(17);
});

it('hides the getting started panel per user and company, and shows it again', function () {
    [$owner, $company] = Bookkeeping::company();
    [, $other] = Bookkeeping::company();
    $this->actingAs($owner);

    $this->put(route('dashboard.gettingStarted'), ['visible' => '0'])->assertRedirect(route('dashboard'));
    expect(session('toasts')[0]['message'])->toBe('Kom igång är dold. Du kan visa den igen längst ner på översikten.')
        ->and($owner->fresh()->hidesPanel('getting-started', $company))->toBeTrue()
        ->and($owner->fresh()->hidesPanel('getting-started', $other))->toBeFalse();

    $this->get(route('dashboard'))->assertOk()->assertDontSee('Ta det i den här ordningen')->assertSee('Visa Kom igång igen');

    $this->put(route('dashboard.gettingStarted'), ['visible' => '1']);
    $this->get(route('dashboard'))->assertOk()->assertSee('Ta det i den här ordningen')->assertSee('Kom igång visas igen.');
});
