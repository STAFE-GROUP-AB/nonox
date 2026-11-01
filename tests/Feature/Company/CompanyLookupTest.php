<?php

declare(strict_types=1);

use App\Actions\Company\LookUpCompany;
use App\Actions\Payables\SaveSupplier;
use App\Domain\Company\OrganisationNumber;
use App\Livewire\Company\CompanyDetailsForm;
use App\Livewire\Company\CreateCompanyForm;
use App\Livewire\Invoicing\Customers;
use App\Livewire\Payables\Suppliers;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\CompanyRegistry\BolagsverketRegistry;
use App\Support\CompanyRegistry\CompanyRegistries;
use App\Support\CompanyRegistry\FakeCompanyRegistry;
use App\Support\CompanyRegistry\RegisteredCompany;
use App\Support\CompanyRegistry\RegistryUnavailable;
use App\Support\Tenancy\CurrentCompany;
use App\Support\WorkQuota;
use Database\Factories\CompanyFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

/*
 * Company lookup (ADR-035). The Bolagsverket answers below follow the
 * structure of the examples in their OpenAPI specification, with invented
 * names, numbers and addresses.
 */

function bolagsverketAnswer(array $overrides = []): array
{
    return ['organisationer' => [array_replace_recursive([
        'organisationsidentitet' => ['identitetsbeteckning' => '5599999990', 'typ' => ['kod' => 'ORGANISATIONSNUMMER', 'klartext' => 'Organisationsnummer']],
        'namnskyddslopnummer' => null,
        'organisationsnamn' => ['organisationsnamnLista' => [
            ['registreringsdatum' => '2024-03-15', 'namn' => 'Särskilda Namnet AB', 'organisationsnamntyp' => ['kod' => 'SARSKILT_FORETAGSNAMN', 'klartext' => 'Särskilt företagsnamn']],
            ['registreringsdatum' => '2020-03-15', 'namn' => 'Exempelcyklar AB', 'organisationsnamntyp' => ['kod' => 'FORETAGSNAMN', 'klartext' => 'Företagsnamn']],
        ], 'fel' => null, 'dataproducent' => 'Bolagsverket'],
        'organisationsform' => ['kod' => 'AB', 'klartext' => 'Aktiebolag', 'fel' => null, 'dataproducent' => 'Bolagsverket'],
        'postadressOrganisation' => ['postadress' => ['postnummer' => '12345', 'utdelningsadress' => 'Testvägen 2', 'land' => 'Sverige', 'coAdress' => 'C/o Annat Exempel AB', 'postort' => 'GRÖNKÖPING'], 'fel' => null, 'dataproducent' => 'SCB'],
        'organisationsdatum' => ['registreringsdatum' => '2000-01-23', 'fel' => null, 'dataproducent' => 'Bolagsverket', 'infortHosScb' => '2000-02-03'],
        'avregistreradOrganisation' => null,
        'pagaendeAvvecklingsEllerOmstruktureringsforfarande' => null,
    ], $overrides)]];
}

function notFoundPart(): array
{
    return ['fel' => ['typ' => 'ORGANISATION_FINNS_EJ', 'felBeskrivning' => 'Organisationen finns inte.'], 'dataproducent' => 'Bolagsverket'];
}

function useBolagsverket(string $environment = 'prod'): void
{
    config([
        'services.company_registry.driver' => 'bolagsverket',
        'services.company_registry.bolagsverket.client_id' => 'test-client',
        'services.company_registry.bolagsverket.client_secret' => 'test-secret',
        'services.company_registry.bolagsverket.environment' => $environment,
    ]);
}

function fakeRegistry(): FakeCompanyRegistry
{
    $fake = new FakeCompanyRegistry;
    app()->instance(FakeCompanyRegistry::class, $fake);

    return $fake;
}

function exampleRegistered(array $overrides = []): RegisteredCompany
{
    return RegisteredCompany::fromArray([
        'name' => 'Exempelbygg AB',
        'legalFormCode' => 'AB',
        'legalFormText' => 'Aktiebolag',
        'street' => 'Byggvägen 7',
        'postalCode' => '54321',
        'city' => 'EXEMPELBY',
        ...$overrides,
    ]);
}

beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->number = CompanyFactory::organisationNumber();
});

