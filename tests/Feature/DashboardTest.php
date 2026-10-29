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
        ->assertSee('Här är översikten för Ettan AB.')
        ->assertSee('Fyll i adress och bankgiro')
        ->assertSee('Kommer snart')
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
    $queries = count(DB::getQueryLog());

    expect($queries)->toBeLessThan(15);
});
