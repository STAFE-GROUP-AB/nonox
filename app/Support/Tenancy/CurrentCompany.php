<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * The company the current request or job works in (AGENTS.md §6.0).
 *
 * - Web: the signed-in user's current company, but only while they are still
 *   a member of it.
 * - Jobs and commands: nothing by default. Call set() or run() explicitly.
 * - API: TODO(§12): resolve from the company a Sanctum token is bound to.
 *
 * When no company is known, CompanyScope returns no rows at all.
 */
final class CurrentCompany
{
    public static function id(): ?int
    {
        $context = self::context();

        if ($context->explicit) {
            return $context->companyId;
        }

        $userId = Auth::id();

        if (! $context->resolved || $context->resolvedForUserId !== $userId) {
            $context->companyId = self::resolveFromUser();
            $context->resolvedForUserId = $userId;
            $context->resolved = true;
        }

        return $context->companyId;
    }

    public static function set(Company|int|null $company): void
    {
        $context = self::context();

        $context->companyId = $company instanceof Company ? $company->id : $company;
        $context->resolved = true;
        $context->explicit = true;
    }

    /**
     * Forget the company so it is resolved again, for example after the user
     * switches company or signs in.
     */
    public static function forget(): void
    {
        $context = self::context();

        $context->companyId = null;
        $context->resolved = false;
        $context->explicit = false;
        $context->resolvedForUserId = null;
    }

    /**
     * Run a callback in the given company, then restore the previous one.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function run(Company|int $company, callable $callback): mixed
    {
        $context = self::context();
        $previous = [$context->resolved, $context->explicit, $context->resolvedForUserId, $context->companyId];

        self::set($company);

        try {
            return $callback();
        } finally {
            [$context->resolved, $context->explicit, $context->resolvedForUserId, $context->companyId] = $previous;
        }
    }

    private static function resolveFromUser(): ?int
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return null;
        }

        return $user->currentCompany()?->id;
    }

    private static function context(): CompanyContext
    {
        return app(CompanyContext::class);
    }
}
