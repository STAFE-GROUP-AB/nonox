<?php

declare(strict_types=1);

namespace App\Modules\Planning\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Planning\Actions\IssueIcalToken;
use App\Modules\Planning\Actions\RequestShift;
use App\Modules\Planning\Actions\RevokeIcalToken;
use App\Modules\Planning\Actions\SaveAvailability;
use App\Modules\Planning\Models\Area;
use App\Modules\Planning\Models\Availability;
use App\Modules\Planning\Models\EmployeeSkill;
use App\Modules\Planning\Models\IcalToken;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\Models\ShiftRequest;
use App\Modules\Planning\PlanningSettings;
use App\Modules\Planning\Support\LocalTimestamp;
use App\Modules\Planning\Support\PublishedSchedule;
use App\Modules\Planning\Support\SelfService;
use App\Modules\StaffLedger\Livewire\Concerns\InCurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Min sida → Mitt schema (PLANNING_MODULE.md §7), phone first: the next
 * shift, the week as a list (published shifts only, news marked), swaps a
 * colleague asks of me to accept or decline, open shifts to claim,
 * swapping or giving up a shift, availability and the
 * calendar link. Shown only to a login linked to an employee while
 * Planering is on. All logic is in the actions.
 */
class MySchedule extends Component
{
    use InCurrentCompany;
    use ShowsToasts;

    public const int OPEN_DAYS = 28;

    public const int MAX_WEEKS_AHEAD = 12;

    /** Weeks from this week: 0 is this week. */
    public int $week = 0;

    /** null, 'request' or 'availability' */
    public ?string $panel = null;

    /** @var array{kind: string, shift_id: string, counterpart_shift_id: string, note: string} */
    public array $request = ['kind' => '', 'shift_id' => '', 'counterpart_shift_id' => '', 'note' => ''];

    /** @var array{kind: string, repeat: string, weekday: int, date: string, whole_day: bool, from: string, to: string, note: string} */
    public array $availability = ['kind' => Availability::UNAVAILABLE, 'repeat' => 'weekly', 'weekday' => 1, 'date' => '', 'whole_day' => true, 'from' => '', 'to' => '', 'note' => ''];

    public ?int $editingAvailability = null;

    /** The calendar link right after it was made; only its hash is stored. */
    public ?string $icalUrl = null;

    public function previousWeek(): void
    {
        $this->week = max(0, $this->week - 1);
    }

    public function nextWeek(): void
    {
        $this->week = min(self::MAX_WEEKS_AHEAD, $this->week + 1);
    }

    public function claim(string $shiftId): void
    {
        $this->openRequest(ShiftRequest::CLAIM, $shiftId);
    }

    public function askSwap(string $shiftId): void
    {
        $this->openRequest(ShiftRequest::SWAP, $shiftId);
    }

    public function askDrop(string $shiftId): void
    {
        $this->openRequest(ShiftRequest::DROP, $shiftId);
    }

