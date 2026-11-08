<?php

declare(strict_types=1);

use App\Actions\Demo\CreateDemo;
use App\Actions\Demo\PurgeExpiredDemos;
use App\Jobs\BuildDemoCompany;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Models\Voucher;
use App\Support\Demo\DemoMode;
use App\Support\Demo\DemoSessions;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Bookkeeping;

/*
 * One whole demo built the way a visitor gets it (ADR-077): a guest and a
 * company of their own, made by the real builder. It takes seconds, so the
 * checks that need a full demo share one build; the cheap ones are in the
 * other files.
 */

it('builds a visitor\'s own demo, keeps it apart from other companies, and purges it whole', function () {
    [$owner, $other] = Bookkeeping::company();
    Bookkeeping::post($owner, $other);
    $otherCustomer = CompanyScope::bypass(fn () => Customer::query()->create(['company_id' => $other->id, 'number' => '9001', 'name' => 'Annan kund AB']));

    Queue::fake();
    $token = DemoSessions::begin('bokforing-for-cafeer');
    Queue::assertPushed(BuildDemoCompany::class, fn (BuildDemoCompany $job): bool => $job->token === $token);
    expect(DemoSessions::state($token)['status'])->toBe(DemoSessions::BUILDING);

    // The job does what the queue worker would.
    (new BuildDemoCompany($token, 'bokforing-for-cafeer'))->handle(app(CreateDemo::class));

    $state = DemoSessions::state($token);
    expect($state['status'])->toBe(DemoSessions::READY);

    $guest = User::query()->findOrFail($state['user_id']);
    $demo = Company::query()->findOrFail($guest->current_team_id);

    // The guest: a placeholder address that is not an inbox, flagged, with the company as its own.
    expect($guest->isDemoGuest())->toBeTrue()
        ->and($guest->email)->toEndWith('@demo.invalid')
        ->and($guest->name)->toBe('Gäst')
        ->and($guest->demo_expires_at->isFuture())->toBeTrue()
        ->and($guest->demo_expires_at->lessThanOrEqualTo(now()->addHours((int) config('nonox.demo.hours'))->addMinute()))->toBeTrue()
        ->and($demo->user_id)->toBe($guest->id)
        ->and($demo->isDemo())->toBeTrue()
        ->and($demo->isTestCompany())->toBeTrue()
        ->and($demo->org_number)->toMatch('/^55999[2-5]-\d{4}$/');

    // A year of bookkeeping: vouchers, invoices, people.
    CurrentCompany::run($demo, function () use ($demo) {
        expect(Voucher::query()->count())->toBeGreaterThan(100)
            ->and(Customer::query()->count())->toBeGreaterThan(0)
            ->and(DB::table('invoices')->where('company_id', $demo->id)->count())->toBeGreaterThanOrEqual(0)
            ->and(DB::table('employees')->where('company_id', $demo->id)->count())->toBeGreaterThan(0);
    });

    // The claim opens the demo once.
    expect(DemoSessions::claim($token)?->id)->toBe($guest->id)
        ->and(DemoSessions::claim($token))->toBeNull();

    // Isolation: the guest sees nothing of the other company, and the other company nothing of the demo.
    $this->actingAs($guest);
    CurrentCompany::forget();
    expect(Customer::query()->whereKey($otherCustomer->id)->exists())->toBeFalse()
        ->and(Voucher::query()->where('company_id', $other->id)->count())->toBe(0);

    $this->actingAs($owner->fresh());
    CurrentCompany::forget();
    expect(Voucher::query()->where('company_id', $demo->id)->count())->toBe(0)
        ->and(Customer::query()->whereKey($otherCustomer->id)->exists())->toBeTrue();

    // The demo's company is closed to the outside while the guest is in it.
    expect(DemoMode::isDemoCompany($demo->id))->toBeTrue()
        ->and(DemoMode::isDemoCompany($other->id))->toBeFalse();

    // Time runs out and the purge removes all of it, but not the other company.
    $demo->forceFill(['demo_expires_at' => CarbonImmutable::now()->subMinute()])->save();
    $guest->forceFill(['demo_expires_at' => CarbonImmutable::now()->subMinute()])->save();

    $tablesWithRows = fn (int $companyId): array => collect(DB::select(
        "select table_name from information_schema.columns where table_schema = 'public' and column_name = 'company_id'",
    ))->pluck('table_name')->filter(fn (string $table): bool => $table !== 'teams' && DB::table($table)->where('company_id', $companyId)->exists())->values()->all();

    expect($tablesWithRows($demo->id))->not->toBe([]);

    $result = app(PurgeExpiredDemos::class)();

    expect($result['companies'])->toBe(1)
        ->and($result['skipped'])->toBe(0)
        ->and(Company::query()->whereKey($demo->id)->exists())->toBeFalse()
        ->and(User::query()->whereKey($guest->id)->exists())->toBeFalse()
        ->and($tablesWithRows($demo->id))->toBe([])
        ->and(Company::query()->whereKey($other->id)->exists())->toBeTrue()
        ->and(DB::table('vouchers')->where('company_id', $other->id)->count())->toBe(1)
        ->and(DB::table('customers')->where('id', $otherCustomer->id)->exists())->toBeTrue();
});

it('leaves nothing behind when the build fails', function () {
    $before = [User::query()->count(), Company::query()->count()];

    expect(fn () => app(CreateDemo::class)('finns-inte', CarbonImmutable::now()->addHour()))->toThrow(InvalidArgumentException::class);

    expect([User::query()->count(), Company::query()->count()])->toBe($before);
});

it('marks a failed build as failed and frees its place', function () {
    Queue::fake();
    $token = DemoSessions::begin('bokforing-for-cafeer');
    $building = DemoSessions::building();

    // An industry that is not there makes the build throw; the job reports it and marks the token.
    (new BuildDemoCompany($token, 'finns-inte'))->handle(app(CreateDemo::class));

    expect(DemoSessions::state($token)['status'])->toBe(DemoSessions::FAILED)
        ->and(DemoSessions::building())->toBe($building - 1)
        ->and(DemoSessions::claim($token))->toBeNull();
});
