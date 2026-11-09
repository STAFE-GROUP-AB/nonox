<?php

declare(strict_types=1);

namespace App\Livewire\Hq;

use App\Actions\Hq\Status\DeleteStatusIncident;
use App\Actions\Hq\Status\OpenStatusIncident;
use App\Actions\Hq\Status\PostStatusIncidentUpdate;
use App\Domain\Status\IncidentSeverity;
use App\Domain\Status\IncidentStatus;
use App\Livewire\Concerns\ShowsToasts;
use App\Livewire\Hq\Concerns\DecidesWithPasskey;
use App\Models\StatusHeartbeat;
use App\Models\StatusIncident;
use App\Support\Hq\HqAccess;
use App\Support\Tenancy\SpansCompanies;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Driftstatus in /hq (ADR-078): the incidents and planned maintenance the
 * public status page shows, with the history of notes. Publishing, noting,
 * closing and deleting are decisions with a fresh passkey confirmation, as
 * in the other HQ screens. The text is public: no names, no customer data.
 */
class StatusIncidents extends Component implements SpansCompanies
{
    use DecidesWithPasskey;
    use ShowsToasts;

    public string $title = '';

    public string $severity = 'DEGRADED';

    public string $body = '';

    public ?int $noteFor = null;

    public string $noteStatus = 'MONITORING';

    public string $noteSeverity = '';

    public string $noteBody = '';

    /**
     * @param  ?array<array-key, mixed>  $credential
     */
    public function open(?array $credential, OpenStatusIncident $open): void
    {
        $this->confirmDecision($credential);

        $open($this->admin(), ['title' => $this->title, 'severity' => $this->severity, 'body' => $this->body]);

        $this->reset('title', 'body');
        $this->severity = IncidentSeverity::Degraded->value;
        $this->toast(__('Händelsen är publicerad på statussidan.'));
    }

    public function startNote(int $incidentId): void
    {
        $incident = StatusIncident::query()->findOrFail($incidentId);

        $this->noteFor = $incident->id;
        $this->noteStatus = $incident->isOpen() ? $incident->status->value : IncidentStatus::Investigating->value;
        $this->noteSeverity = '';
        $this->noteBody = '';
    }

    public function cancelNote(): void
    {
        $this->reset('noteFor', 'noteBody', 'noteSeverity');
    }

    /**
     * @param  ?array<array-key, mixed>  $credential
     */
    public function postNote(?array $credential, PostStatusIncidentUpdate $post): void
    {
        $this->confirmDecision($credential);

        $incident = StatusIncident::query()->findOrFail((int) $this->noteFor);

        try {
            $post($this->admin(), $incident, ['status' => $this->noteStatus, 'severity' => $this->noteSeverity, 'body' => $this->noteBody]);
        } catch (ValidationException $exception) {
            // The note form has its own error keys, so a mistake in it is not shown under the form for a new incident.
            $keys = ['body' => 'noteBody', 'status' => 'noteStatus', 'severity' => 'noteSeverity'];

            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn (array $messages, string $key): array => [$keys[$key] ?? $key => $messages])->all());
        }

        $this->reset('noteFor', 'noteBody', 'noteSeverity');
        $this->toast(__('Anteckningen är publicerad.'));
    }

    /**
     * @param  ?array<array-key, mixed>  $credential
     */
    public function delete(?array $credential, int $incidentId, DeleteStatusIncident $delete): void
    {
        $this->confirmDecision($credential);

        $delete($this->admin(), StatusIncident::query()->findOrFail($incidentId));

        $this->toast(__('Händelsen är borttagen.'));
    }

    public function render(): View
    {
        $admin = $this->admin();

        return view('livewire.hq.status-incidents', [
            'incidents' => StatusIncident::query()->with('updates')->orderByRaw('resolved_at is not null')->orderByDesc('started_at')->limit(50)->get(),
            'heartbeats' => StatusHeartbeat::query()->orderBy('key')->get(),
            'severities' => IncidentSeverity::cases(),
            'statuses' => IncidentStatus::cases(),
            'fresh' => HqAccess::isFresh($admin),
            'hasPasskey' => $admin->hasPasskeysEnabled(),
        ]);
    }
}
