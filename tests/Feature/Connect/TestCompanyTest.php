<?php

declare(strict_types=1);

use App\Actions\Invoicing\SendInvoice;
use App\Mail\InvoiceMail;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Models\Voucher;
use App\Modules\Connect\Actions\CreateTestCompany;
use App\Modules\Connect\ConnectModule;
use App\Modules\Connect\Livewire\TestCompanyPanel;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Connect;

/*
 * Test companies (Testföretag, ADR-032): demo data for trying the API,
 * clearly marked, never e-mailing documents, left out of statistics.
 */

beforeEach(function () {
    Mail::fake();
    Connect::setUp();
});

it('creates a marked test company with demo data and Connect on, from artisan', function () {
    $user = User::factory()->create(['email' => 'integrator@exempel.example']);

    $this->artisan('connect:test-company', ['email' => 'integrator@exempel.example', '--name' => 'Prova API AB'])->assertSuccessful();

    $company = Company::query()->where('user_id', $user->id)->sole();

    expect($company->isTestCompany())->toBeTrue()
        ->and($company->name)->toBe('Prova API AB')
        ->and($company->org_number)->toStartWith('00')
        ->and(app(ModuleManager::class)->isEnabled(ConnectModule::KEY, $company))->toBeTrue()
        ->and(Company::query()->withoutTestCompanies()->whereKey($company->id)->exists())->toBeFalse();

    CurrentCompany::run($company, function (): void {
        expect(Voucher::query()->where('status', 'POSTED')->count())->toBeGreaterThanOrEqual(4)
            ->and(Invoice::query()->where('status', 'ISSUED')->count())->toBe(1)
            ->and(Invoice::query()->where('status', 'DRAFT')->count())->toBe(1);
    });

    // The integrator works against the demo data through the API.
    $key = Connect::apiKey($user, $company, ['invoices:view', 'supplier-invoices:approve']);
    $this->withToken($key)->getJson('/api/v1/customers')->assertOk()->assertJsonCount(2, 'data');
    $this->withToken($key)->getJson('/api/v1/supplier-invoices?status=REGISTERED')->assertOk()->assertJsonCount(1, 'data');

    $this->artisan('connect:test-company', ['email' => 'saknas@exempel.example'])->assertFailed();
});

it('shows a banner on every page and never e-mails its invoices', function () {
    $user = User::factory()->create();
    $company = app(CreateTestCompany::class)($user);
    $invoice = CurrentCompany::run($company, fn () => Invoice::query()->where('status', 'ISSUED')->sole());

    $this->actingAs($user->fresh())->get('/oversikt')->assertOk()->assertSee('data-test-company-banner', false)->assertSee('Testföretag.');

    app(SendInvoice::class)($user->fresh(), $invoice, 'kund@exempel.example');

    Mail::assertNotSent(InvoiceMail::class);
    expect($invoice->refresh()->sent_to)->toBe('kund@exempel.example');

    [$owner] = Bookkeeping::company();
    $this->actingAs($owner)->get('/oversikt')->assertOk()->assertDontSee('data-test-company-banner', false);
});

it('lets an owner create one from Anslutningar, at most three, and refuses others', function () {
    [$owner, $company] = Connect::company();

    Livewire::actingAs($owner)->test(TestCompanyPanel::class)->set('name', 'Mitt test AB')->call('create')->assertRedirect(route('dashboard'));
    expect(Company::query()->where('user_id', $owner->id)->where('is_test_company', true)->count())->toBe(1);

    $owner->switchCompany($company);
    $creator = app(CreateTestCompany::class);
    $creator($owner);
    $creator($owner);
    expect(fn () => $creator($owner))->toThrow(ValidationException::class);

    $bookkeeper = Bookkeeping::member($company, 'bookkeeper');
    Livewire::actingAs($bookkeeper)->test(TestCompanyPanel::class)->assertForbidden();
});

it('renders Anslutningar with apps, keys, webhooks and test companies for an owner', function () {
    [$owner, $company] = Connect::company();
    Connect::webhook($owner, $company, ['voucher.posted']);
    $auditor = Bookkeeping::member($company, 'auditor');

    $this->actingAs($owner)->get('/anslutningar')->assertOk()
        ->assertSee('API-nycklar')->assertSee('Webhooks')->assertSee('https://hooks.example.com/nonox')->assertSee('Testföretag för API:t');

    $this->actingAs($auditor)->get('/anslutningar')->assertForbidden();
});
