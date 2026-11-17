<?php

declare(strict_types=1);

namespace App\Modules\Planning\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Modules\Planning\Actions\SendMessage;
use App\Modules\Planning\Models\Area;
use App\Modules\Planning\Models\Message;
use App\Modules\Planning\Models\MessageRecipient;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\Support\LocalTimestamp;
use App\Modules\Planning\Support\MessageAudience;
use App\Modules\Planning\Support\ShiftQuery;
use App\Modules\StaffLedger\Livewire\Concerns\InCurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * Planering → Meddelanden (ADR-037 gap 5): write a message to the staff on a
 * shift, a day, a period, an area or everyone, and see per message who has
 * read and confirmed it. Planners only. The list loads in a fixed number of
 * queries however many messages and recipients there are. All logic is in
 * SendMessage.
 */
class MessagesPage extends Component
{
    use InCurrentCompany;
    use ShowsToasts;

    public const int LIST_LIMIT = 50;

    /** @var array{scope: string, shift_date: string, shift_id: string, area_id: int|string|null, date_from: string, date_to: string, body: string, requires_ack: bool} */
    public array $form = ['scope' => Message::PERIOD, 'shift_date' => '', 'shift_id' => '', 'area_id' => null, 'date_from' => '', 'date_to' => '', 'body' => '', 'requires_ack' => false];

    /** The message whose recipients are shown. */
    #[Locked]
    public ?int $showing = null;

    public function mount(): void
    {
        abort_unless($this->allowed('planning:manage'), 403);
        $this->resetForm();
    }

    public function send(SendMessage $send): void
    {
        abort_unless($this->allowed('planning:manage'), 403);
        $form = $this->form;

        try {
            $result = $send($this->user(), $this->company(), [
                'scope' => $form['scope'],
                'shift_id' => $form['shift_id'] === '' ? null : $form['shift_id'],
                'area_id' => $form['area_id'] === '' || $form['area_id'] === null ? null : (int) $form['area_id'],
                'date_from' => $form['scope'] === Message::SHIFT || $form['date_from'] === '' ? null : $form['date_from'],
                'date_to' => in_array($form['scope'], [Message::SHIFT, Message::DAY], true) || $form['date_to'] === '' ? null : $form['date_to'],
                'body' => $form['body'],
                'requires_ack' => (bool) $form['requires_ack'],
            ]);
        } catch (ValidationException $exception) {
            $this->setErrorBag(collect($exception->errors())->mapWithKeys(fn (array $messages, string $key): array => ["form.{$key}" => $messages])->all());
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->resetForm();
        $this->showing = $result['message']->id;
        $sent = trans_choice('{1} Meddelandet är skickat till 1 person.|[2,*] Meddelandet är skickat till :count personer.', $result['recipients'], ['count' => $result['recipients']]);
        $this->toast($result['unreachable'] === 0 ? $sent : $sent.' '.trans_choice('{1} 1 nås inte eftersom hen inte har någon inloggning.|[2,*] :count nås inte eftersom de inte har någon inloggning.', $result['unreachable'], ['count' => $result['unreachable']]));
    }

    public function show(int $id): void
    {
        abort_unless($this->allowed('planning:manage'), 403);
        $this->showing = $this->showing === $id ? null : Message::query()->findOrFail($id)->id;
    }

    public function delete(int $id, SendMessage $send): void
    {
        $send->delete($this->user(), $this->company(), Message::query()->findOrFail($id));
        $this->showing = $this->showing === $id ? null : $this->showing;
        $this->toast(__('Meddelandet är borttaget. Personalen ser det inte längre.'));
    }

    public function render(): View
    {
        abort_unless($this->allowed('planning:manage'), 403);
        $areas = Area::query()->orderBy('sort')->orderBy('name')->get(['id', 'name', 'archived_at']);

        return view('planning::livewire.messages', [
            'areas' => $areas,
            'areaNames' => $areas->pluck('name', 'id'),
            'messages' => Message::query()
                ->with('author:id,name')
                ->withCount([
                    'recipients',
                    'recipients as read_count' => fn ($query) => $query->whereNotNull('read_at'),
                    'recipients as acknowledged_count' => fn ($query) => $query->whereNotNull('acknowledged_at'),
                    'recipients as unreachable_count' => fn ($query) => $query->whereNull('notified_at'),
                ])
                ->latest('id')
                ->limit(self::LIST_LIMIT)
                ->get(),
            'recipients' => $this->showing === null ? collect() : MessageRecipient::query()
                ->with('employee:id,first_name,last_name')
                ->where('message_id', $this->showing)
                ->orderBy('employee_id')
                ->get(),
            'dayShifts' => $this->dayShifts(),
            'audience' => $this->audience(),
        ]);
    }

    private function resetForm(): void
    {
        $monday = CarbonImmutable::today(LocalTimestamp::ZONE)->startOfWeek(CarbonImmutable::MONDAY);
        $this->resetErrorBag();
        $this->form = ['scope' => Message::PERIOD, 'shift_date' => CarbonImmutable::today(LocalTimestamp::ZONE)->toDateString(), 'shift_id' => '', 'area_id' => null, 'date_from' => $monday->toDateString(), 'date_to' => $monday->addDays(6)->toDateString(), 'body' => '', 'requires_ack' => false];
    }

    /**
     * The published shifts with a person on the chosen day, to pick one from.
     *
     * @return Collection<int, Shift>
     */
    private function dayShifts(): Collection
    {
        if ($this->form['scope'] !== Message::SHIFT || ! $this->validDate($this->form['shift_date'])) {
            return collect();
        }

        $day = CarbonImmutable::parse($this->form['shift_date'], LocalTimestamp::ZONE);

        return ShiftQuery::startingOn($day, $day)
            ->with('employee:id,first_name,last_name')
            ->whereNotNull('published_at')
            ->whereNotNull('employee_id')
            ->where('status', '<>', Shift::CANCELLED)
            ->orderBy('starts_at')
            ->get();
    }

    /** How many the message would reach as the form stands, or null while it is incomplete. */
    private function audience(): ?int
    {
        $form = $this->form;

        try {
            return match ($form['scope']) {
                Message::SHIFT => $form['shift_id'] === '' ? null : count(MessageAudience::employeeIds(Message::SHIFT, CarbonImmutable::today(), CarbonImmutable::today(), $form['shift_id'])),
                Message::DAY => $this->validDate($form['date_from']) ? count(MessageAudience::employeeIds(Message::DAY, $this->date($form['date_from']), $this->date($form['date_from']))) : null,
                Message::AREA => $this->validRange() && $form['area_id'] ? count(MessageAudience::employeeIds(Message::AREA, $this->date($form['date_from']), $this->date($form['date_to']), null, (int) $form['area_id'])) : null,
                default => $this->validRange() ? count(MessageAudience::employeeIds($form['scope'], $this->date($form['date_from']), $this->date($form['date_to']))) : null,
            };
        } catch (Throwable) {
            return null;
        }
    }

    private function validRange(): bool
    {
        return $this->validDate($this->form['date_from']) && $this->validDate($this->form['date_to'])
            && $this->form['date_from'] <= $this->form['date_to']
            && $this->date($this->form['date_from'])->diffInDays($this->date($this->form['date_to'])) < Message::MAX_DAYS;
    }

    private function validDate(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4));
    }

    private function date(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, LocalTimestamp::ZONE)->startOfDay();
    }
}
