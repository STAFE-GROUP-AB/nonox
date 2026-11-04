<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\Calendar\CalendarRules;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\CalendarConnection;
use App\Modules\Crm\Models\CalendarFeed;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Whether the user's calendar link, or connected calendar, carries the
 * details (ADR-049): the subject, place, deal, text and people. Off, an
 * event says only "Möte: <företag>" and links to NONOX. The screen states
 * what turning it on means before it is saved.
 */
final class SetCalendarDetails
{
    public const string FEED = 'feed';

    public const string CONNECTION = 'connection';

    public function __invoke(User $user, Company $company, string $target, bool $includeDetails): void
    {
        Gate::forUser($user)->authorize('viewAny', [Activity::class, $company]);

        if ($includeDetails && ! CalendarRules::detailsAllowed($company)) {
            throw ValidationException::withMessages(['details' => __('Företaget tillåter inte detaljer i kalendrar.')]);
        }

        CurrentCompany::run($company, fn () => DB::transaction(function () use ($user, $company, $target, $includeDetails): void {
            $record = $target === self::CONNECTION
                ? CalendarConnection::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail()
                : CalendarFeed::query()->where('user_id', $user->id)->whereNull('revoked_at')->lockForUpdate()->firstOrFail();

            if ($record->include_details === $includeDetails) {
                return;
            }

            $record->forceFill(['include_details' => $includeDetails])->save();
            Audit::record('crm.calendar_details_set', $record, ['target' => $target, 'details' => $includeDetails], $company, $user);
        }));
    }
}
