<?php

declare(strict_types=1);

namespace App\Support\Demo;

use App\Models\Company;
use App\Models\User;
use App\Support\Instance\InstanceSettings;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * "Try without registering" (ADR-077). A demo company is an ordinary tenant
 * flagged with demo_expires_at (and is_test_company); its owner is a guest
 * user flagged the same way. This class answers the one question the
 * safeguards ask: is the work going on right now in a demo?
 *
 * While a demo is being built (seeding) nothing is blocked, since the build
 * uses the same Actions as the app, some of which touch what a visitor may
 * not touch.
 */
final class DemoMode
{
    /** Modules that reach outside NONOX; off for a demo company while a visitor is in it. */
    public const array BLOCKED_MODULES = ['ai', 'banking', 'peppol', 'connect', 'pos', 'inbox'];

    /** The reserved top-level domain (RFC 6761) of a guest's placeholder address. */
    public const string GUEST_DOMAIN = 'demo.invalid';

    private static int $seeding = 0;

    /** Whether /prova is open on this installation (the HQ switch, else hosted installations). */
    public static function enabled(): bool
    {
        return InstanceSettings::current()->demoEnabled();
    }

    /**
     * Runs $callback as the demo builder: nothing is blocked inside it.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function seeding(callable $callback): mixed
    {
        self::$seeding++;

        try {
            return $callback();
        } finally {
            self::$seeding--;
        }
    }

    public static function isSeeding(): bool
    {
        return self::$seeding > 0;
    }

    /** Whether work is going on in a demo company right now, outside the build. */
    public static function active(): bool
    {
        if (self::isSeeding()) {
            return false;
        }

        $id = CurrentCompany::id();

        return $id !== null && self::isDemoCompany($id);
    }

    /** Whether the user (the signed-in one by default) is a guest, outside the build. */
    public static function guest(?User $user = null): bool
    {
        $user ??= Auth::user() instanceof User ? Auth::user() : null;

        return ! self::isSeeding() && $user?->isDemoGuest() === true;
    }

    public static function isDemoCompany(Company|int $company): bool
    {
        if ($company instanceof Company) {
            return $company->demo_expires_at !== null;
        }

        return CompanyScope::bypass(
            fn (): bool => Company::query()->whereKey($company)->whereNotNull('demo_expires_at')->exists(),
        );
    }

    public static function moduleBlocked(string $key, ?int $companyId): bool
    {
        return ! self::isSeeding()
            && $companyId !== null
            && in_array($key, self::BLOCKED_MODULES, true)
            && self::isDemoCompany($companyId);
    }

    /**
     * @throws ValidationException when the work is in a demo company or by a guest
     */
    public static function refuse(string $field = 'demo', ?Company $company = null, ?User $user = null): void
    {
        $inDemo = $company instanceof Company ? self::isDemoCompany($company) && ! self::isSeeding() : self::active();

        if ($inDemo || self::guest($user)) {
            throw ValidationException::withMessages([$field => self::message()]);
        }
    }

    public static function message(): string
    {
        return __('Det här går inte i demon. Inget skickas utanför NONOX och inget sparas åt dig. Skapa ett konto för att använda det på riktigt.');
    }
}