describe('Bolagsverket driver', function () {
    it('gets a token with the client credentials and looks the number up', function () {
        Http::fake([
            'portal.api.bolagsverket.se/oauth2/token' => Http::response(['access_token' => 'token-1', 'expires_in' => 3600, 'token_type' => 'Bearer']),
            'gw.api.bolagsverket.se/vardefulla-datamangder/v1/organisationer' => Http::response(bolagsverketAnswer()),
        ]);
        useBolagsverket();

        $found = BolagsverketRegistry::fromConfig()->find('5599999990');

        expect($found)->toHaveCount(1)
            ->and($found[0]->name)->toBe('Exempelcyklar AB')
            ->and($found[0]->legalFormCode)->toBe('AB')
            ->and($found[0]->street)->toBe('Testvägen 2')
            ->and($found[0]->coAddress)->toBe('C/o Annat Exempel AB')
            ->and($found[0]->formattedPostalCode())->toBe('123 45')
            ->and($found[0]->formattedCity())->toBe('Grönköping')
            ->and($found[0]->registeredOn)->toBe('2000-01-23')
            ->and($found[0]->isActive())->toBeTrue();

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/oauth2/token')
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('test-client:test-secret'))
            && $request['grant_type'] === 'client_credentials'
            && $request['scope'] === 'vardefulla-datamangder:read');
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/organisationer')
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer token-1')
            && $request->data() === ['identitetsbeteckning' => '5599999990']);
    });

    it('keeps the token for its lifetime and renews it once when it is refused', function () {
        Http::fake([
            '*/oauth2/token' => Http::sequence()
                ->push(['access_token' => 'token-1', 'expires_in' => 3600])
                ->push(['access_token' => 'token-2', 'expires_in' => 3600]),
            '*/organisationer' => Http::sequence()
                ->push(bolagsverketAnswer())
                ->push(['type' => 'urn:bolagsverket:error:auth', 'instance' => 'auth.error', 'status' => 401, 'title' => 'Unauthorized'], 401)
                ->push(bolagsverketAnswer()),
        ]);
        useBolagsverket();
        $registry = BolagsverketRegistry::fromConfig();

        $registry->find('5599999990');
        $registry->find('5599999990');

        Http::assertSentCount(5);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/organisationer') && $request->hasHeader('Authorization', 'Bearer token-2'));
    });

    it('uses the accept2 test environment when asked to', function () {
        Http::fake([
            'portal-accept2.api.bolagsverket.se/*' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
            'gw-accept2.api.bolagsverket.se/*' => Http::response(bolagsverketAnswer()),
        ]);
        useBolagsverket('test');

        expect(BolagsverketRegistry::fromConfig()->find('5599999990'))->toHaveCount(1);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '//gw.api.') || str_contains($request->url(), '//portal.api.'));
    });

    it('answers an unknown or refused number with nothing', function () {
        Http::fake([
            '*/oauth2/token' => Http::response(['access_token' => 'token-1', 'expires_in' => 3600]),
            '*/organisationer' => Http::sequence()
                ->push(bolagsverketAnswer(['organisationsnamn' => ['organisationsnamnLista' => null, ...notFoundPart()], 'organisationsform' => notFoundPart()]))
                ->push(['type' => 'about:blank', 'instance' => 'client.error', 'status' => 400, 'title' => 'Bad Request', 'detail' => 'Identitetsbeteckning har ogiltig kontrollsiffra.'], 400),
        ]);
        useBolagsverket();
        $registry = BolagsverketRegistry::fromConfig();

        expect($registry->find('5599999990'))->toBe([])
            ->and($registry->find('5599999990'))->toBe([]);
    });

    it('says the register is unavailable on errors, refused credentials and a missing source', function (Closure $fake) {
        $fake();
        useBolagsverket();

        expect(fn () => BolagsverketRegistry::fromConfig()->find('5599999990'))->toThrow(RegistryUnavailable::class);
    })->with([
        'server error' => fn () => fn () => Http::fake([
            '*/oauth2/token' => Http::response(['access_token' => 'token-1', 'expires_in' => 3600]),
            '*/organisationer' => Http::response(['status' => 500, 'title' => 'Internal Server Error'], 500),
        ]),
        'rate limited' => fn () => fn () => Http::fake([
            '*/oauth2/token' => Http::response(['access_token' => 'token-1', 'expires_in' => 3600]),
            '*/organisationer' => Http::response([], 429),
        ]),
        'credentials refused' => fn () => fn () => Http::fake([
            '*/oauth2/token' => Http::response(['error' => 'invalid_client'], 401),
        ]),
        'name source unavailable' => fn () => fn () => Http::fake([
            '*/oauth2/token' => Http::response(['access_token' => 'token-1', 'expires_in' => 3600]),
            '*/organisationer' => Http::response(bolagsverketAnswer(['organisationsnamn' => ['organisationsnamnLista' => null, 'fel' => ['typ' => 'OTILLGANGLIG_UPPGIFTSKALLA', 'felBeskrivning' => 'Uppkoppling mot Bolagsverket misslyckades.']]])),
        ]),
    ]);

    it('reads deregistration and ongoing proceedings', function () {
        Http::fake([
            '*/oauth2/token' => Http::response(['access_token' => 'token-1', 'expires_in' => 3600]),
            '*/organisationer' => Http::response(bolagsverketAnswer([
                'avregistreradOrganisation' => ['avregistreringsdatum' => '2023-05-05T00:00:00.000+00:00', 'fel' => null],
                'pagaendeAvvecklingsEllerOmstruktureringsforfarande' => ['pagaendeAvvecklingsEllerOmstruktureringsforfarandeLista' => [['kod' => 'KK', 'klartext' => 'Konkurs', 'fromDatum' => '2024-01-26T00:00:00.000+00:00']], 'fel' => null],
            ])),
        ]);
        useBolagsverket();

        $found = BolagsverketRegistry::fromConfig()->find('5599999990')[0];

        expect($found->deregisteredOn)->toBe('2023-05-05')
            ->and($found->isActive())->toBeFalse()
            ->and($found->proceedings)->toBe(['Konkurs']);
    });
});

