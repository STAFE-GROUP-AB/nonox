<?php

declare(strict_types=1);

use App\Support\Demo\DemoDatabase;
use App\Support\Demo\DemoMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Bookkeeping;
use Tests\Support\Demo;

/*
 * The one database NONOX may empty (ADR-077, addendum 2026-11-08). These tests
 * really drop and build the demo database's schema, so they do not run in a
 * transaction on it. The demo database is the only thing they touch: the main
 * database's own counts are compared before and after.
 */

beforeEach(fn () => Demo::enable());

afterEach(function () {
    // Leave the demo database empty for whoever runs next.
    DemoDatabase::rebuild();
    File::delete(storage_path('framework/demo-built-at'));
    DemoDatabase::stopDraining();
});

it('builds the demo database again when its demos have expired and none is live, and touches nothing else', function () {
    [$realOwner, $real] = Bookkeeping::company();
    Bookkeeping::post($realOwner, $real);

    [$guest, $company] = Demo::company(CarbonImmutable::now()->subHour());
    DemoMode::building(fn () => Bookkeeping::post($guest, $company));

    $demo = DB::connection(DemoMode::CONNECTION);
    $main = DB::connection(DemoMode::mainConnection());

    // A file of the demo and one of the main installation, under the same relative path.
    DemoMode::onDemoConnection(fn () => Storage::disk('local')->put('archives/1/same.zip', 'demo'));
    Storage::disk('local')->put('archives/1/same.zip', 'main');

    $mainBefore = [$main->table('users')->count(), $main->table('teams')->count(), $main->table('vouchers')->count(), $main->table('migrations')->count()];

    expect($demo->table('vouchers')->count())->toBe(1)
        ->and($demo->table('users')->count())->toBeGreaterThan(0);

    $this->artisan('demo:prune')->expectsOutputToContain('byggd på nytt')->assertSuccessful();

    // The demo database is empty again, with the whole schema, the same triggers and the demo-only tables.
    expect($demo->table('users')->count())->toBe(0)
        ->and($demo->table('teams')->count())->toBe(0)
        ->and($demo->table('vouchers')->count())->toBe(0)
        ->and($demo->table('demo_sessions')->count())->toBe(0)
        ->and($demo->table('migrations')->count())->toBe($mainBefore[3])
        ->and(count($demo->getSchemaBuilder()->getTableListing()))->toBe(count($main->getSchemaBuilder()->getTableListing()) + 4)
        ->and($demo->selectOne('select count(*) as n from pg_trigger where not tgisinternal')->n)->toBe($main->selectOne('select count(*) as n from pg_trigger where not tgisinternal')->n);

    $functions = fn ($connection): array => collect($connection->select("select p.proname, pg_get_functiondef(p.oid) as d from pg_proc p join pg_namespace n on n.oid = p.pronamespace where n.nspname = 'public' and p.prokind = 'f' order by p.proname"))->pluck('d', 'proname')->all();

    expect($functions($demo))->toBe($functions($main));

    // The demo's file is gone, the main one is not, and nothing else was deleted.
    expect(DemoMode::onDemoConnection(fn () => Storage::disk('local')->exists('archives/1/same.zip')))->toBeFalse()
        ->and(Storage::disk('local')->get('archives/1/same.zip'))->toBe('main');
    Storage::disk('local')->delete('archives/1/same.zip');

    // The main database has all of its rows.
    expect([$main->table('users')->count(), $main->table('teams')->count(), $main->table('vouchers')->count(), $main->table('migrations')->count()])->toBe($mainBefore);

    // A second run has nothing to do, and the rebuilt database takes a demo again.
    $this->artisan('demo:prune')->expectsOutputToContain('Inget att bygga om')->assertSuccessful();
    [$again] = Demo::company();

    expect($demo->table('users')->where('id', $again->id)->exists())->toBeTrue();
});

it('rebuilds a database that is waiting to be emptied once its last live demo has ended', function () {
    [, $company] = Demo::company(CarbonImmutable::now()->addMinutes(5));
    File::put(storage_path('framework/demo-built-at'), (string) CarbonImmutable::now()->subHours(30)->getTimestamp());

    $this->artisan('demo:prune')->assertSuccessful();
    expect(DemoDatabase::draining())->toBeTrue()
        ->and(DB::connection('demo')->table('teams')->count())->toBe(1);

    DB::connection('demo')->table('demo_sessions')->where('company_id', $company->id)->update(['expires_at' => CarbonImmutable::now()->subMinute()]);

    $this->artisan('demo:prune')->expectsOutputToContain('byggd på nytt')->assertSuccessful();

    expect(DemoDatabase::draining())->toBeFalse()
        ->and(DB::connection('demo')->table('teams')->count())->toBe(0);
});
