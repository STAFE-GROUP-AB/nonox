<?php

declare(strict_types=1);

use App\Modules\Ai\AiModule;
use App\Modules\ModuleManager;
use App\Support\Audit\Audit;
use App\Support\Demo\DemoMode;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Bookkeeping;
use Tests\Support\Demo;

/*
 * Everything a build and a worker's demo job do is on the demo, and only on the
 * demo (ADR-077, addendum): the database, the cache, the queue, the files and the
 * mail, also when jobs nest and when a demo company has the same id as a real one.
 */

final class DemoProbeJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /** @var list<array<string, mixed>> */
    public static array $seen = [];

    public function handle(): void
    {
        self::$seen[] = self::state();
    }

    /** @return array<string, mixed> */
    public static function state(): array
    {
        return [
            'db' => DB::getDefaultConnection(),
            'cache' => config('cache.default'),
            'queue' => config('queue.default'),
            'local' => config('filesystems.disks.local.root'),
        ];
    }
}

beforeEach(function () {
    Demo::enable();
    DemoProbeJob::$seen = [];
});

afterEach(function () {
    foreach (DemoMode::fileRoots() as $root) {
        File::deleteDirectory($root);
    }
});

it('stays on the demo through a nested job, and never writes to the main database', function () {
    [$owner, $real] = Bookkeeping::company();
    $mainAudit = DB::table('audit_logs')->count();
    $demoState = ['db' => 'demo', 'cache' => 'demo', 'queue' => 'demo', 'local' => storage_path('app/demo-private')];

    DemoMode::building(function () use ($demoState, $real) {
        expect(DemoProbeJob::state())->toBe($demoState);

        // A job that runs in the build (a sync one, as the archive package's is) ...
        DemoProbeJob::dispatch();
        DemoProbeJob::dispatch();

        // ... and the build is still on the demo afterwards.
        expect(DemoProbeJob::state())->toBe($demoState);

        // Something written now, for a company that has the id of a real one, lands in the demo.
        Audit::instance('demo.probe', null, ['company' => $real->id]);
    });

    expect(DemoProbeJob::$seen)->toBe([$demoState, $demoState])
        ->and(DB::table('audit_logs')->count())->toBe($mainAudit)
        ->and(DB::connection('demo')->table('audit_logs')->where('action', 'demo.probe')->count())->toBe(1)
        ->and(DemoMode::onDemoDatabase())->toBeFalse()
        ->and(config('cache.default'))->not->toBe('demo')
        ->and(config('queue.default'))->not->toBe('demo');
});

it('runs a worker\'s demo job with the whole demo switch, and nested jobs leave only what they entered', function () {
    $event = fn (string $class, string $connection, object $job) => new $class($connection, $job);
    $job = fn () => Mockery::mock(Job::class)->shouldReceive('payload')->andReturn([])->getMock();

    $outer = $job();
    $inner = $job();
    $other = $job();

    event($event(JobProcessing::class, 'demo', $outer));
    $outerState = DemoProbeJob::state();
    event($event(JobProcessing::class, 'demo', $inner));
    event($event(JobProcessed::class, 'demo', $inner));
    $afterInner = DemoProbeJob::state();

    // A job of the main queue, finishing in the middle, does not take the demo's switch away either.
    event($event(JobProcessing::class, 'sync', $other));
    event($event(JobProcessed::class, 'sync', $other));
    $afterOther = DemoProbeJob::state();

    event($event(JobProcessed::class, 'demo', $outer));

    expect($outerState['db'])->toBe('demo')
        ->and($afterInner)->toBe($outerState)
        ->and($afterOther)->toBe($outerState)
        ->and(DemoProbeJob::state()['db'])->toBe(config('database.default'))
        ->and(DemoProbeJob::state()['cache'])->not->toBe('demo');
});

it('does not share caches with a real company that has the same id', function () {
    [$owner, $real] = Bookkeeping::company();
    $modules = app(ModuleManager::class);
    $modules->enable(AiModule::KEY, $real, $owner);

    // A demo company with the real company's id.
    DB::connection('demo')->statement("select setval(pg_get_serial_sequence('teams', 'id'), ?)", [$real->id - 1]);
    [$guest, $demo] = Demo::company();

    expect($demo->id)->toBe($real->id)
        ->and($modules->isEnabled(AiModule::KEY, $real))->toBeTrue();

    DemoMode::building(function () use ($modules, $demo) {
        // Warms the demo's cache for the id; the demo company has no modules.
        expect($modules->isEnabled(AiModule::KEY, $demo))->toBeFalse();
        Cache::put('company:'.$demo->id.':probe', 'demo');
    });

    // The real company's cache and modules are what they were.
    expect($modules->isEnabled(AiModule::KEY, $real))->toBeTrue()
        ->and(Cache::get('company:'.$real->id.':probe'))->toBeNull();

    // And in the demo database every key of the demo's cache has its own prefix.
    $keys = DB::connection('demo')->table('cache')->pluck('key');

    expect($keys)->not->toBeEmpty();
    foreach ($keys as $key) {
        expect($key)->toStartWith('nonox_demo_');
    }
});

it('sends no mail while the demo is built, and the jobs of a build stay on the demo queue', function () {
    $count = fn (): int => count(app('mailer')->getSymfonyTransport()->messages());
    $before = $count();

    DemoMode::building(function () use ($count, $before) {
        Mail::raw('Hej', fn ($message) => $message->to('kund@example.test')->subject('Under bygget'));

        expect($count())->toBe($before)
            ->and(config('queue.default'))->toBe('demo');
    });

    Mail::raw('Hej', fn ($message) => $message->to('kund@example.test')->subject('Efter'));
    expect($count())->toBe($before + 1);
});

it('keeps the demo\'s files in directories of their own', function () {
    $main = config('filesystems.disks.local.root');

    DemoMode::onDemoConnection(function () {
        expect(config('filesystems.disks.local.root'))->toBe(storage_path('app/demo-private'));
        Storage::disk('local')->put('archives/1/Arkiv_5599999999_2026.zip', 'demo');
        expect(Storage::disk('local')->exists('archives/1/Arkiv_5599999999_2026.zip'))->toBeTrue();
    });

    expect(config('filesystems.disks.local.root'))->toBe($main)
        ->and(Storage::disk('local')->exists('archives/1/Arkiv_5599999999_2026.zip'))->toBeFalse();
});

it('stores no IP address and no browser name for a guest', function () {
    [$guest] = Demo::company();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5', 'HTTP_USER_AGENT' => 'ProbeBrowser/1.0'])
        ->get(route('demo.open', Demo::token($guest)))->assertRedirect(route('dashboard'));

    $session = DB::connection('demo')->table('sessions')->first();

    expect($session->ip_address)->toBeNull()
        ->and($session->user_agent)->toBeNull();

    DemoMode::onDemoConnection(fn () => Audit::instance('demo.ip_probe', null, []));

    expect(DB::connection('demo')->table('audit_logs')->whereNotNull('ip_address')->count())->toBe(0)
        ->and(DB::connection('demo')->table('audit_logs')->whereNotNull('user_agent')->count())->toBe(0);
});

it('has no sync override of the queue left in the demo builder', function () {
    expect(file_get_contents(base_path('database/seeders/Demo/Extras/WrapUpExtras.php')))->not->toContain('queue.default');
});
