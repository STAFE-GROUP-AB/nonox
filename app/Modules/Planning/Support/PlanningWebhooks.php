<?php

declare(strict_types=1);

namespace App\Modules\Planning\Support;

use App\Models\Company;
use App\Modules\Planning\Events\PeriodAttested;
use App\Modules\Planning\Events\PeriodPublished;
use App\Modules\Planning\Models\Publication;
use Carbon\CarbonImmutable;

/**
 * Announces Planering's events to NONOX Connect (ADR-032). Call these inside
 * the action's transaction: the events are dispatched after it commits, so
 * a rolled-back publication or attestation is never announced. Connect
 * listens; Planering does not know about webhooks.
 */
final class PlanningWebhooks
{
    public static function published(Publication $publication): void
    {
        event(new PeriodPublished($publication->company_id, $publication->id));
    }

    /**
     * Time for the days from–to was attested.
     *
     * @param  iterable<int|string>  $attestationIds  the attestation rows that became attested
     * @param  iterable<int>  $employeeIds  whose time it was
     * @param  int  $attestedMinutes  the attested minutes in total
     */
    public static function attested(Company $company, CarbonImmutable $from, CarbonImmutable $to, iterable $attestationIds, iterable $employeeIds, int $attestedMinutes = 0): void
    {
        [$first, $last] = $from->greaterThan($to) ? [$to, $from] : [$from, $to];
        $employees = [];

        foreach ($employeeIds as $id) {
            $employees[(int) $id] = (int) $id;
        }

        sort($employees);
        $attestations = [];

        foreach ($attestationIds as $id) {
            $attestations[] = $id;
        }

        event(new PeriodAttested($company->id, $first->toDateString(), $last->toDateString(), $attestations, $employees, $attestedMinutes));
    }
}
