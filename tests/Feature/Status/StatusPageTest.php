<?php

declare(strict_types=1);

use App\Console\Commands\StatusHeartbeatCommand;
use App\Console\Commands\VerifyLedgerCommand;
use App\Domain\Status\IncidentSeverity;
use App\Domain\Status\IncidentStatus;
use App\Models\StatusHeartbeat;
use App\Models\StatusIncident;
use App\Support\Status\Heartbeats;
use App\Support\Status\StatusSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Banking;
use Tests\Support\Bookkeeping;
use Tests\Support\Peppol;

/*
 * The public status page (ADR-078): a Swedish page with the state of the
 * service, the jobs and the outside connections, plus the operator's
 * incident notes. Cached for a minute, a fixed number of queries, and
 * nothing about a company, a person or a secret.
 */

function incident(string $title, IncidentSeverity $severity, ?CarbonImmutable $resolved = null, string $note = 'Vi undersöker.'): StatusIncident
{
    $incident = StatusIncident::query()->create([
        'title' => $title,
        'severity' => $severity->value,
        'status' => ($resolved === null ? IncidentStatus::Investigating : IncidentStatus::Resolved)->value,
        'started_at' => $resolved === null ? now()->subHour() : $resolved->subHours(2),
        'resolved_at' => $resolved,
    ]);
    $incident->updates()->create(['status' => $incident->status->value, 'body' => $note, 'created_at' => now()]);

    return $incident;
}

beforeEach(function () {
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-11-08 10:00:00', 'Europe/Stockholm'));
    config(['app.landing.enabled' => true, 'queue.default' => 'sync']);
});

it('answers on a Swedish address without signing in and says that all is well on a fresh installation', function () {
    expect(route('status', [], false))->toBe('/status');

    $this->get('/status')->assertOk()
        ->assertSee('Driftstatus')
        ->assertSee('Allt fungerar')
        ->assertSee('Webbappen')
        ->assertSee('Databas')
        ->assertSee('Schemalagda jobb')
        ->assertSee('Nattlig integritetskontroll')
        ->assertSee('Externa kopplingar')
        ->assertSee('Inga störningar eller avbrott de senaste 90 dagarna');
});

it('shows a backup and a restore test only when the operator reports them', function () {
    $this->get('/status')->assertOk()->assertDontSee('Säkerhetskopiering')->assertDontSee('Återställningstest');

    Artisan::call('status:heartbeat', ['key' => 'backup']);
    Artisan::call('status:heartbeat', ['key' => 'restore-test']);

    $this->get('/status')->assertOk()->assertSee('Säkerhetskopiering')->assertSee('Återställningstest av säkerhetskopian')
        ->assertSee('2026-11-08 10:00');
});

it('records only the keys an operator script may report', function () {
    expect(Artisan::call('status:heartbeat', ['key' => 'integrity']))->toBe(StatusHeartbeatCommand::INVALID)
        ->and(StatusHeartbeat::query()->count())->toBe(0);

    Artisan::call('status:heartbeat', ['key' => 'backup', '--failed' => true]);
    $row = StatusHeartbeat::query()->findOrFail('backup');

    expect($row->last_failure_at)->not->toBeNull()->and($row->last_success_at)->toBeNull();
});

it('marks jobs that did not run as planned, and a failed last run', function () {
    Heartbeats::record(Heartbeats::SCHEDULER, true, CarbonImmutable::now()->subMinutes(30));
    Heartbeats::record(Heartbeats::INTEGRITY, true, CarbonImmutable::now()->subHours(2));
    Heartbeats::record(Heartbeats::INTEGRITY, false);

    $snapshot = StatusSnapshot::get();
    $byKey = collect($snapshot['services'])->keyBy('key');

    expect($byKey['scheduler']['state'])->toBe('DEGRADED')
        ->and($byKey['integrity']['state'])->toBe('DEGRADED')
        ->and($byKey['integrity']['note'])->toContain('kunde inte genomföras')
        ->and($snapshot['overall'])->toBe('DEGRADED');

    $this->get('/status')->assertOk()->assertSee('Vi har en störning');

    $this->travel(5)->seconds();
    Heartbeats::record(Heartbeats::SCHEDULER, true);
    Heartbeats::record(Heartbeats::INTEGRITY, true);

    expect(StatusSnapshot::get()['overall'])->toBe('OK');
});