describe('LookUpCompany', function () {
    it('rejects a number that fails the Luhn check without asking the register', function () {
        $fake = fakeRegistry();
        $digits = $this->number->digits;
        $wrong = substr($digits, 0, 9).(((int) $digits[9] + 1) % 10);

        expect(fn () => app(LookUpCompany::class)($this->owner, $this->company, $wrong))
            ->toThrow(ValidationException::class, 'Organisationsnumret är inte giltigt');
        expect($fake->calls)->toBe(0);
    });

    it('returns the register\'s answer and caches it briefly', function () {
        $fake = fakeRegistry()->answer($this->number->digits, [exampleRegistered()]);

        $first = app(LookUpCompany::class)($this->owner, $this->company, $this->number->formatted());
        $second = app(LookUpCompany::class)($this->owner, $this->company, $this->number->digits);

        expect($first->name)->toBe('Exempelbygg AB')
            ->and($second->name)->toBe('Exempelbygg AB')
            ->and($fake->calls)->toBe(1);
    });

    it('explains when nothing is found and when the register is down', function () {
        $unknown = CompanyFactory::organisationNumber();
        fakeRegistry()
            ->answer($this->number->digits, new RegistryUnavailable('down'))
            ->answer($unknown->digits, []);

        expect(fn () => app(LookUpCompany::class)($this->owner, $this->company, $unknown->digits))
            ->toThrow(ValidationException::class, 'hittar inget företag med organisationsnumret')
            ->and(fn () => app(LookUpCompany::class)($this->owner, $this->company, $this->number->digits))
            ->toThrow(ValidationException::class, 'svarar inte just nu');
    });

    it('sends a sole trader\'s personal number with the century and picks the active business', function () {
        $this->travelTo('2026-11-01');
        fakeRegistry()->answer('198112189876', [
            exampleRegistered(['name' => 'Gamla firman', 'deregisteredOn' => '2010-01-01']),
            exampleRegistered(['name' => 'Nya firman', 'legalFormCode' => 'E', 'legalFormText' => 'Enskild näringsidkare']),
        ]);

        $found = app(LookUpCompany::class)($this->owner, $this->company, '811218-9876');

        expect(LookUpCompany::identity(OrganisationNumber::tryFrom('811218-9876'), '811218-9876'))->toBe('198112189876')
            ->and(LookUpCompany::identity(OrganisationNumber::tryFrom('200511189870'), '200511189870'))->toBe('200511189870')
            ->and(LookUpCompany::identity($this->number, $this->number->formatted()))->toBe($this->number->digits)
            ->and($found->name)->toBe('Nya firman')
            ->and($found->legalForm()?->value)->toBe('EF');
    });

    it('caps lookups per user, also before a company exists', function () {
        fakeRegistry();
        $user = User::factory()->create();

        foreach (range(1, LookUpCompany::USER_LIMIT_PER_HOUR) as $ignored) {
            RateLimiter::hit("company-lookup:user:{$user->id}", 3600);
        }

        expect(fn () => app(LookUpCompany::class)($user, null, $this->number->digits))
            ->toThrow(ValidationException::class, WorkQuota::message());
    });

    it('caps lookups per company', function () {
        fakeRegistry();

        foreach (range(1, WorkQuota::LIMITS['company_lookup']) as $ignored) {
            RateLimiter::hit("work-quota:company_lookup:{$this->company->id}", 3600);
        }

        expect(fn () => app(LookUpCompany::class)($this->owner, $this->company, $this->number->digits))
            ->toThrow(ValidationException::class, WorkQuota::message());
    });

    it('is off without a register and never uses the invented one in production', function () {
        config(['services.company_registry.driver' => 'bolagsverket']);

        expect(CompanyRegistries::isConfigured())->toBeFalse()
            ->and(fn () => app(LookUpCompany::class)($this->owner, $this->company, $this->number->digits))
            ->toThrow(ValidationException::class, 'inte påslagen');

        config(['services.company_registry.driver' => null]);
        app()->detectEnvironment(fn (): string => 'production');

        expect(CompanyRegistries::driver())->toBe('')
            ->and(CompanyRegistries::isConfigured())->toBeFalse();

        config(['services.company_registry.driver' => 'fake']);

        expect(CompanyRegistries::make())->toBeNull();
    });
});

