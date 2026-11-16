<?php

declare(strict_types=1);

use App\Actions\Company\LookUpCompany;
use App\Domain\Company\Industry;
use App\Domain\Company\LegalForm;
use App\Livewire\Company\CompanyDetailsForm;
use App\Livewire\Company\CreateCompanyForm;
use App\Models\RegistryCompany;
use App\Models\RegistryExclusion;
use App\Models\User;
use App\Support\CompanyRegistry\CompanyRegistries;
use App\Support\CompanyRegistry\CompanySize;
use App\Support\CompanyRegistry\FakeCompanyRegistry;
use App\Support\CompanyRegistry\LocalRegistry;
use App\Support\CompanyRegistry\RegisteredCompany;
use App\Support\CompanyRegistry\SniIndustry;
use App\Support\Tenancy\CurrentCompany;
use App\Support\WorkQuota;
use Database\Factories\CompanyFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

/*
 * Lookup in NONOX's own company register (ADR-035 addendum). Every company
 * below is invented; the register rows are written by the tests.
 */

function localRegistryRow(string $orgNumber, array $overrides = []): RegistryCompany
{
    return RegistryCompany::create([
        'org_number' => $orgNumber,
        'name' => 'Demobolaget Norr AB',
        'legal_form' => '49',
        'scb_status' => 1,
        'sni_code' => '62100',
        'sni_division' => '62',
        'address_line1' => 'SKÖNSTAVÄGEN 3 A',
        'co_address' => 'C/O DEMOFÖRVALTNING',
        'postal_code' => '12345',
        'city' => 'DEMOSTAD',
        'sources' => ['scb-bulk'],
        'fetched_at' => '2026-11-04 03:00:00+00',
        ...$overrides,
    ]);
}

function localFakeApi(): FakeCompanyRegistry
{
    $fake = new FakeCompanyRegistry;
    app()->instance(FakeCompanyRegistry::class, $fake);

    return $fake;
}

beforeEach(function () {
    Cache::flush();
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->number = CompanyFactory::organisationNumber();
});

