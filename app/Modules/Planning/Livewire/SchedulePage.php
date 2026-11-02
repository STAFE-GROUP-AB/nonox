<?php

declare(strict_types=1);

namespace App\Modules\Planning\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Modules\ModuleManager;
use App\Modules\Planning\Actions\ApplyTemplate;
use App\Modules\Planning\Actions\CopyPeriod;
use App\Modules\Planning\Actions\DeleteShift;
use App\Modules\Planning\Actions\PublishPeriod;
use App\Modules\Planning\Actions\RecalculateCosts;
use App\Modules\Planning\Actions\SaveShift;
use App\Modules\Planning\Actions\SaveTemplate;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\Models\Template;
use App\Modules\Planning\PlanningModule;
use App\Modules\Planning\PlanningSettings;
use App\Modules\Planning\Support\LocalTimestamp;
use App\Modules\Planning\Support\ScheduleData;
use App\Modules\StaffLedger\Livewire\Concerns\InCurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Planering → Schema (/planering, PLANNING_MODULE.md §7): the week grid with
 * people (or areas) as rows and days as columns, and a day view on a
 * timeline. The grid moves a dragged shift at once in the browser and sends
 * one call per drop; the page then shows the server's answer. All logic is
 * in the actions; this component only translates the grid's gestures.
 */
class SchedulePage extends Component
{
    use InCurrentCompany;
    use ShowsToasts;

    #[Url(as: 'vecka')]
    public string $week = '';

    #[Url(as: 'vy')]
    public string $view = '';

    #[Url(as: 'dag')]
    public string $day = '';

    #[Url(as: 'rader')]
    public string $rows = 'personal';

    #[Url(as: 'avdelning')]
    public ?int $area = null;

    #[Url(as: 'kompetens')]
    public ?int $skill = null;

    #[Url(as: 'lokal')]
    public ?int $location = null;

    public bool $showEditor = false;

    /** The shift being edited, or null for a new one. */
    public ?string $editing = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public bool $showPublish = false;

    public bool $showCopy = false;

    /** @var array{target: string, times: int, people: bool} */
    public array $copy = ['target' => '', 'times' => 1, 'people' => true];

    public bool $showTemplate = false;

    /** @var array{mode: string, template: ?int, name: string, times: int, people: bool} */
    public array $template = ['mode' => 'apply', 'template' => null, 'name' => '', 'times' => 1, 'people' => true];

    public function mount(): void
    {
        abort_unless($this->allowed('planning:view'), 403);
        $settings = $this->settings();
        $this->view = in_array($this->view, ['vecka', 'dag'], true) ? $this->view : ($settings['default_view'] === 'day' ? 'dag' : 'vecka');
        $this->week = $this->monday()->toDateString();
        $this->day = $this->dayDate()->toDateString();
    }

    public function previous(): void
    {
        $this->move(-1);
    }

    public function next(): void
    {
        $this->move(1);
    }

    public function today(): void
    {
        $this->goToDay(CarbonImmutable::today(LocalTimestamp::ZONE));
    }

    public function showDay(string $date): void
    {
        $this->goToDay(CarbonImmutable::parse($date, LocalTimestamp::ZONE));
        $this->view = 'dag';
    }

    public function showWeek(): void
    {
        $this->view = 'vecka';
    }

    /** Opens the editor for a new shift, filled from the cell or the timeline. */
    public function create(?int $employeeId = null, ?string $date = null, ?string $start = null, ?string $end = null): void
    {
        abort_unless($this->allowed('planning:manage'), 403);
        $this->resetErrorBag();
        $this->editing = null;
        $this->form = [
            'employee_id' => $employeeId,
            'date' => $date ?? $this->dayDate()->toDateString(),
            'start' => $start ?? '08:00',
            'end' => $end ?? '16:00',
            'break_minutes' => 0,
            'area_id' => $this->area,
            'skill_id' => $this->skill,
            'note' => '',
        ];
        $this->showEditor = true;
    }

    public function edit(string $id): void
    {
        abort_unless($this->allowed('planning:manage'), 403);
        $shift = Shift::query()->findOrFail($id);
        $this->resetErrorBag();
        $this->editing = $shift->id;
        $this->form = [
            'employee_id' => $shift->employee_id,
            'date' => $shift->starts_at->toDateString(),
            'start' => $shift->starts_at->format('H:i'),
            'end' => $shift->ends_at->format('H:i'),
            'break_minutes' => $shift->break_minutes,
            'area_id' => $shift->area_id,
            'skill_id' => $shift->skill_id,
            'note' => (string) $shift->note,
        ];
        $this->showEditor = true;
    }

