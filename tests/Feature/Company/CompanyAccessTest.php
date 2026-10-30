<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;

it('shows the settings of a company the user belongs to', function () {
    $user = User::factory()->withCompany()->create();
    $company = $user->currentCompany();

    $this->actingAs($user)
        ->get(route('teams.show', $company->id))
        ->assertOk()
        ->assertSee($company->name);

    expect(route('teams.show', $company->id, false))->toBe("/foretag/{$company->id}");
});

it('shows the settings when the user has several companies, as an accounting firm does', function () {
    $user = User::factory()->withCompany()->create();
    $first = $user->currentCompany();
    $second = Company::factory()->create(['user_id' => $user->id]);
    $first->users()->attach(User::factory()->create(), ['role' => 'bookkeeper']);

    $this->actingAs($user)->get(route('teams.show', $first->id))->assertOk()->assertSee($first->name);
    $this->actingAs($user)->get(route('teams.show', $second->id))->assertOk()->assertSee($second->name);
});

it('answers 404 for another company so its existence is not revealed', function () {
    $user = User::factory()->withCompany()->create();
    $other = Company::factory()->create();

    $this->actingAs($user)
        ->get(route('teams.show', $other->id))
        ->assertNotFound();
});

it('switches to another company the user belongs to', function () {
    $user = User::factory()->withCompany()->create();
    $second = Company::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->put(route('current-team.update'), ['team_id' => $second->id])
        ->assertRedirect(route('dashboard'));

    expect($user->fresh()->current_team_id)->toBe($second->id);
});

it('refuses to switch to a company the user does not belong to', function () {
    $user = User::factory()->withCompany()->create();
    $current = $user->current_team_id;
    $other = Company::factory()->create();

    $this->actingAs($user)
        ->put(route('current-team.update'), ['team_id' => $other->id])
        ->assertNotFound();

    expect($user->fresh()->current_team_id)->toBe($current);
});

it('lists every company the user owns or is a member of, by name', function () {
    $user = User::factory()->withCompany(fn ($factory) => $factory->state(['name' => 'Beta AB']))->create();
    $member = Company::factory()->create(['name' => 'Alfa AB']);
    $member->users()->attach($user, ['role' => 'bookkeeper']);

    expect($user->fresh()->companies()->pluck('name')->all())->toBe(['Alfa AB', 'Beta AB']);
});

it('stores memberships and invitations under the Jetstream team_id columns', function () {
    $company = Company::factory()->create();
    $member = User::factory()->create();

    $company->users()->attach($member, ['role' => 'auditor']);
    $invitation = $company->teamInvitations()->create(['email' => 'new@example.com', 'role' => 'admin']);

    $this->assertDatabaseHas('team_user', ['team_id' => $company->id, 'user_id' => $member->id, 'role' => 'auditor']);
    $this->assertDatabaseHas('team_invitations', ['id' => $invitation->id, 'team_id' => $company->id]);

    expect($company->users()->pluck('users.id')->all())->toBe([$member->id])
        ->and($member->teams()->pluck('teams.id')->all())->toBe([$company->id]);
});

it('never lets anyone delete a company', function () {
    $owner = User::factory()->withCompany()->create();

    expect($owner->can('delete', $owner->currentCompany()))->toBeFalse();
});

it('creates companies under /foretag/nytt', function () {
    $this->actingAs(User::factory()->withCompany()->create())
        ->get(route('teams.create'))
        ->assertOk()
        ->assertSee('Nytt företag');

    expect(route('teams.create', absolute: false))->toBe('/foretag/nytt');
});
