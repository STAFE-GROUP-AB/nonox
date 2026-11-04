<?php

declare(strict_types=1);

namespace App\Actions\Guide;

use App\Models\Company;
use App\Models\DeadlineCalendarFeed;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Makes the user's secret calendar link for the company's dates on Mina
 * datum (ADR-051, stage 2): a random token of which only the SHA-256 hash
 * is kept, so the link is shown once. A new link replaces the old one,
 * which stops working at once. Any member of the company may have one.
 * Returns the feed's full URL.
 */
final class IssueDeadlineFeed
{
    public function __invoke(User $user, Company $company): string
    {
        if (! $user->belongsToTeam($company)) {
            throw new AuthorizationException;
        }

        $token = Str::random(DeadlineCalendarFeed::TOKEN_LENGTH);

        // One active link per user and company (a partial unique index): a link made at the same moment wins once, this one replaces it.
        retry(2, fn () => CurrentCompany::run($company, fn () => DB::transaction(function () use ($user, $company, $token): void {
            $replaced = DeadlineCalendarFeed::query()->where('user_id', $user->id)->whereNull('revoked_at')->lockForUpdate()->update(['revoked_at' => CarbonImmutable::now()]);
            $feed = DeadlineCalendarFeed::create(['user_id' => $user->id, 'token_hash' => DeadlineCalendarFeed::hash($token)]);

            Audit::record('guide.calendar_feed_issued', $feed, ['replaced' => $replaced], $company, $user);
        })), 0, fn (Throwable $exception): bool => $exception instanceof UniqueConstraintViolationException);

        return route('guide.dates.feed', ['token' => $token]);
    }
}