describe('LookUpCompany with the local register', function () {
    it('answers an organisation number from the register without asking the API', function () {
        $fake = localFakeApi();
        localRegistryRow($this->number->digits);

        $found = app(LookUpCompany::class)($this->owner, $this->company, $this->number->formatted());

        expect($found->name)->toBe('Demobolaget Norr AB')
            ->and($found->legalForm())->toBe(LegalForm::Ab)
            ->and($found->formattedStreet())->toBe('Skönstavägen 3 A')
            ->and($found->formattedCoAddress())->toBe('c/o Demoförvaltning')
            ->and($found->formattedPostalCode())->toBe('123 45')
            ->and($found->formattedCity())->toBe('Demostad')
            ->and($found->sourceName)->toBe('NONOX företagsregister (bearbetade uppgifter från Bolagsverket och SCB)')
            ->and($found->sourceDate)->toBe('2026-11-04')
            ->and($fake->calls)->toBe(0);
    });

    it('uses one query for a lookup in the register', function () {
        localRegistryRow($this->number->digits);
        $registry = app(LocalRegistry::class);
        $registry::hasRows();

        DB::enableQueryLog();
        $found = $registry->find($this->number->digits);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        expect($found)->toHaveCount(1)->and($queries)->toHaveCount(1);
    });

    it('never returns a company that was excluded after an objection or an erasure request', function () {
        $fake = localFakeApi()->answer($this->number->digits, []);
        localRegistryRow($this->number->digits);
        RegistryExclusion::create(['org_number' => $this->number->digits, 'field' => RegistryExclusion::ALL, 'reason' => 'ERASURE']);

        expect(fn () => app(LookUpCompany::class)($this->owner, $this->company, $this->number->digits))
            ->toThrow(ValidationException::class, 'hittar inget företag')
            ->and($fake->calls)->toBe(1);
    });

    it('replaces a name that was excluded and keeps the rest', function () {
        localRegistryRow($this->number->digits);
        RegistryExclusion::create(['org_number' => $this->number->digits, 'field' => RegistryExclusion::NAME, 'reason' => 'OBJECTION']);

        $found = app(LocalRegistry::class)->find($this->number->digits)[0];

        expect($found->name)->toStartWith('Företag ')->and($found->name)->not->toContain('Demobolaget');
    });

    it('warns about deregistration and ongoing proceedings as Bolagsverket does', function () {
        localRegistryRow($this->number->digits, ['deregistered_on' => '2025-05-05', 'proceedings' => 'KK']);

        $found = app(LookUpCompany::class)($this->owner, $this->company, $this->number->digits);

        expect($found->deregisteredOn)->toBe('2025-05-05')
            ->and($found->isActive())->toBeFalse()
            ->and($found->proceedings)->toBe(['Konkurs']);
    });

    it('sends a personal number to the API driver and never to the register', function () {
        $this->travelTo('2026-11-01');
        localRegistryRow('1981121898');
        $fake = localFakeApi()->answer('198112189876', [RegisteredCompany::fromArray(['name' => 'Enskilda Demofirman', 'legalFormCode' => 'E'])]);

        $found = app(LookUpCompany::class)($this->owner, null, '198112189876');

        expect($found->name)->toBe('Enskilda Demofirman')
            ->and($found->sourceName)->toBe('Bolagsverket (demo)')
            ->and($fake->calls)->toBe(1)
            ->and(app(LocalRegistry::class)->find('198112189876'))->toBe([]);
    });

    it('falls back to the API driver when the register does not have the number', function () {
        localRegistryRow(CompanyFactory::organisationNumber()->digits);
        $fake = localFakeApi();

        $found = app(LookUpCompany::class)($this->owner, $this->company, $this->number->digits);

        expect($found->name)->toStartWith('Demobolaget')
            ->and($found->sourceName)->toBe('Bolagsverket (demo)')
            ->and($fake->calls)->toBe(1);
    });

    it('says not found when the register lacks the number and no API driver is configured', function () {
        config(['services.company_registry.driver' => 'bolagsverket']);
        localRegistryRow(CompanyFactory::organisationNumber()->digits);

        expect(CompanyRegistries::apiConfigured())->toBeFalse()
            ->and(CompanyRegistries::isConfigured())->toBeTrue()
            ->and(fn () => app(LookUpCompany::class)($this->owner, $this->company, $this->number->digits))
            ->toThrow(ValidationException::class, 'NONOX företagsregister (bearbetade uppgifter från Bolagsverket och SCB) hittar inget företag');
    });

    it('is available with rows and no API driver, and hidden with neither', function () {
        config(['services.company_registry.driver' => 'bolagsverket']);

        expect(CompanyRegistries::isConfigured())->toBeFalse();

        localRegistryRow($this->number->digits);

        expect(CompanyRegistries::isConfigured())->toBeTrue();
    });

    it('keeps the per-user, per-company and installation limits', function () {
        localRegistryRow($this->number->digits);
        $user = User::factory()->create();

        foreach (range(1, LookUpCompany::USER_LIMIT_PER_HOUR) as $ignored) {
            RateLimiter::hit("company-lookup:user:{$user->id}", 3600);
        }

        expect(fn () => app(LookUpCompany::class)($user, null, $this->number->digits))
            ->toThrow(ValidationException::class, WorkQuota::message());

        foreach (range(1, WorkQuota::LIMITS['company_lookup']) as $ignored) {
            RateLimiter::hit("work-quota:company_lookup:{$this->company->id}", 3600);
        }

        expect(fn () => app(LookUpCompany::class)($this->owner, $this->company, $this->number->digits))
            ->toThrow(ValidationException::class, WorkQuota::message());

        foreach (range(1, LookUpCompany::INSTALLATION_LIMIT_PER_MINUTE) as $ignored) {
            RateLimiter::hit('company-lookup:installation', 60);
        }

        // The installation-wide cap protects the outside register only: a lookup in our own register is not counted.
        expect(app(LookUpCompany::class)(User::factory()->create(), null, $this->number->digits)->name)->toBe('Demobolaget Norr AB');

        localFakeApi();
        $other = CompanyFactory::organisationNumber();

        expect(fn () => app(LookUpCompany::class)(User::factory()->create(), null, $other->digits))
            ->toThrow(ValidationException::class, WorkQuota::message());
    });

    it('checks Luhn before it touches the register', function () {
        localRegistryRow($this->number->digits);
        $wrong = substr($this->number->digits, 0, 9).(((int) $this->number->digits[9] + 1) % 10);

        $user = User::factory()->create();
        LocalRegistry::hasRows();
        DB::enableQueryLog();

        expect(fn () => app(LookUpCompany::class)($user, null, $wrong))
            ->toThrow(ValidationException::class, 'inte giltigt')
            ->and(collect(DB::getQueryLog())->filter(fn (array $q): bool => str_contains($q['query'], 'company_registry')))->toHaveCount(0);
    });

    it('is not affected by which company asks: another company gets the same answer and its own quota', function () {
        localRegistryRow($this->number->digits);
        [$otherOwner, $other] = Bookkeeping::company();
        CurrentCompany::set($other);

        $found = app(LookUpCompany::class)($otherOwner, $other, $this->number->digits);

        expect($found->name)->toBe('Demobolaget Norr AB')
            ->and(RateLimiter::attempts("work-quota:company_lookup:{$this->company->id}"))->toBe(0);
    });
});

