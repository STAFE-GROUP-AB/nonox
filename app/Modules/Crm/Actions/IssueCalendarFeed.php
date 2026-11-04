<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\CalendarFeed;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Makes the user's secret calendar link for the CRM in this company
 * (ADR-049): a random token of which only the SHA-256 hash is kept, so the
 * link is shown once. A new link replaces the old one, which stops working
 * at once. The details choice starts off unless asked for. Returns the
 * feed's full URL.
 */
final class IssueCalendarFeed
{
    public function __invoke(User $user, Company $company, bool $includeDetails = false): string
    {
        Gate::forUser($user)->authorize('viewAny', [Activity::class, $company]);

        $token = Str::random(CalendarFeed::TOKEN_LENGTH);

        CurrentCompany::run($company, fn () => DB::transaction(function () use ($user, $company, $token, $includeDetails): void {
            $replaced = CalendarFeed::query()->where('user_id', $user->id)->whereNull('revoked_at')->lockForUpdate()->update(['revoked_at' => CarbonImmutable::now()]);

            $feed = CalendarFeed::create([
                'user_id' => $user->id,
                'token_hash' => CalendarFeed::hash($token),
                'include_details' => $includeDetails,
            ]);

            Audit::record('crm.calendar_feed_issued', $feed, ['replaced' => $replaced, 'details' => $includeDetails], $company, $user);
        }));

        return route('crm.calendar.feed', ['token' => $token]);
    }
}
