<?php

declare(strict_types=1);

use App\Http\Middleware\EndExpiredSessions;
use App\Livewire\Profile\SignOutEverywhere;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

it('sends security headers on every page', function () {
    $this->get('/')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Content-Security-Policy', "frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'");
});

it('ends a session after the absolute limit', function () {
    $user = User::factory()->withCompany()->create();

    $this->actingAs($user)
        ->withSession([EndExpiredSessions::STARTED => time() - (EndExpiredSessions::ABSOLUTE_HOURS * 3600) - 1])
        ->get(route('dashboard'))
        ->assertRedirect(route('login'));
});

it('signs out every other session but keeps this one', function () {
    $user = User::factory()->withCompany()->create();
    $this->actingAs($user);

    Livewire::test(SignOutEverywhere::class)->call('signOutOthers');

    expect($user->fresh()->sessions_valid_from)->not->toBeNull();

    $this->withSession([EndExpiredSessions::STARTED => CarbonImmutable::now()->subMinute()->getTimestamp()])
        ->get(route('dashboard'))
        ->assertRedirect(route('login'));
});
