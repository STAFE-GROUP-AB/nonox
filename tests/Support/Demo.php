<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Company;
use App\Models\User;
use App\Support\Demo\DemoMode;
use App\Support\Demo\DemoSessions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Helpers for the demo database tests (ADR-077): a cheap demo company made
 * through the factories on the demo connection, and a guest session.
 */
final class Demo
{
    /**
     * A guest with a company in the demo database, flagged as a test company,
     * with a demo_sessions row. Not built by the demo builder, so it is cheap.
     *
     * @return array{0: User, 1: Company}
     */
    public static function company(?CarbonImmutable $expires = null): array
    {
        return DemoMode::building(function () use ($expires): array {
            [$owner, $company] = Bookkeeping::company();

            $owner->forceFill(['email' => 'gast-'.Str::lower(Str::random(12)).'@'.DemoMode::GUEST_DOMAIN])->save();
            $company->forceFill(['is_test_company' => true])->save();

            DB::table('demo_sessions')->insert([
                'user_id' => $owner->id,
                'company_id' => $company->id,
                'expires_at' => $expires ?? CarbonImmutable::now()->addHours(2),
            ]);

            return [$owner->fresh(), $company->fresh()];
        });
    }

    /**
     * Makes a token for the guest that opens the demo, the way a finished build does.
     */
    public static function token(User $guest): string
    {
        $token = Str::random(40);

        DemoSessions::markReady($token, $guest);

        return $token;
    }

    /** The session id the demo database holds for the browser that opened the demo. */
    public static function sessionId(): string
    {
        return (string) DB::connection(DemoMode::CONNECTION)->table('sessions')->orderByDesc('last_activity')->value('id');
    }
}
