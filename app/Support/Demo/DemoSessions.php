<?php

declare(strict_types=1);

namespace App\Support\Demo;

use App\Jobs\BuildDemoCompany;
use App\Models\User;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The visitor's side of a demo (ADR-077): ask for one, wait while it is
 * built, and be let in. The state of a build is kept in the demo database's
 * cache under a random token that only the asking browser knows, for an
 * hour. A token opens one demo, once. Nothing here is written to the main
 * database.
 */
final class DemoSessions
{
    public const string BUILDING = 'building';

    public const string READY = 'ready';

    public const string FAILED = 'failed';

    private const string BUILDING_COUNT = 'demo:building';

    private const int KEEP_SECONDS = 3600;

    /** The demo database's cache. */
    public static function store(): Repository
    {
        return Cache::store(DemoMode::CONNECTION);
    }

    /** Rate limits for the entry pages: counted in the demo database too, so the main one hears nothing of visitors. */
    public static function limiter(): RateLimiter
    {
        return new RateLimiter(self::store());
    }

    /**
     * Starts a build and returns the token to wait on. The token is bound to the
     * main-database session that started it ($sessionId): a link alone does not
     * claim the demo.
     *
     * @throws ValidationException when the installation is full, is being emptied, or the industry is unknown
     */
    public static function begin(string $industry, string $sessionId = ''): string
    {
        if (! array_key_exists($industry, DemoCatalog::industries())) {
            throw ValidationException::withMessages(['industry' => __('Välj en bransch i listan.')]);
        }

        // A start and a rebuild of the demo database never overlap (advisory lock); the rebuild waits for the start.
        return DemoDatabase::whileStarting(function () use ($industry, $sessionId): string {
            if (DemoDatabase::draining()) {
                throw ValidationException::withMessages(['industry' => __('Demon städas just nu och är öppen igen om en stund. Försök igen senare.')]);
            }

            if (self::live() + self::building() >= (int) config('nonox.demo.max_live')) {
                throw ValidationException::withMessages(['industry' => __('Demon är full just nu. Försök igen om en stund.')]);
            }

            // Builds are heavy: a number of them at once is capped apart from the number of live demos.
            if (self::building() >= (int) config('nonox.demo.max_building')) {
                throw ValidationException::withMessages(['industry' => __('Många bygger en demo just nu. Försök igen om en minut.')]);
            }

            $token = Str::random(40);

            self::store()->put(self::key($token), ['status' => self::BUILDING, 'industry' => $industry, 'session' => self::bind($sessionId)], self::KEEP_SECONDS);
            self::store()->add(self::BUILDING_COUNT, 0, self::KEEP_SECONDS);
            self::store()->increment(self::BUILDING_COUNT);

            BuildDemoCompany::dispatch($token, $industry)->onConnection(DemoMode::CONNECTION);

            return $token;
        });
    }

    /** @return ?array{status: string, industry: string, session?: string, user_id?: int} */
    public static function state(string $token): ?array
    {
        /** @var ?array{status: string, industry: string, session?: string, user_id?: int} $state */
        $state = self::store()->get(self::key($token));

        return is_array($state) ? $state : null;
    }

    public static function markReady(string $token, User $guest): void
    {
        $state = self::state($token);

        self::store()->put(self::key($token), ['status' => self::READY, 'industry' => $state['industry'] ?? '', 'session' => $state['session'] ?? '', 'user_id' => $guest->id], self::KEEP_SECONDS);
    }

    public static function markFailed(string $token): void
    {
        $state = self::state($token);

        self::store()->put(self::key($token), ['status' => self::FAILED, 'industry' => $state['industry'] ?? '', 'session' => $state['session'] ?? ''], self::KEEP_SECONDS);
    }

    public static function finishedBuilding(): void
    {
        self::store()->put(self::BUILDING_COUNT, max(0, (int) self::store()->get(self::BUILDING_COUNT) - 1), self::KEEP_SECONDS);
    }

    /**
     * The state of a build, for the browser (session) that started it only: the
     * token is bound to that session, so a link alone shows and claims nothing.
     *
     * @return ?array{status: string, industry: string, session?: string, user_id?: int}
     */
    public static function stateFor(string $token, string $sessionId): ?array
    {
        $state = self::state($token);

        return $state !== null && $sessionId !== '' && hash_equals((string) ($state['session'] ?? ''), self::bind($sessionId)) ? $state : null;
    }

    /**
     * A one-time permission to open the finished demo, given only to the session that started the build. It is
     * what /prova/oppna/{grant} takes; the build token itself opens nothing.
     */
    public static function grant(string $token, string $sessionId): ?string
    {
        $state = self::stateFor($token, $sessionId);

        if ($state === null || $state['status'] !== self::READY || ! isset($state['user_id'])) {
            return null;
        }

        self::store()->forget(self::key($token));

        return self::issueOpen($state['user_id']);
    }

    public static function issueOpen(int $userId): string
    {
        $grant = Str::random(40);

        self::store()->put(self::openKey($grant), ['user_id' => $userId], 300);

        return $grant;
    }

    public static function openExists(string $grant): bool
    {
        return self::store()->has(self::openKey($grant));
    }

    /** The guest a grant opens, once. Call it on the demo connection. */
    public static function claim(string $grant): ?User
    {
        /** @var ?array{user_id: int} $open */
        $open = self::store()->get(self::openKey($grant));

        if (! is_array($open)) {
            return null;
        }

        self::store()->forget(self::openKey($grant));
        $guest = User::query()->find($open['user_id']);

        if (! $guest instanceof User) {
            return null;
        }

        $ends = DemoMode::expiresAt($guest);

        return $ends !== null && $ends->isFuture() ? $guest : null;
    }

    /** Demos that have not yet ended. */
    public static function live(): int
    {
        return DB::connection(DemoMode::CONNECTION)->table('demo_sessions')->where('expires_at', '>', now())->count();
    }

    public static function building(): int
    {
        return max(0, (int) self::store()->get(self::BUILDING_COUNT));
    }

    private static function bind(string $sessionId): string
    {
        return hash('sha256', $sessionId.'|'.config('app.key'));
    }

    private static function openKey(string $grant): string
    {
        return 'demo:open:'.hash('sha256', $grant);
    }

    private static function key(string $token): string
    {
        return 'demo:build:'.hash('sha256', $token);
    }
}
