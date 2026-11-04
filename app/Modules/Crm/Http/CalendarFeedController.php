<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http;

use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\Calendar\CalendarDocument;
use App\Modules\Crm\Calendar\CalendarEntries;
use App\Modules\Crm\Calendar\CalendarRules;
use App\Modules\Crm\CrmModule;
use App\Modules\Crm\Models\CalendarFeed;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CompanyScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;

/**
 * The CRM calendar behind a user's secret link (ADR-049): no login, the
 * token is the key, so calendar programs can subscribe. A revoked or
 * unknown token, an archived company, CRM switched off, and a user who is
 * no longer a member with crm:view all answer 404, the same answer for
 * each, so the link reveals nothing. Only the token's own user and company
 * are ever read. Throttled; never indexed or cached by a shared cache.
 */
final class CalendarFeedController
{
    public function __invoke(string $token, ModuleManager $modules): Response
    {
        // The token decides the company, so it is looked up before any company is known.
        $feed = CompanyScope::bypass(fn (): ?CalendarFeed => CalendarFeed::query()->where('token_hash', CalendarFeed::hash($token))->whereNull('revoked_at')->first());
        $company = $feed === null ? null : Company::query()->find($feed->company_id);
        $user = $feed === null ? null : User::query()->find($feed->user_id);

        abort_unless($feed instanceof CalendarFeed && $company instanceof Company && $user instanceof User && ! $company->isArchived(), 404);
        abort_unless($modules->isEnabled(CrmModule::KEY, $company) && $user->hasTeamPermission($company, 'crm:view'), 404);

        // Written at most once an hour: a calendar that polls every few minutes is not a stream of writes.
        if ($feed->last_used_at === null || $feed->last_used_at->lessThan(CarbonImmutable::now()->subHour())) {
            CompanyScope::bypass(fn () => $feed->forceFill(['last_used_at' => CarbonImmutable::now()])->saveQuietly());
        }

        $body = CalendarDocument::feed(
            __('NONOX CRM – :company', ['company' => $company->name]),
            CalendarEntries::forUser($company, $user, $feed->include_details && CalendarRules::detailsAllowed($company)),
        );

        return response($body, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="crm.ics"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
        ]);
    }
}
