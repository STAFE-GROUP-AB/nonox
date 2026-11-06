<?php

declare(strict_types=1);

use App\Livewire\Hq\Integrations;
use App\Models\User;
use App\Modules\Banking\Models\BankConnection;
use App\Modules\Banking\Providers\FakeBankDataProvider;
use App\Modules\Peppol\Actions\SendInvoiceViaPeppol;
use App\Modules\Peppol\Drivers\LogAccessPoint;
use App\Support\Integrations\IntegrationHealth;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\Banking;
use Tests\Support\Bookkeeping;
use Tests\Support\Peppol;
use Tests\Support\PeppolFixtures;

/*
 * Integrationer in /hq and `integrations:status` (ADR-034 and ADR-050,
 * addenda 2026-11-06): how the bank feed and Peppol are doing across the
 * installation, for the operator. Counts and ids only: never a company's
 * name, a person or what a bank or supplier sent.
 */

beforeEach(function () {
    FakeBankDataProvider::reset();
    LogAccessPoint::reset();
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-03-22 10:00:00'));
    config(['nonox.hq_admins' => ['hq@example.test'], 'app.admin_email' => null]);
    $this->admin = User::factory()->withCompany()->create(['email' => 'hq@example.test']);
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
});

afterEach(function () {
    FakeBankDataProvider::reset();
    LogAccessPoint::reset();
});

it('answers 404 to everyone but an HQ admin', function () {
    $this->get(route('hq.integrations'))->assertNotFound();

    $this->actingAs($this->owner)->get(route('hq.integrations'))->assertNotFound();
    Livewire::test(Integrations::class)->assertStatus(404);

    $this->actingAs($this->admin)->get(route('hq.integrations'))
        ->assertOk()
        ->assertSee('Bank')
        ->assertSee('Peppol')
        ->assertSee('href="'.route('hq.integrations').'"', false);
});

it('counts connections that keep failing, consents that run out and sendings that wait, with ids only', function () {
    [$owner, $company] = PeppolFixtures::company();
    Banking::enable($company, $owner);
    $connection = Banking::connect($owner, $company, '2026-03-10');
    CurrentCompany::run($company, fn () => BankConnection::query()->whereKey($connection->id)->update(['consecutive_failures' => 4, 'last_error' => 'Enable Banking answered 503.']));

    Peppol::ready($company, $owner, 'acct-H');
    $customer = PeppolFixtures::customer($owner, $company, ['peppol_id' => '0007:'.PeppolFixtures::BUYER_ORG, 'invoice_delivery' => 'PEPPOL']);
    $invoice = PeppolFixtures::invoice($owner, $company, $customer, [['description' => 'Arbete', 'quantity' => '1', 'unit' => 'st', 'price' => '100', 'vat_rate' => 2500]]);
    LogAccessPoint::$failNext = 'HTTP 503';
    app(SendInvoiceViaPeppol::class)($owner, $invoice);
    $this->travel(3)->hours();
    $this->company = $company;

    $health = app(IntegrationHealth::class)();

    expect($health['banking']['failing'])->toBe(1)
        ->and($health['banking']['expiring'])->toBe(1)
        ->and($health['peppol']['stuck'])->toBe(1)
        ->and($health['peppol']['linked'])->toBe(1)
        ->and(json_encode($health))->not->toContain($this->company->name)
        ->and(json_encode($health))->not->toContain('5563343689')
        ->and($health['banking']['problems'])->not->toBe([])
        ->and($health['peppol']['problems'])->not->toBe([]);

    $this->artisan('integrations:status')->assertFailed();

    $this->actingAs($this->admin);
    Livewire::test(Integrations::class)->assertSee('Hämtningen misslyckas för 1 bankkoppling')->assertSee('1 e-faktura har väntat');
});

it('shows the kill switch and is healthy when nothing is wrong', function () {
    $this->artisan('integrations:status')->assertSuccessful();

    config(['services.banking.paused' => true, 'services.peppol.paused' => 'log']);
    $health = app(IntegrationHealth::class)();

    expect($health['banking']['paused'])->toBeTrue()
        ->and($health['peppol']['paused'])->toBeTrue();

    $this->actingAs($this->admin);
    Livewire::test(Integrations::class)->assertSee('NONOX_BANKING_PAUSED')->assertSee('Pausad');
});
