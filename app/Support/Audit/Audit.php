<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Writes to the behandlingshistorik (AGENTS.md §6.6): who did what, when, to
 * what, and the values before and after.
 */
final class Audit
{
    /** @var array<string, mixed> added to every entry written inside withContext() */
    private static array $context = [];

    /** True inside withoutRequest(): entries keep no IP address or user agent. */
    private static bool $withoutRequest = false;

    /**
     * Writes the entries of the callback without the request's IP address
     * and user agent: for pages used by outsiders, such as the public
     * signing page (ADR-046), where the visitor's address is not the
     * company's to keep in its seven-year log.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withoutRequest(callable $callback): mixed
    {
        $before = self::$withoutRequest;
        self::$withoutRequest = true;

        try {
            return $callback();
        } finally {
            self::$withoutRequest = $before;
        }
    }

    /** The request whose IP address and user agent an entry keeps, or null. */
    private static function request(): ?Request
    {
        return self::$withoutRequest || app()->runningInConsole() ? null : request();
    }

    /**
     * Adds fields to every entry the callback writes, for example
     * `via: operator_sync` when the operator sync writes through a company's
     * own Actions (ADR-044), so the log tells the sync from a person.
     *
     * @template T
     *
     * @param  array<string, mixed>  $context
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withContext(array $context, callable $callback): mixed
    {
        $before = self::$context;
        self::$context = [...$before, ...$context];

        try {
            return $callback();
        } finally {
            self::$context = $before;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function record(
        string $action,
        ?Model $subject = null,
        array $payload = [],
        Company|int|null $company = null,
        ?User $user = null,
    ): AuditLog {
        $request = self::request();
        $companyId = $company instanceof Company ? $company->id : ($company ?? CurrentCompany::id());

        return AuditLog::create([
            'company_id' => $companyId,
            'user_id' => $user->id ?? Auth::id(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject === null ? null : (string) $subject->getKey(),
            'payload' => [...$payload, ...self::$context],
            'ip_address' => $request?->ip(),
            'user_agent' => $request === null ? null : mb_substr((string) $request->userAgent(), 0, 1000),
        ]);
    }

    /**
     * An entry about the instance itself, not about a company (the waiting
     * list and /hq, ADR-038): company_id stays empty even when the user has
     * a company selected.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function instance(string $action, ?Model $subject = null, array $payload = [], ?User $user = null): AuditLog
    {
        $request = self::request();

        return AuditLog::create([
            'company_id' => null,
            'user_id' => $user->id ?? Auth::id(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject === null ? null : (string) $subject->getKey(),
            'payload' => $payload,
            'ip_address' => $request?->ip(),
            'user_agent' => $request === null ? null : mb_substr((string) $request->userAgent(), 0, 1000),
        ]);
    }

    /**
     * The attributes that changed, as {"before": {...}, "after": {...}}.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array{before: array<string, mixed>, after: array<string, mixed>}
     */
    public static function changes(array $before, array $after): array
    {
        $changed = array_keys(array_filter(
            $after,
            fn (mixed $value, string $key): bool => ($before[$key] ?? null) !== $value,
            ARRAY_FILTER_USE_BOTH,
        ));

        return [
            'before' => array_intersect_key($before, array_flip($changed)),
            'after' => array_intersect_key($after, array_flip($changed)),
        ];
    }
}
