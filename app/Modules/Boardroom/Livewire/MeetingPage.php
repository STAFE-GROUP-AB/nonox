<?php

declare(strict_types=1);

namespace App\Modules\Boardroom\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\User;
use App\Modules\Boardroom\Actions\MinutesWorkflow;
use App\Modules\Boardroom\Actions\SaveMeeting;
use App\Modules\Boardroom\Domain\AgendaItemKind;
use App\Modules\Boardroom\Domain\MinutesStatus;
use App\Modules\Boardroom\Domain\NoticeWindow;
use App\Modules\Boardroom\Domain\ReportableKind;
use App\Modules\Boardroom\Livewire\Concerns\InCurrentCompany;
use App\Modules\Boardroom\Models\AgendaItem;
use App\Modules\Boardroom\Models\ArticlesVersion;
use App\Modules\Boardroom\Models\Meeting;
use App\Modules\Boardroom\Models\Minutes;
use App\Modules\Boardroom\Models\Shareholder;
use App\Support\Toast;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One meeting in Styrelserum: its details and attendees, the advice on when
 * to send the notice, the numbered agenda, the voting list from the register
 * on the meeting day, and the minutes from draft to "Godkänt i NONOX".
 * Decisions to report to Bolagsverket are marked here, and the day the
 * notification came in is entered here.
 */
class MeetingPage extends Component
{
    use InCurrentCompany;
    use ShowsToasts;

    #[Locked]
    public int $meetingId;

    public bool $editing = false;

    /** @var array<string, mixed> */
    public array $form = [];

    /** null: no item form; 0: a new item; otherwise the item being changed */
    public ?int $editingItem = null;

    /** @var array<string, mixed> */
    public array $item = [];

    /** @var array<int, string> */
    public array $reported = [];

    /** @var array{decisions: array<int|string, ?string>, dissents: array<int|string, ?string>, notes: ?string} */
    public array $minutesForm = ['decisions' => [], 'dissents' => [], 'notes' => null];

    /** @var array{chair: string, minute_taker: string, adjusters: string} */
    public array $checking = ['chair' => '', 'minute_taker' => '', 'adjusters' => ''];

    public function mount(int|string $meeting): void
    {
        abort_unless($this->allowed('meetings:view'), 403);
        $this->meetingId = Meeting::query()->findOrFail((int) $meeting)->id;
        $this->loadMinutesForm();
    }

    public function edit(): void
    {
        $meeting = $this->meeting();
        $this->editing = true;
        $this->form = [
            ...$meeting->only(['title', 'start_time', 'place', 'digital', 'chair_name', 'minute_taker_name']),
            'meeting_date' => $meeting->meeting_date->toDateString(),
            'notice_sent_on' => $meeting->notice_sent_on?->toDateString() ?? '',
            'fiscal_year_id' => $meeting->fiscal_year_id,
            'adjusters' => implode(', ', (array) $meeting->adjusters),
            'attendees' => array_map(fn (array $attendee): array => ['name' => $attendee['name'], 'role' => $attendee['role'], 'shareholder_id' => (string) ($attendee['shareholder_id'] ?? '')], (array) $meeting->attendees),
        ];
        $this->resetErrorBag();
    }

    public function addAttendee(): void
    {
        $this->form['attendees'][] = ['name' => '', 'role' => $this->meeting()->kind->isGeneralMeeting() ? 'shareholder' : 'member', 'shareholder_id' => ''];
    }

    public function removeAttendee(int $index): void
    {
        unset($this->form['attendees'][$index]);
        $this->form['attendees'] = array_values($this->form['attendees']);
    }

    public function save(SaveMeeting $save): void
    {
        $input = $this->form;
        $input['adjusters'] = array_values(array_filter(array_map('trim', explode(',', (string) ($input['adjusters'] ?? '')))));
        $input['attendees'] = array_map(fn (array $attendee): array => [...$attendee, 'shareholder_id' => ($attendee['shareholder_id'] ?? '') === '' ? null : (int) $attendee['shareholder_id']], (array) ($input['attendees'] ?? []));
        $input = array_map(fn (mixed $value): mixed => $value === '' ? null : $value, $input);

        if ($this->attempt(fn () => $save($this->user(), $this->company(), $input, $this->meeting()))) {
            $this->toast(__('Mötet är sparat.'));
            $this->reset('editing', 'form');
        }
    }

    public function editItem(?int $id = null): void
    {
        $existing = $id === null ? null : AgendaItem::query()->where('meeting_id', $this->meetingId)->findOrFail($id);
        $this->editingItem = $existing->id ?? 0;
        $this->item = [
            'kind' => $existing->kind->value ?? AgendaItemKind::Other->value,
            'title' => $existing->title ?? '',
            'proposal' => $existing->proposal ?? '',
            'decision' => $existing->decision ?? '',
            'reportable' => $existing?->reportable->value ?? '',
        ];
        $this->resetErrorBag();
    }

    public function saveItem(SaveMeeting $save): void
    {
        $existing = $this->editingItem ? AgendaItem::query()->where('meeting_id', $this->meetingId)->findOrFail($this->editingItem) : null;

        if ($this->attempt(fn () => $save->saveItem($this->user(), $this->company(), $this->meeting(), $this->item, $existing), 'item')) {
            $this->toast($existing === null ? __('Ärendet är tillagt på dagordningen.') : __('Ärendet är sparat.'));
            $this->reset('editingItem', 'item');
        }
    }

    public function removeItem(int $id, SaveMeeting $save): void
    {
        $item = AgendaItem::query()->where('meeting_id', $this->meetingId)->findOrFail($id);

        if ($this->attempt(fn () => $save->removeItem($this->user(), $this->company(), $item), 'item')) {
            $this->toast(__('Ärendet är borttaget från dagordningen.'));
        }
    }

