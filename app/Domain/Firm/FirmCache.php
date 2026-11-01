<?php

declare(strict_types=1);

namespace App\Domain\Firm;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The cache behind Mina företag (ADR-028). An entry is per user and holds
 * plain arrays only. Its key contains a version per company, bumped on every
 * task change in that company, and a version per user, bumped by the
 * "Läs in på nytt" button: both are read with one Cache::many call, so a
 * colleague's new task shows at once without a query per company.
 */
final class FirmCache
{
    public const int TTL_SECONDS = 600;

    public static function bump(int $companyId): void
    {
        Cache::forever("firm:version:company:{$companyId}", Str::random(12));
    }

    public static function reload(User $user): void
    {
        Cache::forever("firm:version:user:{$user->id}", Str::random(12));
    }

    /**
     * @template T
     *
     * @param  list<int>  $companyIds  the user's own companies
     * @param  array<string, mixed>  $context  anything else the result depends on
     * @param  Closure(): T  $compute
     * @return T
     */
    public static function remember(User $user, string $part, array $companyIds, array $context, Closure $compute): mixed
    {
        $keys = ["firm:version:user:{$user->id}", ...array_map(fn (int $id): string => "firm:version:company:{$id}", $companyIds)];
        $versions = Cache::many($keys);
        $key = "firm:{$part}:{$user->id}:".sha1((string) json_encode([$companyIds, $versions, $context]));

        return Cache::remember($key, self::TTL_SECONDS, $compute);
    }
}
