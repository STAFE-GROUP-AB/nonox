<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\URL;

it('sends users without a company to /kom-igang', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertRedirect(route('onboarding'));

    expect(route('onboarding', absolute: false))->toBe('/kom-igang');
});

it('shows the company form on /kom-igang', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('onboarding'))
        ->assertOk()
        ->assertSee('Skapa ditt företag')
        ->assertSee('Organisationsnummer');
});

it('sends users who already have a company to the overview', function () {
    $this->actingAs(User::factory()->withCompany()->create())
        ->get(route('onboarding'))
        ->assertRedirect(route('dashboard'));
});

it('lets users with a company use the overview', function () {
    $this->actingAs(User::factory()->withCompany()->create())
        ->get(route('dashboard'))
        ->assertOk();
});

it('picks one of the user\'s companies when none is selected', function () {
    $user = User::factory()->withCompany()->create();
    $user->forceFill(['current_team_id' => null])->save();

    $this->actingAs($user->fresh())->get(route('dashboard'))->assertOk();

    expect($user->fresh()->current_team_id)->toBe($user->ownedTeams()->first()->id);
});

it('lists invitations for the user\'s email address', function () {
    $company = Company::factory()->create(['name' => 'Inbjudande AB']);
    $user = User::factory()->create(['email' => 'anna@example.com']);
    $company->teamInvitations()->create(['email' => 'ANNA@example.com', 'role' => 'bookkeeper']);

    $this->actingAs($user)
        ->get(route('onboarding'))
        ->assertOk()
        ->assertSee('Inbjudande AB')
        ->assertSee('Som bokförare');
});

it('accepts an invitation from /kom-igang', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['email' => 'anna@example.com']);
    $invitation = $company->teamInvitations()->create(['email' => 'anna@example.com', 'role' => 'auditor']);

    $this->actingAs($user)
        ->post(route('team-invitations.accept-from-onboarding', $invitation->id))
        ->assertRedirect(route('dashboard'));

    $user->refresh();

    expect($user->belongsToTeam($company))->toBeTrue()
        ->and($user->hasTeamRole($company, 'auditor'))->toBeTrue()
        ->and($user->current_team_id)->toBe($company->id)
        ->and(TeamInvitation::find($invitation->id))->toBeNull();
});

it('accepts an invitation from the signed link in the email', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create(['email' => 'anna@example.com']);
    $invitation = $company->teamInvitations()->create(['email' => 'anna@example.com', 'role' => 'admin']);

    $url = URL::signedRoute('team-invitations.accept', ['invitation' => $invitation->id]);

    expect(parse_url($url, PHP_URL_PATH))->toBe("/foretagsinbjudningar/{$invitation->id}");

    $this->actingAs($user)->get($url)->assertRedirect(route('dashboard'));

    expect($user->fresh()->belongsToTeam($company))->toBeTrue();
});

it('refuses to let someone else accept an invitation', function () {
    $company = Company::factory()->create();
    $invitation = $company->teamInvitations()->create(['email' => 'anna@example.com', 'role' => 'admin']);
    $intruder = User::factory()->create(['email' => 'eve@example.com']);

    $this->actingAs($intruder)
        ->post(route('team-invitations.accept-from-onboarding', $invitation->id))
        ->assertNotFound();

    expect($intruder->fresh()->belongsToTeam($company))->toBeFalse()
        ->and(TeamInvitation::find($invitation->id))->not->toBeNull();
});
