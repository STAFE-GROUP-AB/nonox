<?php

declare(strict_types=1);

use App\Domain\Company\VatPeriod;
use App\Livewire\Setup\FirstRunWizard;
use App\Mail\LoginCodeMail;
use App\Models\Company;
use App\Models\User;
use Database\Factories\CompanyFactory;
use Illuminate\Support\Facades\Mail;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    app()->detectEnvironment(fn () => 'local');
    config(['auth.login_codes.autofill' => false]);
    Mail::fake();
});

function sentCode(): string
{
    $code = null;

    Mail::assertQueued(LoginCodeMail::class, function (LoginCodeMail $mail) use (&$code) {
        $code = $mail->code;

        return true;
    });

    return (string) $code;
}

function companyStep(array $overrides = []): Testable
{
    $component = Livewire::test(FirstRunWizard::class);

    foreach ([
        'company_name' => 'Ettan AB',
        'org_number' => CompanyFactory::organisationNumber()->digits,
        'legal_form' => 'AB',
        'vat' => 'QUARTERLY',
        'fiscal_year_start_month' => 1,
        'framework_confirmed' => true,
        ...$overrides,
    ] as $property => $value) {
        $component->set($property, $value);
    }

    return $component->call('toPerson');
}

it('creates the first user and their company after the email is confirmed', function () {
    $component = companyStep(['company_name' => 'Ettan AB'])
        ->assertSet('step', 'person')
        ->set('name', 'Anna Andersson')
        ->set('email', 'Anna@Example.com')
        ->call('sendCode')
        ->assertSet('step', 'code');

    expect(User::count())->toBe(0);

    $component->set('code', sentCode())
        ->call('finish')
        ->assertRedirect(route('dashboard'));

    $user = User::sole();
    $company = Company::sole();

    expect($user->email)->toBe('anna@example.com')
        ->and($user->name)->toBe('Anna Andersson')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($company->name)->toBe('Ettan AB')
        ->and($user->ownsTeam($company))->toBeTrue()
        ->and($user->current_team_id)->toBe($company->id);

    $this->assertAuthenticatedAs($user);
});

it('stores a company that does not report VAT', function () {
    companyStep(['vat' => 'NONE'])
        ->set('name', 'Anna')
        ->set('email', 'anna@example.com')
        ->call('sendCode')
        ->set('code', sentCode())
        ->call('finish');

    $company = Company::sole();

    expect($company->vat_registered)->toBeFalse()
        ->and($company->vat_number)->toBeNull()
        ->and($company->vat_period)->toBe(VatPeriod::None);
});

it('asks again with a Swedish message when the organisation number is wrong', function () {
    companyStep(['org_number' => '123'])
        ->assertSet('step', 'company')
        ->assertHasErrors('org_number')
        ->assertSee('Organisationsnumret stämmer inte');
});

it('requires the company name', function () {
    companyStep(['company_name' => ''])
        ->assertSet('step', 'company')
        ->assertHasErrors('company_name');
});

it('does not finish with a wrong code', function () {
    $component = companyStep()
        ->set('name', 'Anna')
        ->set('email', 'anna@example.com')
        ->call('sendCode');

    $code = sentCode();

    $component->set('code', $code === '12345678' ? '87654321' : '12345678')
        ->call('finish')
        ->assertHasErrors('code');

    expect(User::count())->toBe(0);
    $this->assertGuest();
});

it('finishes in one click on a local installation with autofill', function () {
    config(['auth.login_codes.autofill' => true]);

    companyStep()
        ->set('name', 'Anna')
        ->set('email', 'anna@example.com')
        ->call('sendCode')
        ->assertRedirect(route('dashboard'));

    expect(User::sole()->email)->toBe('anna@example.com');
});

it('uses the confirmed address even if the email field is changed afterwards', function () {
    $component = companyStep()
        ->set('name', 'Anna')
        ->set('email', 'anna@example.com')
        ->call('sendCode');

    $component->set('email', 'someone-else@example.com')
        ->set('code', sentCode())
        ->call('finish');

    expect(User::sole()->email)->toBe('anna@example.com');
});

it('refuses to run once a user exists', function () {
    $component = companyStep()
        ->set('name', 'Anna')
        ->set('email', 'anna@example.com')
        ->call('sendCode');

    $code = sentCode();
    User::factory()->create(['email' => 'owner@example.com']);

    $component->set('code', $code)
        ->call('finish')
        ->assertRedirect('/');

    expect(User::count())->toBe(1)
        ->and(User::sole()->email)->toBe('owner@example.com');
});

it('refuses to run outside a local installation', function () {
    app()->detectEnvironment(fn () => 'production');

    companyStep()->assertRedirect('/')->assertSet('step', 'company');
});