it('records heartbeats from the scheduler for the tracked commands only', function () {
    $schedule = app(Schedule::class);
    $webhooks = $schedule->command('connect:webhooks');
    $other = $schedule->command('chat:prune');
    $verify = $schedule->command('ledger:verify --all');

    $webhooks->exitCode = 0;
    $other->exitCode = 0;
    $verify->exitCode = 1;

    event(new ScheduledTaskFinished($webhooks, 0.1));
    event(new ScheduledTaskFinished($other, 0.1));
    event(new ScheduledTaskFinished($verify, 0.1));

    expect(StatusHeartbeat::query()->findOrFail('scheduler')->last_success_at)->not->toBeNull()
        ->and(StatusHeartbeat::query()->findOrFail('integrity')->last_failure_at)->not->toBeNull()
        ->and(StatusHeartbeat::query()->findOrFail('integrity')->last_success_at)->toBeNull()
        ->and(StatusHeartbeat::query()->pluck('key')->sort()->values()->all())->toBe(['integrity', 'scheduler']);

    event(new ScheduledTaskFailed($webhooks, new RuntimeException('x')));
    expect(StatusHeartbeat::query()->findOrFail('scheduler')->last_failure_at)->not->toBeNull();
});

it('knows which scheduled commands it tracks', function () {
    expect(Heartbeats::keyFor("'php' 'artisan' ledger:verify --all"))->toBe('integrity')
        ->and(Heartbeats::keyFor("'php' 'artisan' ledger:verify"))->toBeNull()
        ->and(Heartbeats::keyFor("'php' 'artisan' ledger:verify 556016-0680"))->toBeNull()
        ->and(Heartbeats::keyFor("'/usr/bin/php' 'artisan' peppol:sync"))->toBe('peppol')
        ->and(Heartbeats::keyFor("'php' 'artisan' bank:fetch"))->toBe('bank')
        ->and(Heartbeats::keyFor("'php' 'artisan' bank:remind-consent"))->toBeNull()
        ->and(Heartbeats::keyFor("'php' 'artisan' privacy:prune"))->toBeNull();
});

it('reports a stuck database queue as a disruption', function () {
    config(['queue.default' => 'database']);
    Schema::create('jobs', function (Blueprint $table) {
        $table->id();
        $table->string('queue');
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
    DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->subMinutes(20)->getTimestamp(), 'created_at' => now()->subMinutes(20)->getTimestamp()]);

    $queue = collect(StatusSnapshot::get()['services'])->firstWhere('key', 'queue');

    expect($queue['state'])->toBe('DEGRADED');

    StatusSnapshot::forget();
    DB::table('jobs')->delete();
    DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->subMinutes(90)->getTimestamp(), 'created_at' => now()->subMinutes(90)->getTimestamp()]);

    expect(collect(StatusSnapshot::get()['services'])->firstWhere('key', 'queue')['state'])->toBe('OUTAGE');
});

it('shows only whether an outside connection is set up, paused or healthy, never keys', function () {
    config([
        'services.skatteverket.oauth_client_id' => 'sv-client-id-9d3f',
        'services.skatteverket.oauth_client_secret' => 'sv-secret-7a1c',
        'services.skatteverket.api_client_id' => 'sv-api-id-2b8e',
        'services.skatteverket.api_client_secret' => 'sv-api-secret-4e6d',
    ]);

    $external = collect(StatusSnapshot::get()['external'])->keyBy('key');

    expect($external->keys()->all())->toBe(['peppol', 'bank', 'skatteverket'])
        ->and($external['skatteverket']['note'])->toContain('Inställd');

    $html = $this->get('/status')->assertOk()->getContent();

    expect($html)->not->toContain('sv-client-id-9d3f')
        ->and($html)->not->toContain('sv-secret-7a1c')
        ->and($html)->not->toContain('sv-api-id-2b8e')
        ->and($html)->not->toContain('sv-api-secret-4e6d');
});

