<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;

it('knows no company for guests', function () {
    expect(CurrentCompany::id())->toBeNull();
});

it('uses the signed-in user\'s current company', function () {
    $user = User::factory()->withCompany()->create();

    $this->actingAs($user->fresh());

    expect(CurrentCompany::id())->toBe($user->current_team_id);
});

it('follows the user when they switch company', function () {
    $user = User::factory()->withCompany()->create();
    $second = Company::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user = $user->fresh());

    expect(CurrentCompany::id())->not->toBe($second->id);

    $user->switchCompany($second);

    expect(CurrentCompany::id())->toBe($second->id);
});

it('stops trusting a company the user no longer belongs to', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create();
    $company->users()->attach($user, ['role' => 'bookkeeper']);
    $user->switchCompany($company);

    // Removed behind Jetstream's back: current_team_id still points at it.
    $company->users()->detach($user);

    $this->actingAs($user = $user->fresh());

    expect($user->current_team_id)->toBe($company->id)
        ->and($user->currentCompany())->toBeNull()
        ->and(CurrentCompany::id())->toBeNull();

    $this->get(route('dashboard'))->assertRedirect(route('onboarding'));
});

it('restores the previous company after run()', function () {
    $a = Company::factory()->create();
    $b = Company::factory()->create();

    CurrentCompany::set($a);

    $inside = CurrentCompany::run($b, fn () => CurrentCompany::id());

    expect($inside)->toBe($b->id)
        ->and(CurrentCompany::id())->toBe($a->id);
});

it('forgets the company when the user signs out', function () {
    $user = User::factory()->withCompany()->create();
    $this->actingAs($user->fresh());

    expect(CurrentCompany::id())->not->toBeNull();

    auth()->logout();

    expect(CurrentCompany::id())->toBeNull();
});

it('only bypasses the company filter in the tenancy code', function () {
    $offenders = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), '/Support/Tenancy/')) {
            continue;
        }

        if (preg_match('/withoutGlobalScopes?\s*\(/', (string) file_get_contents($file->getPathname()))) {
            $offenders[] = str_replace(base_path().'/', '', $file->getPathname());
        }
    }

    expect($offenders)->toBe([], 'Use CompanyScope::bypass() with a comment instead of withoutGlobalScope.');
});
