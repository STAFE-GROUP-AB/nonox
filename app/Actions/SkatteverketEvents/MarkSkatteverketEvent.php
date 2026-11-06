<?php

declare(strict_types=1);

namespace App\Actions\SkatteverketEvents;

use App\Models\Company;
use App\Models\SkatteverketEvent;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Marks one of Skatteverket's customer events handled in NONOX, or not
 * handled again (ADR-054 stage 4). Only the person who fetched the event
 * sees and marks it; anyone else's event is not found. It says only that the
 * person has dealt with it; Skatteverket is not told, and the event stays as
 * Skatteverket gave it.
 */
class MarkSkatteverketEvent
{
    /** @throws AuthorizationException */
    public function __invoke(User $user, Company $company, int $eventId, bool $handled): SkatteverketEvent
    {
        FetchSkatteverketEvents::authorize($user, $company, 'reports:view');

        return CurrentCompany::run($company, fn (): SkatteverketEvent => DB::transaction(function () use ($user, $company, $eventId, $handled): SkatteverketEvent {
            // Scoped to the company and the person: another company's or another user's event is not found (404).
            $event = SkatteverketEvent::query()->fetchedBy($user)->lockForUpdate()->findOrFail($eventId);

            if (($event->handled_at !== null) === $handled) {
                return $event;
            }

            $event->forceFill([
                'handled_at' => $handled ? CarbonImmutable::now() : null,
                'handled_by' => $handled ? $user->id : null,
            ])->save();

            Audit::record($handled ? 'skatteverket.event_handled' : 'skatteverket.event_reopened', $event, [], $company, $user);

            return $event;
        }));
    }
}