it('never shows a company, a person or an organisation number', function () {
    [$owner, $company] = Bookkeeping::company();

    $html = $this->get('/status')->assertOk()->getContent();

    expect($html)->not->toContain($company->name)
        ->and($html)->not->toContain((string) $company->org_number)
        ->and($html)->not->toContain($owner->email)
        ->and($html)->not->toContain($owner->name);
});

it('lets an open incident decide the overall state, and ignores information and resolved ones', function () {
    incident('Information om nästa vecka', IncidentSeverity::Info);
    expect(StatusSnapshot::get()['overall'])->toBe('OK');

    incident('Gammalt avbrott', IncidentSeverity::Outage, CarbonImmutable::now()->subDay());
    StatusSnapshot::forget();
    expect(StatusSnapshot::get()['overall'])->toBe('OK');

    incident('Underhåll i natt', IncidentSeverity::Maintenance);
    StatusSnapshot::forget();
    expect(StatusSnapshot::get()['overall'])->toBe('MAINTENANCE');

    incident('Långsamma utskick', IncidentSeverity::Degraded);
    StatusSnapshot::forget();
    expect(StatusSnapshot::get()['overall'])->toBe('DEGRADED');

    incident('Tjänsten ligger nere', IncidentSeverity::Outage);
    StatusSnapshot::forget();

    $this->get('/status')->assertOk()
        ->assertSee('Vi har ett avbrott')
        ->assertSee('Pågående händelser')
        ->assertSee('Tjänsten ligger nere')
        ->assertSee('Tidigare händelser')
        ->assertSee('Gammalt avbrott');
});

it('keeps resolved incidents for ninety days with their history and escapes the text', function () {
    $recent = incident('Löst för en vecka sedan', IncidentSeverity::Degraded, CarbonImmutable::now()->subDays(7));
    $recent->updates()->create(['status' => 'RESOLVED', 'body' => "Rad ett\n<script>alert(1)</script>", 'created_at' => now()->subDays(7)]);
    incident('För gammalt', IncidentSeverity::Degraded, CarbonImmutable::now()->subDays(120));

    $html = $this->get('/status')->assertOk()
        ->assertSee('Löst för en vecka sedan')
        ->assertDontSee('För gammalt')
        ->getContent();

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->and($html)->toContain('Rad ett<br />');
});

it('is cached for a minute and reads the same few rows whatever the number of incidents', function () {
    StatusSnapshot::get();

    DB::enableQueryLog();
    $this->get('/status')->assertOk();
    $cached = count(DB::getQueryLog());
    DB::flushQueryLog();
    StatusSnapshot::get();
    expect(DB::getQueryLog())->toBe([]);

    incident('Första', IncidentSeverity::Info);
    StatusSnapshot::forget();
    DB::flushQueryLog();
    StatusSnapshot::get();
    $few = count(DB::getQueryLog());

    foreach (range(1, 15) as $i) {
        incident("Händelse {$i}", IncidentSeverity::Info, CarbonImmutable::now()->subDays($i));
    }

    StatusSnapshot::forget();
    DB::flushQueryLog();
    $many = StatusSnapshot::get();
    $manyQueries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect(count($many['incidents']))->toBe(16)
        ->and($manyQueries)->toBe($few)
        ->and($cached)->toBeLessThan($few);

    $this->travel(61)->seconds();
    expect(Cache::has(StatusSnapshot::CACHE_KEY))->toBeFalse();
});

