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
 * Adds a note to an incident's history and moves it to a new status
 * (ADR-078). "Åtgärdad" closes it; a later note with another status opens
 * it again. The severity may be corrected in the same step. Audited with
 * ids and enum values only.
 */
final class PostStatusIncidentUpdate
{
    /**
     * @param  array<string, mixed>  $input  status, body, severity (optional)
     */
    public function __invoke(User $admin, StatusIncident $incident, array $input): StatusIncident
    {
        HqAccess::ensure($admin);

        $data = Validator::make($input, [
            'status' => ['required', Rule::enum(IncidentStatus::class)],
            'severity' => ['nullable', Rule::enum(IncidentSeverity::class)],
            'body' => ['required', 'string', 'max:'.StatusIncident::BODY_MAX],
        ], [], [
            'status' => __('status'),
            'severity' => __('allvarlighet'),
            'body' => __('anteckning'),
        ])->validate();

        $status = IncidentStatus::from((string) $data['status']);
        $before = ['status' => $incident->status->value, 'severity' => $incident->severity->value];

        DB::transaction(function () use ($admin, $incident, $data, $status, $before): void {
            $incident->forceFill([
                'status' => $status->value,
                'severity' => ($data['severity'] ?? null) ?: $incident->severity->value,
                'resolved_at' => $status === IncidentStatus::Resolved ? ($incident->resolved_at ?? now()) : null,
            ])->save();

            $incident->updates()->create([
                'status' => $status->value,
                'body' => trim((string) $data['body']),
                'created_by' => $admin->id,
                'created_at' => now(),
            ]);

            Audit::instance('hq.status_incident_updated', $incident, [
                'before' => $before,
                'after' => ['status' => $incident->status->value, 'severity' => $incident->severity->value],
            ], $admin);
        });

        StatusSnapshot::forget();

        return $incident;
    }
}
