<?php

declare(strict_types=1);

use App\Actions\Demo\CreateDemo;
use App\Jobs\BuildDemoCompany;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Models\Voucher;
use App\Support\Demo\DemoMode;
use App\Support\Demo\DemoSessions;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Bookkeeping;
use Tests\Support\Demo;

/*
 * One whole demo built the way a visitor gets it (ADR-077): a guest and a
 * company of their own, made by the real builder in the demo database. It
 * takes seconds, so the checks that need a full demo share one build.
 */

beforeEach(fn () => Demo::enable());

it('builds a visitor\'s own demo in the demo database only, with the real triggers', function () {
    [$owner, $other] = Bookkeeping::company();
    Bookkeeping::post($owner, $other);

    $main = DB::connection(DemoMode::mainConnection());
    $before = [$main->table('users')->count(), $main->table('teams')->count(), $main->table('vouchers')->count(), $main->table('audit_logs')->count()];

    Queue::fake();
    $token = DemoSessions::begin('bokforing-for-cafeer');
    Queue::assertPushed(BuildDemoCompany::class, fn (BuildDemoCompany $job): bool => $job->token === $token && $job->connection === 'demo');
    expect(DemoSessions::state($token)['status'])->toBe(DemoSessions::BUILDING);

    // The job does what the queue worker would.
    (new BuildDemoCompany($token, 'bokforing-for-cafeer'))->handle(app(CreateDemo::class));

    $state = DemoSessions::state($token);
    expect($state['status'])->toBe(DemoSessions::READY);

    // Not one row of it in the main database.
    expect([$main->table('users')->count(), $main->table('teams')->count(), $main->table('vouchers')->count(), $main->table('audit_logs')->count()])->toBe($before)
        ->and($main->table('users')->where('email', 'like', '%@'.DemoMode::GUEST_DOMAIN)->exists())->toBeFalse();

    DemoMode::onDemoConnection(function () use ($state, $token, $other) {
        $guest = User::query()->findOrFail($state['user_id']);
        $demo = Company::query()->findOrFail($guest->current_team_id);

        // The guest: a placeholder address that is not an inbox, no password, the company as its own.
        expect($guest->email)->toEndWith('@demo.invalid')
            ->and($guest->name)->toBe('Gäst')
            ->and($demo->user_id)->toBe($guest->id)
            ->and($demo->isTestCompany())->toBeTrue()
            ->and($demo->org_number)->toMatch('/^00\d{4}-\d{4}$/')
            ->and(DemoMode::expiresAt($guest)?->isFuture())->toBeTrue()
            ->and(DemoMode::expiresAt($guest)?->lessThanOrEqualTo(now()->addHours((int) config('nonox.demo.hours'))->addMinute()))->toBeTrue();

        // A year of bookkeeping: vouchers, customers, people.
        CurrentCompany::run($demo, function () use ($demo) {
            expect(Voucher::query()->count())->toBeGreaterThan(100)
                ->and(Customer::query()->count())->toBeGreaterThan(0)
                ->and(DB::table('employees')->where('company_id', $demo->id)->count())->toBeGreaterThan(0);
        });

        // The demo shows the real rules: a posted voucher cannot be changed, not even there.
        $changed = true;

        try {
            DB::transaction(fn () => DB::table('vouchers')->where('company_id', $demo->id)->update(['description' => 'Ändrad']));
        } catch (Throwable) {
            $changed = false;
        }

        expect($changed)->toBeFalse();

        // The other company's data is not in the demo database.
        expect(Company::query()->where('org_number', $other->org_number)->exists())->toBeFalse();

        // The claim opens the demo once.
        $grant = DemoSessions::issueOpen($guest->id);

        expect(DemoSessions::claim($grant)?->id)->toBe($guest->id)
            ->and(DemoSessions::claim($grant))->toBeNull()
            ->and(DemoSessions::claim($token))->toBeNull();
    });

    // And the guest gets in through the real route and sees the banner and the company's pages.
    $guestId = $state['user_id'];
    $again = DemoSessions::issueOpen($guestId);

    $this->get(route('demo.open', $again))->assertRedirect(route('dashboard'));
    $this->withCookie(DemoMode::COOKIE, Demo::sessionId())->get(route('accounting.vouchers.index'))->assertOk()->assertSee('data-demo-banner', false);
});

it('leaves nothing behind when the build fails', function () {
    $count = fn (): array => [DB::connection('demo')->table('users')->count(), DB::connection('demo')->table('teams')->count()];
    $before = $count();

    expect(fn () => app(CreateDemo::class)('finns-inte', CarbonImmutable::now()->addHour()))->toThrow(InvalidArgumentException::class);

    expect($count())->toBe($before);
});

it('marks a failed build as failed and frees its place', function () {
    Queue::fake();
    $token = DemoSessions::begin('bokforing-for-cafeer');
    $building = DemoSessions::building();

    // An industry that is not there makes the build throw; the job reports it and marks the token.
    (new BuildDemoCompany($token, 'finns-inte'))->handle(app(CreateDemo::class));

    expect(DemoSessions::state($token)['status'])->toBe(DemoSessions::FAILED)
        ->and(DemoSessions::building())->toBe($building - 1);

    DemoMode::onDemoConnection(fn () => expect(DemoSessions::claim($token))->toBeNull());
});

it('builds a restaurant demo on a Sunday evening, when this Sunday\'s shifts have started', function () {
    // A Sunday after the shifts' start: the self-service extras must not claim a shift that has begun.
    $this->travelTo(CarbonImmutable::parse('2026-11-08 21:30:00', 'Europe/Stockholm'));
    expect(now()->isSunday())->toBeTrue();

    Queue::fake();
    $token = DemoSessions::begin('bokforing-for-restauranger');

    (new BuildDemoCompany($token, 'bokforing-for-restauranger'))->handle(app(CreateDemo::class));

    expect(DemoSessions::state($token)['status'])->toBe(DemoSessions::READY);
});
