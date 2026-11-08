<?php

declare(strict_types=1);

namespace App\Support\Demo;

use App\Jobs\BuildDemoCompany;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The visitor's side of a demo (ADR-077): ask for one, wait while it is
 * built, and be let in. The state of a build lives in the cache under a
 * random token that only the asking browser's session knows, for an hour.
 * A token opens one demo, once.
 */
final class DemoSessions
{
    public const string BUILDING = 'building';

    public const string READY = 'ready';

    public const string FAILED = 'failed';

    private const string BUILDING_COUNT = 'demo:building';

    private const int KEEP_SECONDS = 3600;

    /**
     * Starts a build and returns the token to wait on.
     *
     * @throws ValidationException when the installation is full or the industry is unknown
     */
    public static function begin(string $industry): string
    {
        if (! array_key_exists($industry, DemoCatalog::industries())) {
            throw ValidationException::withMessages(['industry' => __('Välj en bransch i listan.')]);
        }

        if (self::live() + self::building() >= (int) config('nonox.demo.max_live')) {
            throw ValidationException::withMessages(['industry' => __('Demon är full just nu. Försök igen om en stund.')]);
        }

        $token = Str::random(40);

        Cache::put(self::key($token), ['status' => self::BUILDING, 'industry' => $industry], self::KEEP_SECONDS);
        Cache::increment(self::BUILDING_COUNT);
        Cache::put(self::BUILDING_COUNT, max(0, (int) Cache::get(self::BUILDING_COUNT)), self::KEEP_SECONDS);

        BuildDemoCompany::dispatch($token, $industry);

        return $token;
    }

    /** @return ?array{status: string, industry: string, user_id?: int} */
    public static function state(string $token): ?array
    {
        /** @var ?array{status: string, industry: string, user_id?: int} $state */
        $state = Cache::get(self::key($token));

        return is_array($state) ? $state : null;
    }

    public static function markReady(string $token, User $guest): void
    {
        $state = self::state($token);

        Cache::put(self::key($token), ['status' => self::READY, 'industry' => $state['industry'] ?? '', 'user_id' => $guest->id], self::KEEP_SECONDS);
    }

    public static function markFailed(string $token): void
    {
        $state = self::state($token);

        Cache::put(self::key($token), ['status' => self::FAILED, 'industry' => $state['industry'] ?? ''], self::KEEP_SECONDS);
    }

    public static function finishedBuilding(): void
    {
        Cache::put(self::BUILDING_COUNT, max(0, (int) Cache::get(self::BUILDING_COUNT) - 1), self::KEEP_SECONDS);
    }

    /** The guest a finished build made, once; the token cannot open it again. */
    public static function claim(string $token): ?User
    {
        $state = self::state($token);

        if ($state === null || $state['status'] !== self::READY || ! isset($state['user_id'])) {
            return null;
        }

        Cache::forget(self::key($token));
        $guest = User::query()->find($state['user_id']);

        return $guest instanceof User && $guest->isDemoGuest() && $guest->demo_expires_at?->isFuture() ? $guest : null;
    }

    /** Demo companies that have not yet expired. */
    public static function live(): int
    {
        return CompanyScope::bypass(fn (): int => Company::query()->where('demo_expires_at', '>', now())->count());
    }

    public static function building(): int
    {
        return max(0, (int) Cache::get(self::BUILDING_COUNT));
    }

    private static function key(string $token): string
    {
        return 'demo:build:'.hash('sha256', $token);
    }
}