describe('register data in a form', function () {
    it('maps SCB legal form codes to our legal forms', function (string $code, string $name, ?LegalForm $expected) {
        expect((new RegisteredCompany(name: $name, legalFormCode: $code))->legalForm())->toBe($expected);
    })->with([
        'aktiebolag' => ['49', 'Demo AB', LegalForm::Ab],
        'handelsbolag by name' => ['31', 'Demo Handelsbolag', LegalForm::Hb],
        'handelsbolag by abbreviation' => ['31', 'Exempel & Co HB', LegalForm::Hb],
        'kommanditbolag by abbreviation' => ['31', 'Demo KB', LegalForm::Kb],
        'kommanditbolag by name' => ['31', 'Demo Kommanditbolag', LegalForm::Kb],
        'partnership with neither' => ['31', 'Demo & Söner', null],
        'the word beats the abbreviation' => ['31', 'KB Bygg Handelsbolag', LegalForm::Hb],
        'both words' => ['31', 'Handelsbolag och Kommanditbolag', null],
        'both abbreviations' => ['31', 'KB HB Demo', null],
        'ekonomisk förening' => ['51', 'Demo ek för', LegalForm::EkonomiskForening],
        'bostadsrättsförening' => ['53', 'Brf Demo', LegalForm::EkonomiskForening],
        'ideell förening' => ['61', 'Demoklubben', LegalForm::IdeellForening],
        'stiftelse' => ['72', 'Demostiftelsen', LegalForm::Stiftelse],
        'family foundation' => ['71', 'Demofamiljens stiftelse', LegalForm::Stiftelse],
        'unknown code' => ['96', 'Demo Ltd', null],
    ]);

    it('writes addresses in normal case', function () {
        $company = new RegisteredCompany(name: 'X', street: 'SKÖNSTAVÄGEN 3 A', coAddress: 'C/O DEMO AB', postalCode: '12345', city: 'UPPLANDS VÄSBY');

        expect($company->formattedStreet())->toBe('Skönstavägen 3 A')
            ->and($company->formattedCoAddress())->toBe('c/o Demo AB')
            ->and($company->formattedPostalCode())->toBe('123 45')
            ->and($company->formattedCity())->toBe('Upplands Väsby')
            ->and((new RegisteredCompany(name: 'X', street: 'Byggvägen 7 b'))->formattedStreet())->toBe('Byggvägen 7 b');
    });

    it('suggests an industry from the SNI 2025 code', function (string $sni, Industry $expected) {
        expect(SniIndustry::for($sni))->toBe($expected);
    })->with([
        'restaurant' => ['56110', Industry::Restaurant],
        'mobile restaurant' => ['56120', Industry::Restaurant],
        'bar (Barverksamhet)' => ['56300', Industry::Restaurant],
        'catering' => ['56210', Industry::Other],
        'building' => ['41200', Industry::Crafts],
        'specialised construction' => ['43210', Industry::Crafts],
        'retail' => ['47711', Industry::Retail],
        'hairdresser' => ['96210', Industry::HairBeauty],
        'accounting' => ['69201', Industry::AccountingFirm],
        'audit is not accounting' => ['69202', Industry::Other],
        'programming' => ['62100', Industry::Consultant],
        'management consulting' => ['70200', Industry::Consultant],
        'engineering consulting' => ['71121', Industry::Consultant],
        'design' => ['74120', Industry::Other],
        'food manufacturing' => ['10710', Industry::Manufacturing],
        'machine repair' => ['33120', Industry::Manufacturing],
        'real estate' => ['68201', Industry::Other],
    ]);

    it('reads the employee classes and the K2 limits conservatively', function () {
        expect(CompanySize::hasEmployees(1))->toBeFalse()
            ->and(CompanySize::hasEmployees(2))->toBeTrue()
            ->and(CompanySize::hasEmployees(null))->toBeNull()
            ->and(CompanySize::likelyAboveK2Limits(7, 8))->toBeTrue()
            ->and(CompanySize::likelyAboveK2Limits(6, 8))->toBeFalse()
            ->and(CompanySize::likelyAboveK2Limits(7, 7))->toBeFalse()
            ->and(CompanySize::likelyAboveK2Limits(7, null))->toBeFalse();
    });
});

