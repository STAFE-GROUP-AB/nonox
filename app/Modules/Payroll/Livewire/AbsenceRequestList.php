<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Modules\Payroll\Actions\AbsenceRequests;
use App\Modules\Payroll\Models\Absence;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Lön → Ansökningar: sickness reports and vacation applications from Min
 * sida, approved with hours per day or refused with a reason.
 */
class AbsenceRequestList extends Component
{
    use ShowsToasts;

    /** @var array<int, string> hours per day, by absence id */
    public array $hours = [];

    public ?int $rejecting = null;

    public string $reason = '';

    public function mount(): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), 'payroll:run'), 403);
    }

    public function approve(int $absenceId, AbsenceRequests $requests): void
    {
        try {
            $absence = $requests->approve($this->user(), Absence::query()->findOrFail($absenceId), $this->hours[$absenceId] ?? null);
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        $this->toast(__('Godkänd. Frånvaron räknas med i lönekörningen för :month.', ['month' => $absence->date_from->translatedFormat('F Y')]));
    }

    public function reject(AbsenceRequests $requests): void
    {
        if ($this->rejecting === null) {
            return;
        }

        $requests->reject($this->user(), Absence::query()->findOrFail($this->rejecting), $this->reason);
        $this->reset('rejecting', 'reason');
        $this->toast(__('Ansökan är avslagen. Den anställde ser beslutet och skälet på Min sida.'));
    }

    public function render(AbsenceRequests $requests): View
    {
        $pending = Absence::query()->with('employee:id,employee_number,first_name,last_name')->where('status', 'REQUESTED')->orderBy('date_from')->get();

        foreach ($pending as $absence) {
            $this->hours[$absence->id] ??= $requests->defaultHours($this->company(), $absence);
        }

        return view('payroll::livewire.absence-requests', [
            'pending' => $pending,
            'decided' => Absence::query()->with('employee:id,first_name,last_name')->whereNotNull('requested_by')->whereIn('status', ['APPROVED', 'REJECTED'])->latest('decided_at')->limit(10)->get(),
        ]);
    }

    private function company(): Company
    {
        /** @var Company $company */
        $company = $this->user()->currentCompany();

        return $company;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
