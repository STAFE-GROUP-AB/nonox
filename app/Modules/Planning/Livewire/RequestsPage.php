<?php

declare(strict_types=1);

namespace App\Modules\Planning\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Modules\Planning\Actions\DecideRequest;
use App\Modules\Planning\Models\Area;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\Models\ShiftRequest;
use App\Modules\Planning\Support\LocalTimestamp;
use App\Modules\Planning\Support\RequestChecks;
use App\Modules\StaffLedger\Livewire\Concerns\InCurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Planering → Öppna pass och byten (/planering/forfragningar): the staff's
 * requests to take, swap or give up shifts, with the working-time checks
 * re-run on the result, to approve or reject (a swap once the colleague has
 * accepted it); swaps still waiting for the colleague; recent answers; and the open
 * shifts ahead with how many have asked for them. A fixed number of queries.
 */
class RequestsPage extends Component
{
    use InCurrentCompany;
    use ShowsToasts;

    public const int OPEN_DAYS = 28;

    public ?int $rejecting = null;

    public string $reason = '';

    public function mount(): void
    {
        abort_unless($this->allowed('planning:view'), 403);
    }

    public function approve(int $id, DecideRequest $decide): void
    {
        try {
            $warnings = $decide($this->user(), $this->company(), ShiftRequest::query()->findOrFail($id), true);
        } catch (ValidationException $exception) {
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->toast($warnings === []
            ? __('Förfrågan är godkänd. Schemat är ändrat och publicerat, och de som berörs har fått besked.')
            : trans_choice('{1} Förfrågan är godkänd med 1 varning. Schemat är ändrat och publicerat.|[2,*] Förfrågan är godkänd med :count varningar. Schemat är ändrat och publicerat.', count($warnings), ['count' => count($warnings)]));
    }

    public function startReject(int $id): void
    {
        abort_unless($this->allowed('planning:manage'), 403);
        $this->rejecting = $id;
        $this->reason = '';
    }

    public function reject(DecideRequest $decide): void
    {
        if ($this->rejecting === null) {
            return;
        }

        try {
            $decide($this->user(), $this->company(), ShiftRequest::query()->findOrFail($this->rejecting), false, $this->reason);
        } catch (ValidationException $exception) {
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->rejecting = null;
        $this->reason = '';
        $this->toast(__('Förfrågan är avslagen och personen har fått besked.'));
    }

    public function render(RequestChecks $checks): View
    {
        $now = CarbonImmutable::now(LocalTimestamp::ZONE);
        $relations = ['shift', 'counterpartShift', 'requester:id,first_name,last_name', 'counterpart:id,first_name,last_name'];
        $pending = ShiftRequest::query()->with($relations)->where('status', ShiftRequest::PENDING)->oldest('id')->get();
        $open = Shift::query()
            ->where('status', Shift::PUBLISHED)
            ->whereNull('employee_id')
            ->where('starts_at', '>', $now)
            ->where('starts_at', '<', $now->addDays(self::OPEN_DAYS))
            ->orderBy('starts_at')
            ->limit(50)
            ->get();

        return view('planning::livewire.requests', [
            'pending' => $pending,
            'warnings' => $checks->for($this->company(), $pending),
            'awaiting' => ShiftRequest::query()->with($relations)->where('status', ShiftRequest::AWAITING_COUNTERPART)->oldest('id')->get(),
            'decided' => ShiftRequest::query()->with($relations)->whereNotIn('status', ShiftRequest::OPEN)->where('updated_at', '>=', $now->subDays(14))->latest('updated_at')->limit(20)->get(),
            'open' => $open,
            'claims' => $pending->where('kind', ShiftRequest::CLAIM)->countBy('shift_id')->all(),
            'areas' => Area::query()->pluck('name', 'id'),
            'now' => $now,
            'canManage' => $this->allowed('planning:manage'),
        ]);
    }
}
