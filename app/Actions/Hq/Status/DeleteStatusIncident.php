<?php

declare(strict_types=1);

namespace App\Actions\Hq\Status;

use App\Models\StatusIncident;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Hq\HqAccess;
use App\Support\Status\StatusSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * Removes an incident that should never have been published (ADR-078),
 * with its notes. Resolved incidents are history and normally stay; the
 * deletion is audited with the id, severity and number of notes.
 */
final class DeleteStatusIncident
{
    public function __invoke(User $admin, StatusIncident $incident): void
    {
        HqAccess::ensure($admin);

        DB::transaction(function () use ($admin, $incident): void {
            Audit::instance('hq.status_incident_deleted', $incident, [
                'severity' => $incident->severity->value,
                'updates' => $incident->updates()->count(),
            ], $admin);

            $incident->delete();
        });

        StatusSnapshot::forget();
    }
}