it('is linked from the public pages and the sitemap, and the policy only once it is published', function () {
    $this->get('/sakerhet')->assertOk()
        ->assertSee('href="'.route('status').'"', false)
        ->assertDontSee('href="'.route('security.policy').'"', false);

    $this->get('/sitemap.xml')->assertOk()->assertSee(route('status'), false)->assertDontSee(route('security.policy'), false);

    config(['app.landing.hosted' => true, 'app.landing.security_policy_published' => true]);

    $this->get('/sakerhet')->assertOk()->assertSee('href="'.route('security.policy').'"', false);
    $this->get('/sitemap.xml')->assertOk()->assertSee(route('security.policy'), false);
});

it('records a run that the overlap mutex skipped as nothing at all', function () {
    $event = app(Schedule::class)->command('connect:webhooks')->withoutOverlapping();
    $event->mutex->create($event);

    $event->run(app());

    expect($event->skippedBecauseOverlapping)->toBeTrue()->and($event->exitCode)->toBeNull();

    event(new ScheduledTaskFinished($event, 0.0));
    event(new ScheduledTaskFailed($event, new RuntimeException('x'), 0.0));

    expect(StatusHeartbeat::query()->count())->toBe(0);

    $event->mutex->forget($event);
});

it('shows the integrity check as ran or not ran, and keeps what it found for HQ', function () {
    $event = app(Schedule::class)->command('ledger:verify --all');
    $event->exitCode = VerifyLedgerCommand::EXIT_DEVIATIONS;

    event(new ScheduledTaskFinished($event, 0.1));
    event(new ScheduledTaskFailed($event, new RuntimeException('exit 3'), 0.1));

    $row = StatusHeartbeat::query()->findOrFail('integrity');
    $integrity = collect(StatusSnapshot::get()['services'])->firstWhere('key', 'integrity');

    expect($row->last_exit_code)->toBe(3)->and($row->last_completed_at)->not->toBeNull()->and($row->last_failure_at)->not->toBeNull()
        ->and($integrity['state'])->toBe('OK')
        ->and($integrity['note'])->toBe('Kontrollen har körts.')
        ->and($integrity['last'])->toBe('2026-11-08 10:00');

    expect(strtolower($this->get('/status')->assertOk()->getContent()))->not->toContain('avvikelse');

    $this->travel(40)->hours();
    StatusSnapshot::forget();

    expect(collect(StatusSnapshot::get()['services'])->firstWhere('key', 'integrity')['state'])->toBe('DEGRADED');

    $crash = app(Schedule::class)->command('ledger:verify --all');
    $crash->exitCode = 1;
    event(new ScheduledTaskFinished($crash, 0.1));
    StatusSnapshot::forget();

    expect(collect(StatusSnapshot::get()['services'])->firstWhere('key', 'integrity')['note'])->toBe('Senaste körningen kunde inte genomföras.');
});

it('reads the age of the oldest pending job from the queue driver for every driver that has one', function () {
    config(['queue.default' => 'redis']);
    $queue = Mockery::mock(Illuminate\Contracts\Queue\Queue::class);
    $queue->shouldReceive('creationTimeOfOldestPendingJob')->andReturn(now()->subMinutes(15)->getTimestamp() + 0.5);
    Queue::shouldReceive('connection')->with('redis')->andReturn($queue);

    expect(collect(StatusSnapshot::get()['services'])->firstWhere('key', 'queue')['state'])->toBe('DEGRADED');
});

it('only calls a connection disrupted for everyone when a quarter of at least three fail', function () {
    $share = new ReflectionMethod(StatusSnapshot::class, 'widespreadShare');

    expect($share->invoke(null, 2, 2))->toBeFalse()
        ->and($share->invoke(null, 3, 0))->toBeFalse()
        ->and($share->invoke(null, 8, 1))->toBeFalse()
        ->and($share->invoke(null, 8, 2))->toBeTrue()
        ->and($share->invoke(null, 3, 3))->toBeTrue();
});

