<?php

declare(strict_types=1);

namespace App\Support\Demo;

use App\Models\User;
use App\Support\Instance\InstanceSettings;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Try without registering" (ADR-077, addendum 2026-11-08): demos live in a
 * database of their own, the connection "demo". Everything a guest does runs
 * with that connection as the default, so "is this a demo?" is one question:
 * is the default connection the demo connection?
 *
 * Laravel facts this relies on (read in vendor/laravel/framework 2026-11-08):
 * the default connection is config('database.default'), read at every call
 * (DatabaseManager::setDefaultConnection, Model::resolveConnection(null));
 * the session's database handler takes config('session.connection') when the
 * driver is created, and drivers are cached (Manager::forgetDrivers); the
 * cache store and the rate limiter singleton are built from config('cache.*')
 * once; the queue default is config('queue.default') at dispatch.
 */
final class DemoMode
{
    public const string CONNECTION = 'demo';

    /** A guest's session cookie. A different name from the main session's, so one can never be read as the other. */
    public const string COOKIE = 'nonox_demo_session';

    /** The reserved top-level domain (RFC 6761) of a guest's placeholder address. */
    public const string GUEST_DOMAIN = 'demo.invalid';

    /** Modules that reach outside NONOX; off for the demo database. */
    public const array BLOCKED_MODULES = ['ai', 'banking', 'peppol', 'connect', 'pos', 'inbox'];

    /** Settings that a guest request changes and the end of the request puts back. */
    private const array SWITCHED = [
        'database.default',
        'session.driver',
        'session.connection',
        'session.cookie',
        'session.table',
        'cache.default',
        'cache.limiter',
        'queue.default',
        'filesystems.disks.local.root',
        'filesystems.disks.uploads.root',
    ];

    private static int $seeding = 0;

    /** Set by UseDemoDatabase when it forgets the cookie of a demo that has ended; shown once, after the session has started. */
    private static bool $ended = false;

    /** The main connection's name, remembered at boot: setDefaultConnection() overwrites config('database.default'). */
    private static ?string $main = null;

    /** @var list<array<string, mixed>> the values before each switch, innermost last */
    private static array $stack = [];

    /** Whether the switch of this request (UseDemoDatabase) is on the stack. */
    private static bool $requestEntered = false;

    /** @var array<int, ?CarbonImmutable> */
    private static array $expiries = [];

    /** Whether /prova is open on this installation (the HQ switch, else hosted installations). */
    public static function enabled(): bool
    {
        return InstanceSettings::current()->demoEnabled();
    }

    /** The name of the main connection, as it was before any demo switch. */
    public static function mainConnection(): string
    {
        return self::$main ??= (string) config('database.default');
    }

    /** Called when the application boots, before anything can switch the default connection. */
    public static function rememberMainConnection(): void
    {
        self::$main = (string) config('database.default');
    }

    public static function onDemoDatabase(): bool
    {
        return DB::getDefaultConnection() === self::CONNECTION;
    }

    /** Whether work is going on in the demo database right now, outside the build. */
    public static function active(): bool
    {
        return self::onDemoDatabase() && ! self::isSeeding();
    }

    public static function markEnded(): void
    {
        self::$ended = true;
    }

    /** True once after markEnded(). */
    public static function pullEnded(): bool
    {
        $ended = self::$ended;
        self::$ended = false;

        return $ended;
    }

    public static function isSeeding(): bool
    {
        return self::$seeding > 0;
    }

    /**
     * Runs $callback as the demo builder: everything is switched to the demo
     * exactly as for a guest's request (see enter()), and nothing is blocked
     * inside it.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function building(callable $callback): mixed
    {
        self::$seeding++;

        try {
            return self::onDemoConnection($callback);
        } finally {
            self::$seeding--;
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function onDemoConnection(callable $callback): mixed
    {
        self::enter();

        try {
            return $callback();
        } finally {
            self::leave();
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function onMainConnection(callable $callback): mixed
    {
        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection(self::mainConnection());

        try {
            return $callback();
        } finally {
            DB::setDefaultConnection($previous);
        }
    }

    public static function moduleBlocked(string $key): bool
    {
        return self::active() && in_array($key, self::BLOCKED_MODULES, true);
    }

    /**
     * Makes this request a guest's: the demo database, a session in it under
     * its own cookie, its own cache store and queue, and its own file
     * directories. Called before the session starts and before the user is
     * resolved, so nothing is read from the main database first.
     */
    public static function activateRequest(): void
    {
        if (self::$requestEntered) {
            return;
        }

        self::enter();
        self::$requestEntered = true;
    }

    /** Puts the main settings back; a request ends here, and so does each request in a test. */
    public static function deactivateRequest(): void
    {
        self::$ended = false;

        if (! self::$requestEntered) {
            return;
        }

        self::$requestEntered = false;
        self::leave();
    }

    /**
     * The one switch to the demo: a guest's request, the build and a worker's
     * demo job all use it, so that nothing keyed by company id (the cache, the
     * queue, files) can reach the main installation's company with the same id.
     * It nests; every enter() has a leave().
     */
    public static function enter(): void
    {
        $saved = [];

        foreach (self::SWITCHED as $key) {
            $saved[$key] = config($key);
        }

        self::$stack[] = $saved;

        config([
            'database.default' => self::CONNECTION,
            'session.driver' => 'demo-database',
            'session.connection' => self::CONNECTION,
            'session.cookie' => self::COOKIE,
            'session.table' => 'sessions',
            'cache.default' => self::CONNECTION,
            'cache.limiter' => self::CONNECTION,
            'queue.default' => self::CONNECTION,
            'filesystems.disks.local.root' => storage_path('app/demo-private'),
            'filesystems.disks.uploads.root' => storage_path('app/demo-uploads'),
        ]);

        self::refreshServices();
    }

    public static function leave(): void
    {
        $saved = array_pop(self::$stack);

        if ($saved === null) {
            return;
        }

        config($saved);
        self::$expiries = [];

        self::refreshServices();
    }

    /**
     * The directories of the demo's files; nothing else is under them.
     *
     * @return list<string>
     */
    public static function fileRoots(): array
    {
        return [storage_path('app/demo-private'), storage_path('app/demo-uploads')];
    }

    /** The services that hold on to a connection or store once they are built. */
    private static function refreshServices(): void
    {
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        app()->forgetInstance(RateLimiter::class);
        app('filesystem')->forgetDisk(['local', 'uploads']);
        Auth::forgetGuards();
        CurrentCompany::forget();
    }

    /** When the demo of this guest ends, from the demo database. */
    public static function expiresAt(?User $user = null): ?CarbonImmutable
    {
        $user ??= Auth::user() instanceof User ? Auth::user() : null;

        if (! $user instanceof User || ! self::onDemoDatabase()) {
            return null;
        }

        return self::$expiries[$user->id] ??= self::lookupExpiry($user->id);
    }

    private static function lookupExpiry(int $userId): ?CarbonImmutable
    {
        $at = DB::table('demo_sessions')->where('user_id', $userId)->value('expires_at');

        return $at === null ? null : CarbonImmutable::parse((string) $at);
    }

    /**
     * @throws ValidationException when the work is in the demo database
     */
    public static function refuse(string $field = 'demo'): void
    {
        if (self::active()) {
            throw ValidationException::withMessages([$field => self::message()]);
        }
    }

    public static function message(): string
    {
        return __('Det här går inte i demon. Inget skickas utanför NONOX och inget sparas åt dig. Skapa ett konto för att använda det på riktigt.');
    }
}
