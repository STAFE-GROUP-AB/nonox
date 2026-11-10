<?php

declare(strict_types=1);

use App\Domain\Company\Industry;
use App\Livewire\Company\CreateCompanyForm;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Modules\ModuleManager;
use App\Support\CompanyRegistry\FakeCompanyRegistry;
use App\Support\CompanyRegistry\RegisteredCompany;
use Database\Factories\CompanyFactory;
use Livewire\Livewire;
use Tests\Support\Onboarding;

/*
 * /kom-igang is prefilled from the waiting-list answers, and the Kom igång
 * checklist on the overview follows the industry and the old program
 * (ADR-038 addendum).
 */

describe('prefilled /kom-igang', function () {
    it('fills name, organisation number and industry from the approved entry, all of it editable', function () {
        $number = CompanyFactory::organisationNumber();
        [$entry, $user] = Onboarding::approved(['company_name' => 'Exempelkaféet AB', 'org_number' => $number->formatted(), 'industry' => 'bokforing-for-cafeer']);

        Livewire::actingAs($user)->test(CreateCompanyForm::class)
            ->assertSet('name', 'Exempelkaféet AB')
            ->assertSet('org_number', $number->formatted())
            ->assertSet('industry', Industry::Cafe->value)
            ->set('name', 'Annat namn AB')
            ->assertSet('name', 'Annat namn AB')
            ->set('industry', Industry::Retail->value)
            ->assertSet('industry', Industry::Retail->value);
    });

    it('runs the company lookup on the organisation number and proposes the register\'s other values', function () {
        $number = CompanyFactory::organisationNumber();
        $fake = new FakeCompanyRegistry;
        app()->instance(FakeCompanyRegistry::class, $fake);
        $fake->answer($number->digits, [RegisteredCompany::fromArray(['name' => 'Exempelkaféet Aktiebolag', 'legalFormCode' => 'AB', 'legalFormText' => 'Aktiebolag', 'street' => 'Kaféstråket 1', 'postalCode' => '54321', 'city' => 'EXEMPELBY'])]);
        [, $user] = Onboarding::approved(['company_name' => 'Exempelkaféet AB', 'org_number' => $number->formatted()]);

        Livewire::actingAs($user)->test(CreateCompanyForm::class)
            ->assertSet('name', 'Exempelkaféet AB')
            ->assertSet('address_line1', 'Kaféstråket 1')
            ->assertSet('city', 'Exempelby')
            ->assertSet('registryConflicts', ['name' => 'Exempelkaféet Aktiebolag']);

        expect($fake->calls)->toBe(1);
    });

    it('leaves the form empty for someone without an approved entry', function () {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test(CreateCompanyForm::class)
            ->assertSet('name', '')->assertSet('org_number', '')->assertSet('industry', '');
    });

    it('ignores an entry that belongs to another user and one that is not approved', function () {
        Onboarding::approved(['company_name' => 'Någon annans AB']);
        $pending = User::factory()->create();
        WaitlistEntry::factory()->create(['email' => $pending->email, 'company_name' => 'Väntar AB', 'approved_user_id' => $pending->id]);

        Livewire::actingAs($pending)->test(CreateCompanyForm::class)->assertSet('name', '');
    });

    it('leaves an industry of "Annat" unselected', function () {
        [, $user] = Onboarding::approved(['industry' => 'annat']);

        Livewire::actingAs($user)->test(CreateCompanyForm::class)->assertSet('industry', '');
    });

    it('shows the page to the signed-in user', function () {
        [, $user] = Onboarding::approved(['company_name' => 'Exempelkaféet AB']);

        $this->actingAs($user)->get(route('onboarding'))->assertOk();
    });
});

describe('the Kom igång checklist', function () {
    function checklist(User $user): array
    {
        return test()->actingAs($user)->get(route('dashboard'))->viewData('steps');
    }

    function titles(array $steps): array
    {
        return array_column($steps, 'title');
    }

    it('puts "Flytta från Fortnox" first for someone who comes from Fortnox', function () {
        [, $user] = Onboarding::approved(['current_system' => 'fortnox']);
        Onboarding::company($user);

        $steps = checklist($user->fresh());

        expect(titles($steps)[0])->toBe('Skapa företaget')
            ->and(titles($steps)[1])->toBe('Flytta från Fortnox')
            ->and($steps[1]['link'])->toBe(route('fortnox.move'))
            ->and($steps[1]['done'])->toBeFalse();
    });

    it('marks the Fortnox step done once the move has been started', function () {
        [, $user] = Onboarding::approved(['current_system' => 'fortnox']);
        $company = Onboarding::company($user);
        Onboarding::fortnoxConnection($company, $user);

        expect(checklist($user->fresh())[1]['done'])->toBeTrue();
    });

    it('adds a payroll step when there are employees', function () {
        [, $user] = Onboarding::approved(['employees' => '5-9']);
        Onboarding::company($user);

        expect(titles(checklist($user->fresh())))->toContain('Lägg upp lön och anställda')
            ->and(titles(checklist($user->fresh())))->not->toContain('Flytta från Fortnox');
    });

    it('has no payroll step without employees', function () {
        [, $user] = Onboarding::approved(['employees' => 'inga']);
        Onboarding::company($user);

        expect(titles(checklist($user->fresh())))->not->toContain('Lägg upp lön och anställda');
    });

    it('suggests the modules of the industry, by name, linking to the module settings', function () {
        [, $user] = Onboarding::approved(['industry' => 'bokforing-for-cafeer', 'employees' => 'inga']);
        $company = Onboarding::company($user);

        $steps = checklist($user->fresh());
        $step = collect($steps)->firstWhere('title', 'Slå på modulerna för din bransch');

        expect($step)->not->toBeNull()
            ->and($step['link'])->toBe(route('teams.show', ['company' => $company->id, 'flik' => 'moduler']))
            ->and($step['done'])->toBeFalse()
            ->and($step['text'])->toContain('Kök')
            ->and($step['allowed'])->toBeTrue();
    });

    it('names only modules that exist and are offered, and not those already on', function () {
        [, $user] = Onboarding::approved(['industry' => 'bokforing-for-cafeer', 'employees' => 'inga']);
        $company = Onboarding::company($user);
        $user->refresh();
        app(ModuleManager::class)->enable('inventory', $company, $user);
        app(ModuleManager::class)->enable('kitchen', $company, $user);

        $step = collect(checklist($user->fresh()))->firstWhere('title', 'Slå på modulerna för din bransch');

        expect($step['text'])->not->toContain('Kök')->not->toContain('Lager')->toContain('Kassakopplingar')->and($step['done'])->toBeFalse();
    });

    it('takes the industry from the company when the user did not come through the queue', function () {
        $user = User::factory()->withCompany(fn ($factory) => $factory->state(['industry' => Industry::Restaurant]))->create();

        $step = collect(checklist($user))->firstWhere('title', 'Slå på modulerna för din bransch');

        expect($step)->not->toBeNull()
            ->and(titles(checklist($user)))->not->toContain('Flytta från Fortnox')
            ->and(titles(checklist($user)))->not->toContain('Lägg upp lön och anställda');
    });

    it('leaves the standard checklist alone for a company without industry and without an entry', function () {
        $user = User::factory()->withCompany()->create();

        expect(titles(checklist($user)))->toBe([
            'Skapa företaget',
            'Fyll i adress och bankgiro',
            'Bjud in din redovisningskonsult eller en kollega',
            'Flytta in bokföringen från ditt gamla program',
            'Skicka din första faktura',
        ]);
    });
});