describe('onboarding', function () {
    beforeEach(function () {
        $this->newUser = User::factory()->create();
        $this->actingAs($this->newUser);
    });

    it('fills the form, the industry and the employer from the register and names the source and date', function () {
        localRegistryRow($this->number->digits, ['employee_class' => 3, 'turnover_class' => 5]);

        Livewire::test(CreateCompanyForm::class)
            ->set('org_number', $this->number->formatted())
            ->assertSet('name', 'Demobolaget Norr AB')
            ->assertSet('legal_form', 'AB')
            ->assertSet('address_line1', 'Skönstavägen 3 A')
            ->assertSet('postal_code', '123 45')
            ->assertSet('city', 'Demostad')
            ->assertSet('industry', 'consultant')
            ->assertSet('employer_registered', true)
            ->assertSee('Enligt NONOX företagsregister (bearbetade uppgifter från Bolagsverket och SCB), hämtade 4 nov 2026')
            ->assertSee('Förslag från registret, utifrån branschkoden 62100')
            ->assertDontSeeHtml('data-registry-hint');
    });

    it('suggests no employer for zero employees and nothing when the class is unknown', function () {
        localRegistryRow($this->number->digits, ['employee_class' => 1]);

        Livewire::test(CreateCompanyForm::class)
            ->set('employer_registered', true)
            ->set('org_number', $this->number->digits)
            ->assertSet('employer_registered', true);

        $other = CompanyFactory::organisationNumber();
        localRegistryRow($other->digits, ['employee_class' => 1]);

        Livewire::test(CreateCompanyForm::class)
            ->set('org_number', $other->digits)
            ->assertSet('employer_registered', false);

        $unknown = CompanyFactory::organisationNumber();
        localRegistryRow($unknown->digits, ['employee_class' => null]);

        Livewire::test(CreateCompanyForm::class)
            ->set('employer_registered', true)
            ->set('org_number', $unknown->digits)
            ->assertSet('employer_registered', true);
    });

    it('hints, without choosing, that a very large company may be too large for K2', function () {
        localRegistryRow($this->number->digits, ['employee_class' => 8, 'turnover_class' => 9]);

        Livewire::test(CreateCompanyForm::class)
            ->set('org_number', $this->number->digits)
            ->assertSet('reporting_framework', 'K2')
            ->assertSeeHtml('data-registry-hint')
            ->assertSee('större företag som inte får använda K2');
    });

    it('never overwrites what the user has typed or chosen', function () {
        localRegistryRow($this->number->digits, ['employee_class' => 4]);

        Livewire::test(CreateCompanyForm::class)
            ->set('industry', 'cafe')
            ->set('name', 'Mitt eget namn')
            ->set('org_number', $this->number->digits)
            ->call('lookUpCompany')
            ->assertSet('industry', 'cafe')
            ->assertSet('name', 'Mitt eget namn')
            ->assertSet('registryConflicts.name', 'Demobolaget Norr AB');

        Livewire::test(CreateCompanyForm::class)
            ->set('org_number', $this->number->digits)
            ->assertSet('industry', 'consultant')
            ->set('industry', 'retail')
            ->call('lookUpCompany')
            ->assertSet('industry', 'retail')
            ->assertSet('employer_registered', true)
            ->set('employer_registered', false)
            ->call('lookUpCompany')
            ->assertSet('employer_registered', false);
    });

    it('takes a suggestion back when another number is looked up and the user has not touched it', function () {
        $other = CompanyFactory::organisationNumber();
        localRegistryRow($this->number->digits);
        localRegistryRow($other->digits, ['name' => 'Demokrogen AB', 'sni_code' => '56110', 'sni_division' => '56']);

        Livewire::test(CreateCompanyForm::class)
            ->set('org_number', $this->number->digits)
            ->assertSet('industry', 'consultant')
            ->set('org_number', $other->digits)
            ->call('lookUpCompany')
            ->assertSet('industry', 'restaurant');
    });

    it('leaves a sole trader to the API driver and suggests nothing from it', function () {
        $this->travelTo('2026-11-01');
        localFakeApi();

        Livewire::test(CreateCompanyForm::class)
            ->set('org_number', '198112189876')
            ->assertSet('legal_form', 'EF')
            ->assertSet('industry', '')
            ->assertSee('Enligt Bolagsverket (demo)');
    });

    it('lets other forms use the register without industry or employer suggestions', function () {
        localRegistryRow(str_replace('-', '', (string) $this->company->org_number), ['employee_class' => 3]);
        $this->actingAs($this->owner);

        Livewire::test(CompanyDetailsForm::class, ['company' => $this->company])
            ->call('lookUpCompany')
            ->assertSee('Enligt NONOX företagsregister (bearbetade uppgifter från Bolagsverket och SCB), hämtade 4 nov 2026')
            ->assertDontSeeHtml('data-registry-suggestion');
    });
});