    public function save(SaveShift $save): void
    {
        $shift = $this->editing === null ? null : Shift::query()->findOrFail($this->editing);
        $form = array_map(fn (mixed $value): mixed => $value === '' ? null : $value, $this->form);

        try {
            $saved = $save($this->user(), $this->company(), $form, $shift);
        } catch (ValidationException $exception) {
            $this->setErrorBag(collect($exception->errors())->mapWithKeys(fn (array $messages, string $key): array => ["form.{$key}" => $messages])->all());
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->showEditor = false;
        $this->toast($shift === null ? __('Passet är tillagt som utkast.') : $this->savedMessage($saved, __('Passet är sparat.')));
    }

    /**
     * A drop, move or resize from the grid: the shift's new person (null for
     * an open shift) and times. Returns whether it was saved, so the grid
     * can put the shift back when it was not.
     */
    public function moveShift(string $id, ?int $employeeId, string $startsAt, string $endsAt, SaveShift $save): bool
    {
        $shift = Shift::query()->findOrFail($id);

        return $this->attempt(fn () => $save($this->user(), $this->company(), [
            ...$this->current($shift),
            'employee_id' => $employeeId,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ], $shift), fn (Shift $saved): string => $this->savedMessage($saved, __('Passet är flyttat.')));
    }

    /** A drop in the grid with areas as rows: the shift's new area and times, same person. */
    public function moveShiftToArea(string $id, ?int $areaId, string $startsAt, string $endsAt, SaveShift $save): bool
    {
        $shift = Shift::query()->findOrFail($id);

        return $this->attempt(fn () => $save($this->user(), $this->company(), [
            ...$this->current($shift),
            'area_id' => $areaId,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ], $shift), fn (Shift $saved): string => $this->savedMessage($saved, __('Passet är flyttat.')));
    }

    /** Drag to create on the timeline: a new draft at once, without the editor. */
    public function createShift(?int $employeeId, string $startsAt, string $endsAt, SaveShift $save): bool
    {
        return $this->attempt(fn () => $save($this->user(), $this->company(), [
            'employee_id' => $employeeId,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'area_id' => $this->area,
            'skill_id' => $this->skill,
        ]), __('Passet är tillagt som utkast.'));
    }

    /** Paste (v) or a drop with Alt: a copy of the shift on another person and day, same times. */
    public function copyShift(string $id, ?int $employeeId, string $date, SaveShift $save): bool
    {
        $shift = Shift::query()->findOrFail($id);

        return $this->attempt(fn () => $save($this->user(), $this->company(), [
            ...$this->current($shift),
            'starts_at' => null,
            'ends_at' => null,
            'employee_id' => $employeeId,
            'date' => $date,
            'start' => $shift->starts_at->format('H:i'),
            'end' => $shift->ends_at->format('H:i'),
        ]), __('Passet är kopierat.'));
    }

    public function deleteShift(string $id, DeleteShift $delete): void
    {
        $shift = Shift::query()->findOrFail($id);

        try {
            $cancelled = $delete($this->user(), $this->company(), $shift);
        } catch (ValidationException $exception) {
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->showEditor = false;
        $this->toast($cancelled ? __('Passet är inställt och personen har fått besked.') : __('Utkastet är borttaget.'));
    }

    public function openPublish(): void
    {
        abort_unless($this->allowed('planning:manage'), 403);
        $this->showPublish = true;
    }

    public function publish(PublishPeriod $publish): void
    {
        [$from, $to] = $this->period();

        try {
            $publication = $publish($this->user(), $this->company(), $from, $to);
        } catch (ValidationException $exception) {
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->showPublish = false;
        $this->toast(trans_choice('{1} 1 pass är publicerat. :notified har fått besked.|[2,*] :count pass är publicerade. :notified har fått besked.', $publication->shift_count, [
            'count' => $publication->shift_count,
            'notified' => trans_choice('{0} Ingen|{1} 1 person|[2,*] :count personer', $publication->notified_count, ['count' => $publication->notified_count]),
        ]));
    }

    public function openCopy(): void
    {
        abort_unless($this->allowed('planning:manage'), 403);
        $this->copy = ['target' => $this->monday()->addWeek()->toDateString(), 'times' => 1, 'people' => true];
        $this->showCopy = true;
    }

    public function copyWeek(CopyPeriod $copy): void
    {
        try {
            $created = $copy($this->user(), $this->company(), $this->monday(), 1, CarbonImmutable::parse($this->copy['target'], LocalTimestamp::ZONE), (int) $this->copy['times'], (bool) $this->copy['people']);
        } catch (ValidationException $exception) {
            $this->toast($this->firstError($exception), 'error');

            return;
        } catch (Throwable) {
            $this->toast(__('Välj en vecka att kopiera till.'), 'error');

            return;
        }

        $this->showCopy = false;
        $this->toast(trans_choice('{0} Inga nya pass: veckan finns redan där.|{1} 1 pass är kopierat som utkast.|[2,*] :count pass är kopierade som utkast.', $created, ['count' => $created]));
    }

    public function openTemplate(string $mode = 'apply'): void
    {
        abort_unless($this->allowed('planning:manage'), 403);
        $this->template = ['mode' => $mode, 'template' => Template::query()->orderBy('name')->value('id'), 'name' => '', 'times' => 1, 'people' => true];
        $this->showTemplate = true;
    }

    public function applyTemplate(ApplyTemplate $apply, SaveTemplate $save): void
    {
        try {
            if ($this->template['mode'] === 'save') {
                $template = $save->fromWeeks($this->user(), $this->company(), $this->template['name'], $this->monday(), 1, (bool) $this->template['people']);
                $this->showTemplate = false;
                $this->toast(__('Veckan är sparad som mallen :name.', ['name' => $template->name]));

                return;
            }

            $template = Template::query()->with('shifts')->findOrFail((int) $this->template['template']);
            $created = $apply($this->user(), $this->company(), $template, $this->monday(), (int) $this->template['times'], (bool) $this->template['people']);
        } catch (ValidationException $exception) {
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->showTemplate = false;
        $this->toast(trans_choice('{0} Inga nya pass: mallens pass finns redan.|{1} 1 pass från mallen är tillagt som utkast.|[2,*] :count pass från mallen är tillagda som utkast.', $created, ['count' => $created]));
    }

    /** Recalculates the cost estimates of the period, for example after a salary change. */
    public function recalculate(RecalculateCosts $recalculate): void
    {
        [$from, $to] = $this->period();
        $recalculate($this->user(), $this->company(), $from, $to);
        $this->toast(__('Kostnaderna är omräknade.'));
    }

    public function render(ScheduleData $data): View
    {
        [$from, $to] = $this->period();
        $loaded = $data->load($this->company(), $from, $to, ['area' => $this->area, 'skill' => $this->skill, 'location' => $this->location]);

        return view('planning::livewire.schedule', [
            ...$loaded,
            'from' => $from,
            'to' => $to,
            'canManage' => $this->allowed('planning:manage'),
            'summary' => $this->showPublish ? PublishPeriod::summary($from, $to) : null,
            'templates' => $this->showTemplate ? Template::query()->orderBy('name')->get(['id', 'name', 'weeks']) : collect(),
            'step' => (int) $loaded['settings']['time_step_minutes'],
        ]);
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function period(): array
    {
        return $this->view === 'dag'
            ? [$this->dayDate(), $this->dayDate()]
            : [$this->monday(), $this->monday()->addDays(6)];
    }

    private function monday(): CarbonImmutable
    {
        return $this->parse($this->week)->startOfWeek(CarbonImmutable::MONDAY);
    }

    private function dayDate(): CarbonImmutable
    {
        return $this->parse($this->day);
    }

    private function move(int $direction): void
    {
        if ($this->view === 'dag') {
            $this->goToDay($this->dayDate()->addDays($direction));

            return;
        }

        $this->week = $this->monday()->addWeeks($direction)->toDateString();
    }

    private function goToDay(CarbonImmutable $day): void
    {
        $this->day = $day->toDateString();
        $this->week = $day->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
    }

    private function parse(string $date): CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            try {
                return CarbonImmutable::parse($date, LocalTimestamp::ZONE)->startOfDay();
            } catch (Throwable) {
            }
        }

        return CarbonImmutable::today(LocalTimestamp::ZONE);
    }

    /**
     * The shift's fields as SaveShift takes them.
     *
     * @return array<string, mixed>
     */
    private function current(Shift $shift): array
    {
        return [
            'employee_id' => $shift->employee_id,
            'area_id' => $shift->area_id,
            'skill_id' => $shift->skill_id,
            'starts_at' => $shift->starts_at->toIso8601String(),
            'ends_at' => $shift->ends_at->toIso8601String(),
            'break_minutes' => $shift->break_minutes,
            'note' => $shift->note,
        ];
    }

    /**
     * @param  callable(): Shift  $action
     * @param  string|callable(Shift): string  $success
     */
    private function attempt(callable $action, string|callable $success): bool
    {
        try {
            $saved = $action();
        } catch (ValidationException $exception) {
            $this->toast($this->firstError($exception), 'error');

            return false;
        }

        $this->toast(is_string($success) ? $success : $success($saved));

        return true;
    }

    /**
     * The toast after a change: a change to a published shift is shown to
     * the person only when the period is published again, and they are told
     * then only when the company notifies on publish.
     */
    private function savedMessage(Shift $saved, string $done): string
    {
        if (! $saved->hasUnpublishedChanges()) {
            return $done;
        }

        return PlanningSettings::bool($this->settings(), 'notify_on_publish')
            ? __(':done Personen får besked när du publicerar igen.', ['done' => $done])
            : __(':done Publicera igen för att visa ändringen. Aviseringar är avstängda, så personen får inget besked.', ['done' => $done]);
    }

    /** @return array<string, mixed> */
    private function settings(): array
    {
        return PlanningSettings::resolve(app(ModuleManager::class)->settings(PlanningModule::KEY, $this->company()));
    }
}