it('does not read the audit log for the public page', function () {
    DB::enableQueryLog();
    StatusSnapshot::get();
    $sql = implode("\n", array_column(DB::getQueryLog(), 'query'));
    DB::disableQueryLog();

    expect($sql)->not->toContain('audit_logs');
});

it('clears the cache only when a heartbeat changes between healthy and failed', function () {
    Heartbeats::record(Heartbeats::SCHEDULER, true);
    StatusSnapshot::get();

    Heartbeats::record(Heartbeats::SCHEDULER, true);
    expect(Cache::has(StatusSnapshot::CACHE_KEY))->toBeTrue();

    $this->travel(5)->seconds();
    Heartbeats::record(Heartbeats::SCHEDULER, false);
    expect(Cache::has(StatusSnapshot::CACHE_KEY))->toBeFalse();
});

it('builds the page without the cache when the cache fails, and never caches the fallback', function () {
    Cache::shouldReceive('get')->with(StatusSnapshot::CACHE_KEY)->andThrow(new RuntimeException('cache down'));

    $snapshot = StatusSnapshot::get();

    expect($snapshot['overall'])->toBe('OK')->and(collect($snapshot['services'])->pluck('key')->all())->toContain('database');

    $fallback = StatusSnapshot::fallback();

    expect($fallback['overall'])->toBe('OUTAGE')->and($fallback['services'][1]['state'])->toBe('OUTAGE');
});

it('colours the states with variables that keep 4.5:1 against the page in both themes', function () {
    $luminance = function (string $hex): float {
        $channels = array_map(fn (string $part): float => hexdec($part) / 255, str_split(ltrim($hex, '#'), 2));
        [$r, $g, $b] = array_map(fn (float $c): float => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4, $channels);

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    };
    $contrast = function (string $foreground, string $background) use ($luminance): float {
        [$light, $dark] = [max($luminance($foreground), $luminance($background)), min($luminance($foreground), $luminance($background))];

        return ($light + 0.05) / ($dark + 0.05);
    };

    $html = $this->get('/status')->assertOk()->getContent();

    expect($html)->toContain('--st-ok:#4ADE80')->and($html)->toContain('prefers-color-scheme:dark')
        ->and($html)->not->toContain('style="color:#1B7F4B"');

    foreach (['#4ADE80', '#FBBF24', '#FB923C', '#F87171'] as $color) {
        expect($html)->toContain($color)->and($contrast($color, '#15171C'))->toBeGreaterThanOrEqual(4.5);
    }

    foreach (['#1B7F4B', '#8A5A00', '#B45309', '#B42318'] as $color) {
        expect($html)->toContain($color)->and($contrast($color, '#F7F5F0'))->toBeGreaterThanOrEqual(4.5);
    }
});

/** The state of the two connections on the public page, freshly built. */
function connectionStates(): array
{
    StatusSnapshot::forget();
    $external = collect(StatusSnapshot::get()['external'])->keyBy('key');

    return ['bank' => $external['bank']['state'], 'peppol' => $external['peppol']['state']];
}

