<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Modules\Payroll\Actions\AbsenceRequests;
use App\Modules\Payroll\Models\Absence;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Support\Calendar\SwedishHolidays;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Min sida: report sickness or care of a sick child, apply for vacation and
 * follow what has been decided.
 */
class MyAbsence extends Component
{
    use ShowsToasts;

    public ?string $form = null;

    /** @var array<string, string> */
    public array $absence = ['date_from' => '', 'date_to' => '', 'note' => ''];

    public function open(string $type): void
    {
        abort_unless(in_array($type, AbsenceRequests::TYPES, true), 404);

        $today = CarbonImmutable::today()->toDateString();
        $this->form = $type;
        $this->absence = ['date_from' => $today, 'date_to' => $today, 'note' => ''];
        $this->resetErrorBag();
    }

    public function send(AbsenceRequests $requests): void
    {
        if ($this->form === null) {
            return;
        }

        $requests->request($this->user(), $this->company(), ['type' => $this->form, ...$this->absence]);

        $this->toast(match ($this->form) {
            'VACATION' => __('Din semesteransökan är skickad. Du ser här när den är besvarad.'),
            'VAB' => __('Din anmälan om vård av barn är skickad. Krya på er!'),
            default => __('Din sjukanmälan är skickad. Krya på dig!'),
        });
        $this->form = null;
    }

    public function cancel(int $absenceId, AbsenceRequests $requests): void
    {
        $requests->cancel($this->user(), Absence::query()->findOrFail($absenceId));
        $this->toast(__('Ansökan är återkallad.'));
    }

    public function render(): View
    {
        $employee = Employee::query()->where('user_id', $this->user()->id)->first();

        return view('payroll::livewire.my-absence', [
            'employee' => $employee,
            'requests' => $employee === null ? collect() : Absence::query()
                ->where('employee_id', $employee->id)
                ->whereNotNull('requested_by')
                ->latest('date_from')
                ->limit(10)
                ->get(),
            'vacationDays' => $this->form === 'VACATION' ? $this->vacationDays() : null,
        ]);
    }

    /** Semesterdagar in the dates applied for (semesterlagen 3 a §). */
    private function vacationDays(): ?int
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->absence['date_from']) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->absence['date_to']) !== 1) {
            return null;
        }

        $from = CarbonImmutable::parse($this->absence['date_from']);
        $to = CarbonImmutable::parse($this->absence['date_to']);

        if ($to->lessThan($from) || $from->diffInDays($to) > 366) {
            return null;
        }

        $days = 0;

        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $days += SwedishHolidays::isVacationDay($day) ? 1 : 0;
        }

        return $days;
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