    public function sendRequest(RequestShift $requestShift): void
    {
        try {
            $requestShift($this->user(), $this->company(), $this->request);
        } catch (ValidationException $exception) {
            $this->setErrorBag(collect($exception->errors())->mapWithKeys(fn (array $messages, string $key): array => ["request.{$key}" => $messages])->all());
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->toast(match ($this->request['kind']) {
            ShiftRequest::CLAIM => __('Din förfrågan om passet är skickad. Du ser här när den är besvarad.'),
            ShiftRequest::SWAP => __('Din kollega har fått frågan om bytet. Säger hen ja går det vidare till din chef.'),
            default => __('Din förfrågan om att lämna passet är skickad. Passet är ditt tills den är godkänd.'),
        });
        $this->closePanel();
    }

    public function withdraw(int $requestId, RequestShift $requestShift): void
    {
        try {
            $requestShift->withdraw($this->user(), $this->company(), ShiftRequest::query()->findOrFail($requestId));
        } catch (ValidationException $exception) {
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->toast(__('Förfrågan är återkallad.'));
    }

    /** The colleague's yes to a swap: it goes on to the planner. */
    public function acceptSwap(int $requestId, RequestShift $requestShift): void
    {
        $this->answerSwap($requestId, true, $requestShift);
    }

    public function declineSwap(int $requestId, RequestShift $requestShift): void
    {
        $this->answerSwap($requestId, false, $requestShift);
    }

    public function newAvailability(): void
    {
        $this->resetErrorBag();
        $this->editingAvailability = null;
        $this->availability = ['kind' => Availability::UNAVAILABLE, 'repeat' => 'weekly', 'weekday' => CarbonImmutable::today(LocalTimestamp::ZONE)->isoWeekday(), 'date' => CarbonImmutable::today(LocalTimestamp::ZONE)->toDateString(), 'whole_day' => true, 'from' => '', 'to' => '', 'note' => ''];
        $this->panel = 'availability';
    }

    public function editAvailability(int $id): void
    {
        $row = $this->ownAvailability($id);
        $this->resetErrorBag();
        $this->editingAvailability = $row->id;
        $this->availability = [
            'kind' => $row->kind,
            'repeat' => $row->isRecurring() ? 'weekly' : 'date',
            'weekday' => $row->weekday ?? 1,
            'date' => $row->date?->toDateString() ?? '',
            'whole_day' => $row->isWholeDay(),
            'from' => (string) $row->from,
            'to' => (string) $row->to,
            'note' => (string) $row->note,
        ];
        $this->panel = 'availability';
    }

    public function saveAvailability(SaveAvailability $save): void
    {
        try {
            $save($this->user(), $this->company(), $this->availability, $this->editingAvailability === null ? null : $this->ownAvailability($this->editingAvailability));
        } catch (ValidationException $exception) {
            $this->setErrorBag(collect($exception->errors())->mapWithKeys(fn (array $messages, string $key): array => ["availability.{$key}" => $messages])->all());
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->toast($this->editingAvailability === null ? __('Din tillgänglighet är sparad.') : __('Din tillgänglighet är ändrad.'));
        $this->closePanel();
    }

    public function deleteAvailability(int $id, SaveAvailability $save): void
    {
        try {
            $save->delete($this->user(), $this->company(), $this->ownAvailability($id));
        } catch (ValidationException $exception) {
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->toast(__('Raden är borttagen.'));
        $this->closePanel();
    }

    public function issueIcal(IssueIcalToken $issue): void
    {
        $this->icalUrl = $issue($this->user(), $this->company());
        $this->toast(__('Din kalenderlänk är skapad. Kopiera den nu, den visas bara en gång.'));
    }

    public function revokeIcal(RevokeIcalToken $revoke): void
    {
        $revoke($this->user(), $this->company());
        $this->icalUrl = null;
        $this->toast(__('Kalenderlänken är avstängd. Kalendrar som använder den uppdateras inte längre.'));
    }

    public function closePanel(): void
    {
        $this->panel = null;
        $this->editingAvailability = null;
        $this->request = ['kind' => '', 'shift_id' => '', 'counterpart_shift_id' => '', 'note' => ''];
        $this->resetErrorBag();
    }

    public function render(SelfService $self): View
    {
        $employee = $self->find($this->user(), $this->company());

        if (! $employee instanceof Employee) {
            return view('planning::livewire.my-schedule', ['employee' => null]);
        }

        $settings = $self->settings($this->company());
        $now = CarbonImmutable::now(LocalTimestamp::ZONE);
        $today = $now->startOfDay();
        $monday = $today->startOfWeek(CarbonImmutable::MONDAY)->addWeeks($this->week);
        $claims = PlanningSettings::bool($settings, 'allow_open_shift_claims');
        $swaps = PlanningSettings::bool($settings, 'allow_swaps');

        $upcoming = PublishedSchedule::forEmployee($employee->id, $today, $today->addDays(60), withCancelled: false);
        $weekShifts = PublishedSchedule::forEmployee($employee->id, $monday, $monday->addDays(6));
        $requests = ShiftRequest::query()->with(['shift', 'counterpartShift'])->where('requested_by', $employee->id)->latest('id')->limit(10)->get();
        $pending = $requests->whereIn('status', ShiftRequest::OPEN);

        return view('planning::livewire.my-schedule', [
            'employee' => $employee,
            'now' => $now,
            'monday' => $monday,
            'next' => $upcoming->first(fn ($shift): bool => $shift->end->greaterThan($now)),
            'weekShifts' => $weekShifts,
            'areas' => Area::query()->get(['id', 'name', 'colour'])->keyBy('id'),
            'openShifts' => $claims ? $this->openShifts($employee, $now) : collect(),
            'requests' => $requests,
            'pendingShiftIds' => $pending->pluck('shift_id')->all(),
            'askedOfMe' => ShiftRequest::query()
                ->with(['shift', 'counterpartShift', 'requester:id,first_name,last_name'])
                ->where('counterpart_employee_id', $employee->id)
                ->where('status', ShiftRequest::AWAITING_COUNTERPART)
                ->oldest('id')
                ->get(),
            'swapCandidates' => $this->panel === 'request' && $this->request['kind'] === ShiftRequest::SWAP ? $this->swapCandidates($employee, $now) : collect(),
            'requestShift' => $this->panel === 'request' && $this->request['shift_id'] !== '' ? Shift::query()->find($this->request['shift_id']) : null,
            'availabilityRows' => PlanningSettings::bool($settings, 'allow_availability') ? Availability::query()->where('employee_id', $employee->id)->orderBy('weekday')->orderBy('date')->orderBy('from')->get() : null,
            'ical' => IcalToken::query()->where('employee_id', $employee->id)->whereNull('revoked_at')->first(),
            'claims' => $claims,
            'swaps' => $swaps,
        ]);
    }

    private function answerSwap(int $requestId, bool $accept, RequestShift $requestShift): void
    {
        try {
            $requestShift->answer($this->user(), $this->company(), ShiftRequest::query()->findOrFail($requestId), $accept);
        } catch (ValidationException $exception) {
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->toast($accept
            ? __('Du har sagt ja till bytet. Nu väntar det på er chef; inget ändras förrän chefen godkänner.')
            : __('Du har tackat nej till bytet. Din kollega har fått besked.'));
    }

    private function openRequest(string $kind, string $shiftId): void
    {
        $this->resetErrorBag();
        $this->request = ['kind' => $kind, 'shift_id' => $shiftId, 'counterpart_shift_id' => '', 'note' => ''];
        $this->panel = 'request';
    }

    /**
     * Published open shifts that have not started, within four weeks, that
     * need no skill the employee lacks.
     *
     * @return Collection<int, Shift>
     */
    private function openShifts(Employee $employee, CarbonImmutable $now): Collection
    {
        $skills = EmployeeSkill::query()->where('employee_id', $employee->id)->pluck('skill_id')->all();

        return Shift::query()
            ->where('status', Shift::PUBLISHED)
            ->whereNull('employee_id')
            ->where('starts_at', '>', $now)
            ->where('starts_at', '<', $now->addDays(self::OPEN_DAYS))
            ->where(fn ($query) => $query->whereNull('skill_id')->when($skills !== [], fn ($query) => $query->orWhereIn('skill_id', $skills)))
            ->orderBy('starts_at')
            ->limit(20)
            ->get();
    }

    /**
     * Colleagues' published shifts that have not started, to swap with.
     * Only the first name and the initial of the last name are shown.
     *
     * @return Collection<int, Shift>
     */
    private function swapCandidates(Employee $employee, CarbonImmutable $now): Collection
    {
        return Shift::query()
            ->with('employee:id,first_name,last_name')
            ->where('status', Shift::PUBLISHED)
            ->whereNotNull('employee_id')
            ->where('employee_id', '<>', $employee->id)
            ->where('starts_at', '>', $now)
            ->where('starts_at', '<', $now->addDays(self::OPEN_DAYS))
            ->orderBy('starts_at')
            ->limit(60)
            ->get();
    }

    /** One of the employee's own availability rows; anyone else's answers 404. */
    private function ownAvailability(int $id): Availability
    {
        $row = Availability::query()->findOrFail($id);
        abort_unless($this->user()->can('own', $row), 404);

        return $row;
    }
}