describe('review fixes', function () {
    it('says that the register only has legal persons for a personal number when no API driver is configured', function () {
        $this->travelTo('2026-11-01');
        config(['services.company_registry.driver' => 'bolagsverket']);
        localRegistryRow($this->number->digits);

        expect(fn () => app(LookUpCompany::class)($this->owner, null, '198112189876'))
            ->toThrow(ValidationException::class, 'har bara juridiska personer');

        $this->actingAs(User::factory()->create());
        $before = RateLimiter::attempts('company-lookup:installation');

        Livewire::test(CreateCompanyForm::class)
            ->set('org_number', '198112189876')
            ->assertSet('name', '')
            ->assertDontSee('hittar inget företag');

        expect(RateLimiter::attempts('company-lookup:installation'))->toBe($before);
    });

    it('is off when the instance switch is off, even with rows, and on by default locally', function () {
        localRegistryRow($this->number->digits);
        config(['services.company_registry.driver' => 'bolagsverket', 'services.company_registry.local' => 'false']);

        expect(CompanyRegistries::localEnabled())->toBeFalse()
            ->and(CompanyRegistries::isConfigured())->toBeFalse()
            ->and(CompanyRegistries::local())->toBeNull();

        config(['services.company_registry.local' => 'true']);

        expect(CompanyRegistries::isConfigured())->toBeTrue();

        config(['services.company_registry.local' => null]);

        expect(CompanyRegistries::localEnabled())->toBeTrue();

        app()->detectEnvironment(fn (): string => 'production');

        expect(CompanyRegistries::localEnabled())->toBeFalse();
    });

    it('uses the older of the two fetch dates', function () {
        localRegistryRow($this->number->digits, ['fetched_at' => '2026-11-04 03:00:00+00', 'bolagsverket_fetched_at' => '2026-11-01 03:00:00+00']);

        expect(app(LocalRegistry::class)->find($this->number->digits)[0]->sourceDate)->toBe('2026-11-01');
    });

    it('does not propose a name that was excluded, and says so', function () {
        localRegistryRow($this->number->digits);
        RegistryExclusion::create(['org_number' => $this->number->digits, 'field' => RegistryExclusion::NAME, 'reason' => 'OBJECTION']);
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateCompanyForm::class)
            ->set('org_number', $this->number->digits)
            ->call('lookUpCompany')
            ->assertSet('name', '')
            ->assertSet('address_line1', 'Skönstavägen 3 A')
            ->assertSee('Namnet visas inte');
    });

    it('keeps abbreviations and apostrophes right in capitals', function () {
        $company = new RegisteredCompany(name: 'X', street: "O'NEILLS VÄG 4 LGH 1101", coAddress: 'C/O DEMO AB');

        expect($company->formattedStreet())->toBe("O'Neills Väg 4 LGH 1101")
            ->and($company->formattedCoAddress())->toBe('c/o Demo AB');
    });

    it('formats the registration date as the source date', function () {
        localRegistryRow($this->number->digits);
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateCompanyForm::class)
            ->set('org_number', $this->number->digits)
            ->call('lookUpCompany')
            ->assertSee('registrerat')
            ->assertDontSee('2019-03-15');
    });

    it('takes a suggestion back when the next lookup has nothing to suggest, or fails', function () {
        $second = CompanyFactory::organisationNumber();
        localRegistryRow($this->number->digits, ['employee_class' => 8, 'turnover_class' => 9]);
        localRegistryRow($second->digits, ['name' => 'Demoandra AB', 'sni_code' => null, 'sni_division' => null, 'employee_class' => null]);
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateCompanyForm::class)
            ->set('org_number', $this->number->digits)
            ->assertSet('industry', 'consultant')
            ->assertSet('employer_registered', true)
            ->assertSeeHtml('data-registry-hint')
            ->set('org_number', $second->digits)
            ->call('lookUpCompany')
            ->assertSet('industry', '')
            ->assertSet('employer_registered', false)
            ->assertDontSeeHtml('data-registry-hint')
            ->assertDontSeeHtml('data-registry-suggestion')
            ->set('org_number', $this->number->digits)
            ->call('lookUpCompany')
            ->assertSet('industry', 'consultant')
            ->set('org_number', '1234')
            ->call('lookUpCompany')
            ->assertSet('industry', '')
            ->assertSet('employer_registered', false)
            ->assertDontSeeHtml('data-registry-hint');
    });

    it('keeps what the user chose when a later lookup finds less', function () {
        $second = CompanyFactory::organisationNumber();
        localRegistryRow($this->number->digits, ['employee_class' => 3]);
        localRegistryRow($second->digits, ['name' => 'Demoandra AB', 'sni_code' => null, 'sni_division' => null, 'employee_class' => null]);
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateCompanyForm::class)
            ->set('org_number', $this->number->digits)
            ->set('industry', 'retail')
            ->set('employer_registered', false)
            ->set('employer_registered', true)
            ->set('org_number', $second->digits)
            ->call('lookUpCompany')
            ->assertSet('industry', 'retail')
            ->assertSet('employer_registered', true);
    });

    it('clears an earlier company\'s name when the next lookup does not show its name', function () {
        $second = CompanyFactory::organisationNumber();
        localRegistryRow($this->number->digits, ['name' => 'Först AB']);
        localRegistryRow($second->digits, ['name' => 'Andra AB', 'address_line1' => 'ANDRAGATAN 2']);
        RegistryExclusion::create(['org_number' => $second->digits, 'field' => RegistryExclusion::NAME, 'reason' => 'OBJECTION']);
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateCompanyForm::class)
            ->set('org_number', $this->number->digits)
            ->assertSet('name', 'Först AB')
            ->set('org_number', $second->digits)
            ->call('lookUpCompany')
            ->assertSet('name', '')
            ->assertSet('address_line1', 'Andragatan 2');
    });
});
