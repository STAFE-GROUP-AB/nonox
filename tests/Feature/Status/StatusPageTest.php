<?php

declare(strict_types=1);

use App\Console\Commands\StatusHeartbeatCommand;
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
use Illuminate\Support\Facades\Schema;
use Tests\Support\Bookkeeping;

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
        ->and($byKey['integrity']['note'])->toContain('avvikelser')
        ->and($snapshot['overall'])->toBe('DEGRADED');

    $this->get('/status')->assertOk()->assertSee('Vi har en störning');

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

it('is linked from the public pages and the sitemap', function () {
    $this->get('/sakerhet')->assertOk()
        ->assertSee('href="'.route('status').'"', false)
        ->assertSee('href="'.route('security.policy').'"', false);

    $this->get('/sitemap.xml')->assertOk()
        ->assertSee(route('status'), false)
        ->assertSee(route('security.policy'), false);
});
