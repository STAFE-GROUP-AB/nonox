<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\Models\CalendarFeed;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Stops the user's CRM calendar link in this company at once (ADR-049).
 * Needs no permission beyond being the user: someone who lost crm:view can
 * still switch off their own link. Also run for a member who leaves the
 * company (no $by). Audited with ids only.
 */
final class RevokeCalendarFeed
{
    /** @return bool whether there was a link to stop */
    public function __invoke(User $user, Company $company, ?User $by = null): bool
    {
        return CurrentCompany::run($company, fn (): bool => DB::transaction(function () use ($user, $company, $by): bool {
            $feeds = CalendarFeed::query()->where('user_id', $user->id)->whereNull('revoked_at')->lockForUpdate()->get();

            foreach ($feeds as $feed) {
                $feed->forceFill(['revoked_at' => CarbonImmutable::now()])->save();
                Audit::record('crm.calendar_feed_revoked', $feed, ['user_id' => $user->id], $company, $by ?? $user);
            }

            return $feeds->isNotEmpty();
        }));
    }
}
