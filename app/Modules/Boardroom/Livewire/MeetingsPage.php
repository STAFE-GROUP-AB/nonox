<?php

declare(strict_types=1);

namespace App\Modules\Boardroom\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\FiscalYear;
use App\Modules\Boardroom\Actions\SaveMeeting;
use App\Modules\Boardroom\Domain\MeetingKind;
use App\Modules\Boardroom\Domain\MinutesStatus;
use App\Modules\Boardroom\Livewire\Concerns\InCurrentCompany;
use App\Modules\Boardroom\Models\Meeting;
use App\Modules\Boardroom\Models\Minutes;
use App\Support\Toast;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Styrelserum: the company's meetings, coming and held, and the form that
 * plans a new one. An annual general meeting is planned for a fiscal year
 * and gets its agenda from the closing. One query for the meetings and one
 * for the state of their minutes, however many there are.
 */
class MeetingsPage extends Component
{
    use InCurrentCompany;
    use ShowsToasts;

    public bool $planning = false;

    /** @var array<string, mixed> */
    public array $form = [];

    public function mount(): void
    {
        abort_unless($this->allowed('meetings:view'), 403);
    }

    public function plan(string $kind = 'AGM'): void
    {
        $kind = MeetingKind::tryFrom($kind) ?? MeetingKind::AnnualGeneralMeeting;
        $year = FiscalYear::query()->whereDate('end_date', '<', CarbonImmutable::today()->toDateString())->orderByDesc('end_date')->first();
        $this->planning = true;
        $this->form = [
            'kind' => $kind->value,
            'title' => $kind === MeetingKind::AnnualGeneralMeeting && $year !== null ? __('Årsstämma för :year', ['year' => $year->label()]) : $kind->label(),
            'meeting_date' => CarbonImmutable::today()->addWeeks(6)->toDateString(),
            'start_time' => '', 'place' => '', 'digital' => false,
            'fiscal_year_id' => $kind === MeetingKind::AnnualGeneralMeeting ? (string) ($year->id ?? '') : '',
        ];
        $this->resetErrorBag();
    }

    public function save(SaveMeeting $save): void
    {
        $meeting = null;
        $input = array_map(fn (mixed $value): mixed => $value === '' ? null : $value, $this->form);

        if (! $this->attempt(function () use ($save, $input, &$meeting): void {
            $meeting = $save($this->user(), $this->company(), $input);
        })) {
            return;
        }

        Toast::flash(__('Mötet är planerat. Dagordningen är ifylld med förslag.'));
        $this->redirectRoute('boardroom.meetings.show', ['meeting' => $meeting?->id]);
    }

    public function render(): View
    {
        $meetings = Meeting::query()->orderByDesc('meeting_date')->orderByDesc('id')->get();
        $minutes = Minutes::query()->whereIn('meeting_id', $meetings->modelKeys())->get(['id', 'meeting_id', 'status'])->groupBy('meeting_id');
        $today = CarbonImmutable::today();

        return view('boardroom::livewire.meetings', [
            'upcoming' => $meetings->filter(fn (Meeting $meeting): bool => $meeting->meeting_date->greaterThanOrEqualTo($today))->reverse()->values(),
            'held' => $meetings->filter(fn (Meeting $meeting): bool => $meeting->meeting_date->lessThan($today))->values(),
            'approved' => $minutes->map(fn ($list): bool => $list->contains(fn (Minutes $each): bool => $each->status === MinutesStatus::Approved)),
            'kinds' => MeetingKind::cases(),
            'years' => FiscalYear::query()->orderByDesc('start_date')->get(),
            'canManage' => $this->mayChange('meetings:manage'),
        ]);
    }
}
