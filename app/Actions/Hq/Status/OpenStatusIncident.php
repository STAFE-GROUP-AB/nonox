<?php

declare(strict_types=1);

namespace App\Actions\Hq\Status;

use App\Domain\Status\IncidentSeverity;
use App\Domain\Status\IncidentStatus;
use App\Models\StatusIncident;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Hq\HqAccess;
use App\Support\Status\StatusSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Publishes a new incident or planned maintenance on the public status page
 * (ADR-078), with its first note. Only an HQ admin. The text is public: the
 * audit log gets the id, severity and status, never the text.
 */
final class OpenStatusIncident
{
    /**
     * @param  array<string, mixed>  $input  title, severity, body
     */
    public function __invoke(User $admin, array $input): StatusIncident
    {
        HqAccess::ensure($admin);

        $data = Validator::make($input, [
            'title' => ['required', 'string', 'max:'.StatusIncident::TITLE_MAX],
            'severity' => ['required', Rule::enum(IncidentSeverity::class)],
            'body' => ['required', 'string', 'max:'.StatusIncident::BODY_MAX],
        ], [], [
            'title' => __('rubrik'),
            'severity' => __('allvarlighet'),
            'body' => __('beskrivning'),
        ])->validate();

        $incident = DB::transaction(function () use ($admin, $data): StatusIncident {
            $incident = StatusIncident::query()->create([
                'title' => trim((string) $data['title']),
                'severity' => (string) $data['severity'],
                'status' => IncidentStatus::Investigating->value,
                'started_at' => now(),
                'created_by' => $admin->id,
            ]);

            $incident->updates()->create([
                'status' => IncidentStatus::Investigating->value,
                'body' => trim((string) $data['body']),
                'created_by' => $admin->id,
                'created_at' => now(),
            ]);

            Audit::instance('hq.status_incident_opened', $incident, ['severity' => $incident->severity->value], $admin);

            return $incident;
        });

        StatusSnapshot::forget();

        return $incident;
    }
}
