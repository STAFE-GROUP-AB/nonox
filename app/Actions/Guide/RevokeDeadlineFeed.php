<?php

declare(strict_types=1);

namespace App\Actions\Guide;

use App\Models\Company;
use App\Models\DeadlineCalendarFeed;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Stops the user's calendar link for the company's dates at once (ADR-051,
 * stage 2). Needs no permission beyond being the user. Audited with ids only.
 */
final class RevokeDeadlineFeed
{
    /** @return bool whether there was a link to stop */
    public function __invoke(User $user, Company $company): bool
    {
        return CurrentCompany::run($company, fn (): bool => DB::transaction(function () use ($user, $company): bool {
            $feeds = DeadlineCalendarFeed::query()->where('user_id', $user->id)->whereNull('revoked_at')->lockForUpdate()->get();

            foreach ($feeds as $feed) {
                $feed->forceFill(['revoked_at' => CarbonImmutable::now()])->save();
                Audit::record('guide.calendar_feed_revoked', $feed, ['user_id' => $user->id], $company, $user);
            }

            return $feeds->isNotEmpty();
        }));
    }
}
