<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Deadlines\CompanyDeadlines;
use App\Domain\Deadlines\DeadlineCalendar;
use App\Models\Company;
use App\Models\DeadlineCalendarFeed;
use App\Models\User;
use App\Support\Tenancy\CompanyScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Mina datum behind a user's secret calendar link (ADR-051, stage 2): no
 * login, the token is the key, so calendar programs can subscribe. A
 * revoked or unknown token, an archived company, a company that has turned
 * the guide off, and a user who no longer belongs to the company all
 * answer 404, the same answer for each. Only the token's own company is
 * read. Throttled, with a stricter limit for links that lead nowhere; never
 * indexed or cached by a shared cache.
 */
final class DeadlineFeedController
{
    public const int MISSES_PER_MINUTE = 10;

    public function __invoke(Request $request, string $token, CompanyDeadlines $deadlines): Response
    {
        // The token decides the company, so it is looked up before any company is known.
        $feed = CompanyScope::bypass(fn (): ?DeadlineCalendarFeed => DeadlineCalendarFeed::query()->where('token_hash', DeadlineCalendarFeed::hash($token))->whereNull('revoked_at')->first());
        $company = $feed === null ? null : Company::query()->find($feed->company_id);
        $user = $feed === null ? null : User::query()->find($feed->user_id);

        $valid = $feed instanceof DeadlineCalendarFeed && $company instanceof Company && $user instanceof User
            && ! $company->isArchived() && $company->guide_enabled && $user->belongsToTeam($company);

        if (! $valid) {
            $misses = 'guide-calendar-miss:'.$request->ip();
            abort_if(RateLimiter::tooManyAttempts($misses, self::MISSES_PER_MINUTE), 429);
            RateLimiter::hit($misses, 60);
            abort(404);
        }

        // Written at most once an hour: a calendar that polls is not a stream of writes.
        if ($feed->last_used_at === null || $feed->last_used_at->lessThan(CarbonImmutable::now()->subHour())) {
            CompanyScope::bypass(fn () => $feed->forceFill(['last_used_at' => CarbonImmutable::now()])->saveQuietly());
        }

        $body = DeadlineCalendar::feed(
            __('Mina datum – :company', ['company' => $company->name]),
            $company->id,
            // Two weeks back as well, so a date that has just passed is still in the calendar.
            $deadlines->upcoming($company, CarbonImmutable::today()->subDays(14)),
            (string) parse_url(url('/'), PHP_URL_HOST),
        );

        return response($body, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="mina-datum.ics"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
        ]);
    }
}
