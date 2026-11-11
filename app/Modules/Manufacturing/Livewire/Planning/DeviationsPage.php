<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Livewire\Planning;

use App\Models\SalesDocument;
use App\Modules\Manufacturing\Actions\Planning\ConfirmCustomerDate;
use App\Modules\Manufacturing\Actions\Planning\DecideDeviation;
use App\Modules\Manufacturing\Actions\Planning\Scenarios;
use App\Modules\Manufacturing\Domain\DeviationStatus;
use App\Modules\Manufacturing\Livewire\Concerns\PlanningScreen;
use App\Modules\Manufacturing\Mail\DeliveryDateMail;
use App\Modules\Manufacturing\Models\CustomerDateChange;
use App\Modules\Manufacturing\Models\ProductionDeviation;
use App\Modules\Manufacturing\Models\Workstation;
use App\Modules\Manufacturing\Planning\Suggestion;
use App\Modules\Manufacturing\Planning\Suggestions;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Tillverkning → Planering → Avvikelser (ADR-071 §13.5): what moved the
 * plan, what it did to each delivery, and simulated suggestions. A planner
 * applies a suggestion or closes the deviation (production:plan); a new
 * date goes to the customer only after production:confirm-dates has seen
 * the text and the recipient. An absence shows the person and the dates as
 * "Frånvarande", never the reason (§13.7).
 */
class DeviationsPage extends Component
{
    use PlanningScreen;

    #[Url(as: 'stangda')]
    public bool $showClosed = false;

    #[Locked]
    public ?int $suggestingId = null;

    /** @var array{deviation: ?int, document: ?string, date: string} */
    public array $confirming = ['deviation' => null, 'document' => null, 'date' => ''];

    #[Locked]
    public ?int $messageChangeId = null;

    public string $recipient = '';

    /** @var list<Suggestion>|null */
    private ?array $suggestions = null;

    public function suggest(?int $id): void
    {
        abort_unless($this->can('plan'), 403);
        $this->suggestingId = $this->suggestingId === $id ? null : $id;
    }

    public function apply(int $index, Scenarios $scenarios): void
    {
        abort_unless($this->can('plan'), 403);
        $suggestion = $this->suggestionList()[$index] ?? null;

        if ($suggestion === null || $suggestion->changes === []) {
            return;
        }

        $this->attempt(function () use ($scenarios, $suggestion): void {
            $scenarios->apply($this->user(), $this->company(), $scenarios->save($this->user(), $this->company(), $suggestion->changes));
        }, __('Förslaget är tillämpat. Planen beräknas om.'));

        $this->suggestions = null;
    }

    public function decide(int $id, string $status, DecideDeviation $decide): void
    {
        abort_unless($this->can('plan'), 403);
        $deviation = ProductionDeviation::query()->findOrFail($id);
        $status = DeviationStatus::tryFrom($status) ?? DeviationStatus::Handled;

        $this->attempt(fn () => $decide($this->user(), $this->company(), $deviation, $status), $status === DeviationStatus::Handled ? __('Avvikelsen är markerad som hanterad.') : __('Avvikelsen är avfärdad.'));
    }

    public function startConfirm(int $deviationId, string $documentId, string $date): void
    {
        abort_unless($this->can('confirmDates'), 403);
        $this->confirming = ['deviation' => $deviationId, 'document' => SalesDocument::query()->findOrFail($documentId)->id, 'date' => $date];
        $this->messageChangeId = null;
    }

    public function confirm(ConfirmCustomerDate $confirm): void
    {
        abort_unless($this->can('confirmDates'), 403);
        $document = SalesDocument::query()->with('customer')->findOrFail((string) $this->confirming['document']);
        $deviation = $this->confirming['deviation'] === null ? null : ProductionDeviation::query()->findOrFail($this->confirming['deviation']);
        $change = null;

        $ok = $this->attempt(function () use ($confirm, $document, $deviation, &$change): void {
            $change = $confirm($this->user(), $this->company(), $document, (string) $this->confirming['date'], $deviation);
        }, __('Leveransdatumet är bekräftat på ordern. Granska meddelandet innan det skickas till kunden.'), 'confirming.');

        if ($ok && $change instanceof CustomerDateChange) {
            $this->messageChangeId = $change->id;
            $this->recipient = (string) ($document->customer->email ?? '');
        }
    }

    public function send(ConfirmCustomerDate $confirm): void
    {
        abort_unless($this->can('confirmDates'), 403);
        $change = CustomerDateChange::query()->findOrFail((int) $this->messageChangeId);

        $ok = $this->attempt(fn () => $confirm->send($this->user(), $this->company(), $change, $this->recipient), __('Meddelandet om leveransdatum är skickat till :email.', ['email' => $this->recipient]), '');

        if ($ok) {
            $this->cancelConfirm();
        }
    }

    public function cancelConfirm(): void
    {
        $this->confirming = ['deviation' => null, 'document' => null, 'date' => ''];
        $this->messageChangeId = null;
        $this->recipient = '';
    }

    /** @return list<Suggestion> */
    private function suggestionList(): array
    {
        if ($this->suggestingId === null) {
            return [];
        }

        return $this->suggestions ??= app(Suggestions::class)->for($this->company(), ProductionDeviation::query()->findOrFail($this->suggestingId));
    }

    public function render(): View
    {
        $deviations = ProductionDeviation::query()
            ->when(! $this->showClosed, fn ($query) => $query->open())
            ->with(['impacts.order:id,number,name', 'impacts.salesDocument:id,number', 'impacts.customer:id,name', 'employee:id,first_name,last_name'])
            ->latest('id')
            ->limit(50)
            ->get();

        $workstations = Workstation::query()->whereIn('id', $deviations->map(fn (ProductionDeviation $deviation): ?int => $deviation->details['workstation_id'] ?? null)->filter()->unique()->values())->pluck('name', 'id');
        $preview = null;

        if ($this->messageChangeId !== null) {
            $change = CustomerDateChange::query()->findOrFail($this->messageChangeId);
            $preview = (new DeliveryDateMail($this->company(), $change))->render();
        }

        return view('manufacturing::livewire.planning.deviations', [
            'run' => $this->run(),
            'canPlan' => $this->can('plan'),
            'canConfirm' => $this->can('confirmDates'),
            'deviations' => $deviations,
            'workstations' => $workstations,
            'suggestions' => $this->suggestionList(),
            'preview' => $preview,
        ]);
    }
}