    public function recordReported(int $id, SaveMeeting $save): void
    {
        $item = AgendaItem::query()->where('meeting_id', $this->meetingId)->findOrFail($id);

        if ($this->attempt(fn () => $save->recordReported($this->user(), $this->company(), $item, (string) ($this->reported[$id] ?? '')), 'reported')) {
            $this->toast(__('Dagen då anmälan kom in till Bolagsverket är sparad. Påminnelsen i Mina datum är borta.'));
            unset($this->reported[$id]);
        }
    }

    public function startMinutes(MinutesWorkflow $workflow, ?int $corrects = null): void
    {
        $original = $corrects === null ? null : Minutes::query()->where('meeting_id', $this->meetingId)->findOrFail($corrects);

        if ($this->attempt(fn () => $workflow->start($this->user(), $this->company(), $this->meeting(), $original), 'minutes')) {
            $this->loadMinutesForm();
            $this->toast($original === null ? __('Ett utkast till protokoll är skapat.') : __('Ett nytt protokoll som rättar det godkända är skapat. Det godkända finns kvar.'));
        }
    }

    public function saveMinutes(MinutesWorkflow $workflow): void
    {
        $draft = $this->draft();
        abort_if($draft === null, 404);

        if ($this->attempt(fn () => $workflow->update($this->user(), $this->company(), $draft, $this->minutesForm), 'minutesForm')) {
            $this->toast(__('Protokollet är sparat som utkast.'));
        }
    }

    public function check(MinutesWorkflow $workflow): void
    {
        $draft = $this->draft();
        abort_if($draft === null, 404);
        $adjusters = array_values(array_filter(array_map('trim', explode(',', $this->checking['adjusters']))));

        if ($this->attempt(function () use ($workflow, $draft, $adjusters): void {
            $workflow->update($this->user(), $this->company(), $draft, $this->minutesForm);
            $workflow->check($this->user(), $this->company(), $draft->fresh() ?? $draft, $this->checking['chair'], $this->checking['minute_taker'], $adjusters);
        }, 'checking')) {
            $this->toast(__('Protokollet är markerat som justerat.'));
        }
    }

    public function approve(MinutesWorkflow $workflow): void
    {
        $draft = $this->draft();
        abort_if($draft === null, 404);

        if ($this->attempt(fn () => $workflow->approve($this->user(), $this->company(), $draft), 'minutes')) {
            $this->toast(__('Protokollet är godkänt i NONOX och låst. PDF:en och dess kontrollsumma är sparade.'));
        }
    }

    public function discard(MinutesWorkflow $workflow): void
    {
        $draft = $this->draft();
        abort_if($draft === null, 404);

        if ($this->attempt(fn () => $workflow->discard($this->user(), $this->company(), $draft), 'minutes')) {
            $this->loadMinutesForm();
            $this->toast(__('Utkastet är borttaget.'));
        }
    }

    public function delete(SaveMeeting $save): void
    {
        if ($this->attempt(fn () => $save->delete($this->user(), $this->company(), $this->meeting()), 'meeting')) {
            Toast::flash(__('Mötet är borttaget.'));
            $this->redirectRoute('boardroom.meetings');
        }
    }

    public function render(): View
    {
        $meeting = $this->meeting();
        $items = AgendaItem::query()->where('meeting_id', $meeting->id)->orderBy('position')->get();
        $minutes = Minutes::query()->where('meeting_id', $meeting->id)->orderBy('id')->get();
        $articles = ArticlesVersion::applicable($meeting->meeting_date);
        $window = NoticeWindow::for($meeting, $items, $articles);
        $approvers = User::query()->whereIn('id', $minutes->pluck('approved_by')->filter()->unique()->values())->pluck('name', 'id');

        $votingList = $meeting->kind->isGeneralMeeting() ? MinutesWorkflow::votingList($meeting) : [];

        return view('boardroom::livewire.meeting', [
            'meeting' => $meeting,
            'items' => $items,
            'minutes' => $minutes,
            'draft' => $minutes->first(fn (Minutes $each): bool => ! $each->isApproved()),
            'approvers' => $approvers,
            'window' => $window,
            'articles' => $articles,
            'locked' => $minutes->contains(fn (Minutes $each): bool => $each->isApproved()),
            'votingList' => $votingList,
            'holders' => Shareholder::query()->orderBy('name')->get(['id', 'name']),
            'itemKinds' => AgendaItemKind::cases(),
            'reportables' => ReportableKind::cases(),
            'canManage' => $this->mayChange('meetings:manage'),
            'roles' => Meeting::ROLES,
        ]);
    }

    private function meeting(): Meeting
    {
        return Meeting::query()->findOrFail($this->meetingId);
    }

    private function draft(): ?Minutes
    {
        return Minutes::query()->where('meeting_id', $this->meetingId)->where('status', '!=', MinutesStatus::Approved->value)->first();
    }

    private function loadMinutesForm(): void
    {
        $draft = $this->draft();
        $meeting = $this->meeting();
        $this->minutesForm = ['decisions' => [], 'dissents' => [], 'notes' => null];

        if ($draft !== null) {
            foreach ($draft->body['items'] ?? [] as $item) {
                $this->minutesForm['decisions'][$item['position']] = $item['decision'] ?? '';
                $this->minutesForm['dissents'][$item['position']] = $item['dissent'] ?? '';
            }

            $this->minutesForm['notes'] = $draft->body['notes'] ?? '';
        }

        $this->checking = [
            'chair' => (string) $meeting->chair_name,
            'minute_taker' => (string) $meeting->minute_taker_name,
            'adjusters' => implode(', ', (array) $meeting->adjusters),
        ];
    }
}
