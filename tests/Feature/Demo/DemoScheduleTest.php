<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;

it('schedules the demo purge every ten minutes', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'demo:prune'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/10 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('runs from the command line, says what it removed, and touches nothing else', function () {
    $guest = User::factory()->create(['demo_expires_at' => now()->subMinute()]);
    $real = User::factory()->create();

    $this->artisan('demo:prune')
        ->expectsOutputToContain('Tog bort 0 demoföretag och 1 gäster.')
        ->assertSuccessful();

    expect(User::query()->whereKey($guest->id)->exists())->toBeFalse()
        ->and(User::query()->whereKey($real->id)->exists())->toBeTrue();
});
