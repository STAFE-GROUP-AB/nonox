<?php

declare(strict_types=1);

namespace App\Domain\Overview;

use App\Models\AuditLog;
use App\Models\Company;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The cache behind Översikt (ADR-027). Every entry of a company carries the
 * company's version in its key; changing the version makes all of them
 * stale at once, for every role and user, without knowing their keys.
 *
 * The version changes after a transaction that recorded something in the
 * audit log or saved a model a todo provider depends on commits, and when
 * someone presses the reload arrow. Entries also expire after TTL_MINUTES,
 * and the date is part of every key, so deadlines move with the day.
 */
final class OverviewCache
{
    public const int TTL_MINUTES = 10;

    /** Audit actions that change nothing on the overview. */
    private const array QUIET_ACTIONS = ['auth.', 'company.switched'];

    /** @var array<int, true> companies with a version change waiting for the transaction to commit */
    private static array $pending = [];

    /**
     * The value of the entry, computed and stored if it is missing or stale.
     *
     * @template TValue
     *
     * @param  Closure(): TValue  $compute
     * @return TValue
     */
    public static function remember(int $companyId, string $name, Closure $compute): mixed
    {
        return Cache::remember(self::key($companyId, $name), now()->addMinutes(self::TTL_MINUTES), $compute);
    }

    /** Makes every entry of the company stale now. */
    public static function forget(int $companyId): void
    {
        Cache::forever(self::versionKey($companyId), Str::random(12));
    }

    /**
     * Makes the company's entries stale once the current transaction
     * commits, or now when there is none. A transaction that writes a
     * thousand vouchers changes the version once; one that is rolled back
     * changes nothing.
     */
    public static function forgetAfterCommit(int $companyId): void
    {
        if (isset(self::$pending[$companyId])) {
            return;
        }

        self::$pending[$companyId] = true;

        DB::afterRollBack(function () use ($companyId): void {
            unset(self::$pending[$companyId]);
        });

        DB::afterCommit(function () use ($companyId): void {
            unset(self::$pending[$companyId]);
            self::forget($companyId);
        });
    }

    /**
     * Listens for the changes that make a company's overview stale: every
     * audit log entry except sign-ins and switches, and saves and deletes of
     * the given models.
     *
     * @param  list<class-string<Model>>  $models
     */
    public static function listen(array $models): void
    {
        AuditLog::created(function (AuditLog $log): void {
            if ($log->company_id === null || Str::startsWith($log->action, self::QUIET_ACTIONS)) {
                return;
            }

            self::forgetAfterCommit((int) $log->company_id);
        });

        foreach (array_unique($models) as $model) {
            $model::saved(fn (Model $changed) => self::changed($changed));
            $model::deleted(fn (Model $changed) => self::changed($changed));
        }
    }

    private static function changed(Model $model): void
    {
        $companyId = $model instanceof Company ? $model->getKey() : $model->getAttribute('company_id');

        if (is_numeric($companyId)) {
            self::forgetAfterCommit((int) $companyId);
        }
    }

    private static function key(int $companyId, string $name): string
    {
        $version = Cache::get(self::versionKey($companyId), '0');

        return "overview:{$companyId}:".(is_scalar($version) ? (string) $version : '0').":{$name}";
    }

    private static function versionKey(int $companyId): string
    {
        return "overview:{$companyId}:version";
    }
}