it('decides a widespread bank failure from real connections: a quarter of at least three, active only', function () {
    Heartbeats::record(Heartbeats::BANK, true);
    $companies = collect(range(1, 8))->map(fn () => Bookkeeping::company())->values();
    $make = function (int $count, int $failing, string $status = 'ACTIVE', int $from = 0) use ($companies): array {
        $ids = [];

        foreach ($companies->slice($from, $count)->values() as $i => [$owner, $company]) {
            Banking::enable($company, $owner);
            $connection = Banking::connect($owner, $company);
            DB::table('bank_connections')->where('id', $connection->id)->update([
                'status' => $status,
                'consecutive_failures' => $i < $failing ? 3 : 0,
            ]);
            $ids[] = $connection->id;
        }

        return $ids;
    };
    // A scenario's connections are taken out of the count by revoking them (they have accounts hanging on them).
    $remove = fn (array $ids) => DB::table('bank_connections')->whereIn('id', $ids)->update(['status' => 'REVOKED', 'consecutive_failures' => 0]);

    $ids = $make(4, 1);
    expect(connectionStates()['bank'])->toBe('DEGRADED');
    $remove($ids);

    $ids = $make(5, 1);
    expect(connectionStates()['bank'])->toBe('OK');
    $remove($ids);

    $ids = $make(2, 2);
    expect(connectionStates()['bank'])->toBe('OK');
    $remove($ids);

    // Three active and healthy, three revoked and failing: the Active filter keeps the failing ones out.
    $healthy = $make(3, 0);
    $revoked = $make(3, 3, 'REVOKED', 3);
    expect(connectionStates()['bank'])->toBe('OK');
    $remove([...$healthy, ...$revoked]);
});

it('decides a widespread Peppol failure from real participants, leaving out unlinked ones and new links', function () {
    Heartbeats::record(Heartbeats::PEPPOL, true);
    $linked = collect(range(0, 5))->map(function (int $i): int {
        [$owner, $company] = Bookkeeping::company();

        return Peppol::ready($company, $owner, 'account-'.$i.'-'.uniqid())->id;
    })->values();
    $update = fn (array $ids, array $values) => DB::table('peppol_participants')->whereIn('id', $ids)->update($values);
    $healthy = ['last_polled_at' => now(), 'last_error' => null];

    // 1 failing of 4 linked (two participants without an account).
    $update($linked->all(), $healthy);
    $update($linked->slice(4)->all(), ['provider_account' => null]);
    $update([$linked[0]], ['last_error' => 'timeout']);
    expect(connectionStates()['peppol'])->toBe('DEGRADED');

    // 1 failing of 5.
    $update([$linked[4]], ['provider_account' => 'account-4-linked']);
    expect(connectionStates()['peppol'])->toBe('OK');

    // 2 failing of 2.
    $update($linked->slice(2)->all(), ['provider_account' => null]);
    $update($linked->take(2)->all(), ['last_error' => 'timeout']);
    expect(connectionStates()['peppol'])->toBe('OK');

    // Three linked and healthy, plus a failing participant without an account: it is not counted.
    $update($linked->take(3)->all(), [...$healthy, 'provider_account' => DB::raw("'acct-' || id")]);
    $update([$linked[3]], ['provider_account' => null, 'last_error' => 'timeout']);
    expect(connectionStates()['peppol'])->toBe('OK');

    // Never polled: a link under an hour old is fine, one older than an hour counts as failing.
    $update($linked->take(3)->all(), ['last_polled_at' => null, 'last_error' => null, 'created_at' => now()->subMinutes(10)]);
    expect(connectionStates()['peppol'])->toBe('OK');

    $update($linked->take(3)->all(), ['created_at' => now()->subHours(2)]);
    expect(connectionStates()['peppol'])->toBe('DEGRADED');
});

it('calls a measured queue that does not answer a disruption, and an unmeasured one unknown', function () {
    config(['queue.default' => 'database']);
    Queue::shouldReceive('connection')->with('database')->once()->andThrow(new RuntimeException('connection refused'));

    $queue = collect(StatusSnapshot::get()['services'])->firstWhere('key', 'queue');

    expect($queue['state'])->toBe('DEGRADED')->and($queue['note'])->toBe('Kön svarar inte.');

    StatusSnapshot::forget();
    config(['queue.default' => 'elsewhere', 'queue.connections.elsewhere' => ['driver' => 'sqs']]);
    Queue::shouldReceive('connection')->with('elsewhere')->andThrow(new RuntimeException('no such driver'));

    $unmeasured = collect(StatusSnapshot::get()['services'])->firstWhere('key', 'queue');

    expect($unmeasured['state'])->toBe('UNKNOWN')->and($unmeasured['note'])->toContain('mäts inte');
});