describe('forms', function () {
    it('fills an empty company form when the organisation number loses focus', function () {
        fakeRegistry()->answer($this->number->digits, [exampleRegistered()]);

        Livewire::test(CreateCompanyForm::class)
            ->assertSee('Hämta uppgifter')
            ->set('org_number', $this->number->formatted())
            ->assertSet('name', 'Exempelbygg AB')
            ->assertSet('legal_form', 'AB')
            ->assertSet('address_line1', 'Byggvägen 7')
            ->assertSet('postal_code', '543 21')
            ->assertSet('city', 'Exempelby')
            ->assertSet('registryConflicts', [])
            ->assertSee('sparas först när du sparar')
            ->assertDispatched('toast');
    });

    it('does not look up on its own when the name is already written', function () {
        $fake = fakeRegistry();

        Livewire::test(CreateCompanyForm::class)
            ->set('name', 'Mitt Bolag AB')
            ->set('org_number', $this->number->formatted())
            ->assertSet('name', 'Mitt Bolag AB')
            ->assertSet('registryResult', null);

        expect($fake->calls)->toBe(0);
    });

    it('never overwrites a field the user has edited without asking', function () {
        fakeRegistry()->answer($this->number->digits, [exampleRegistered(['legalFormCode' => 'E', 'legalFormText' => 'Enskild näringsidkare'])]);

        Livewire::test(CreateCompanyForm::class)
            ->set('name', 'Mitt Eget Namn')
            ->set('city', 'Min Ort')
            ->set('org_number', $this->number->formatted())
            ->call('lookUpCompany')
            ->assertSet('name', 'Mitt Eget Namn')
            ->assertSet('city', 'Min Ort')
            ->assertSet('address_line1', 'Byggvägen 7')
            ->assertSet('legal_form', 'EF')
            ->assertSet('registryConflicts', ['name' => 'Exempelbygg AB', 'city' => 'Exempelby'])
            ->assertSee('Använd registrets uppgifter')
            ->call('keepOwnValues')
            ->assertSet('name', 'Mitt Eget Namn')
            ->assertSet('registryConflicts', [])
            ->call('lookUpCompany')
            ->call('useRegistryValues')
            ->assertSet('name', 'Exempelbygg AB')
            ->assertSet('city', 'Exempelby');
    });

    it('replaces what an earlier lookup filled in, but not what the user changed after it', function () {
        $other = CompanyFactory::organisationNumber();
        fakeRegistry()
            ->answer($this->number->digits, [exampleRegistered()])
            ->answer($other->digits, [exampleRegistered(['name' => 'Annat Bolag AB', 'street' => 'Andra gatan 3'])]);

        Livewire::test(CreateCompanyForm::class)
            ->set('org_number', $this->number->formatted())
            ->set('address_line1', 'Min egen adress 1')
            ->set('org_number', $other->formatted())
            ->call('lookUpCompany')
            ->assertSet('name', 'Annat Bolag AB')
            ->assertSet('address_line1', 'Min egen adress 1')
            ->assertSet('registryConflicts', ['address_line1' => 'Andra gatan 3']);
    });

    it('shows why nothing was filled in', function () {
        fakeRegistry()->answer($this->number->digits, new RegistryUnavailable('down'));

        Livewire::test(CreateCompanyForm::class)
            ->set('org_number', '123')
            ->assertSet('registryNotice', '')
            ->call('lookUpCompany')
            ->assertSee('Organisationsnumret är inte giltigt')
            ->set('org_number', $this->number->formatted())
            ->assertSee('svarar inte just nu')
            ->assertSet('name', '');
    });

    it('fills the customer form, c/o address included', function () {
        fakeRegistry()->answer($this->number->digits, [exampleRegistered(['coAddress' => 'C/o Kontoret'])]);

        Livewire::test(Customers::class)
            ->call('create')
            ->assertSee('Hämta uppgifter')
            ->set('form.org_number', $this->number->formatted())
            ->assertSet('form.name', 'Exempelbygg AB')
            ->assertSet('form.address_line2', 'C/o Kontoret')
            ->assertSet('form.postal_code', '543 21');
    });

    it('fills the supplier form and checks the VAT number in VIES', function () {
        fakeRegistry()->answer($this->number->digits, [exampleRegistered()]);
        Http::fake(['*/check-vat-number' => Http::response(['valid' => true, 'name' => 'BEISPIEL GMBH', 'requestIdentifier' => 'WAPI9'])]);
        $supplier = app(SaveSupplier::class)($this->owner, $this->company, ['name' => 'Beispiel GmbH', 'vat_number' => 'DE123456789', 'payment_terms_days' => 30]);

        Livewire::test(Suppliers::class)
            ->call('create')
            ->set('form.org_number', $this->number->formatted())
            ->assertSet('form.name', 'Exempelbygg AB')
            ->assertSet('form.city', 'Exempelby')
            ->call('edit', $supplier->id)
            ->assertSet('registryResult', null)
            ->assertSee('Kontrollera i VIES')
            ->call('checkVatNumber')
            ->assertSee('Giltigt i VIES')
            ->assertSee('WAPI9');

        expect(AuditLog::query()->where('action', 'supplier.vat_number_checked')->where('subject_id', $supplier->id)->exists())->toBeTrue();
    });

    it('proposes the register\'s details in the company settings', function () {
        $digits = str_replace('-', '', (string) $this->company->org_number);
        fakeRegistry()->answer($digits, [exampleRegistered(['name' => $this->company->name])]);

        Livewire::test(CompanyDetailsForm::class, ['company' => $this->company])
            ->set('state.address_line1', 'Gamla vägen 1')
            ->call('lookUpCompany')
            ->assertSet('state.address_line1', 'Gamla vägen 1')
            ->assertSet('registryConflicts.address_line1', 'Byggvägen 7')
            ->call('useRegistryValues')
            ->assertSet('state.address_line1', 'Byggvägen 7');

        expect($this->company->fresh()->address_line1)->not->toBe('Byggvägen 7');
    });

    it('does not offer lookup to someone who cannot change the company', function () {
        fakeRegistry();
        $this->actingAs(Bookkeeping::member($this->company, 'auditor'));

        Livewire::test(CompanyDetailsForm::class, ['company' => $this->company])
            ->assertDontSee('Hämta uppgifter')
            ->call('lookUpCompany')
            ->assertForbidden();
    });

    it('hides the button when no register is configured', function () {
        config(['services.company_registry.driver' => 'bolagsverket']);

        Livewire::test(Customers::class)
            ->call('create')
            ->assertDontSee('Hämta uppgifter');
    });
});
