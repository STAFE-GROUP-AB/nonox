<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Actions\CloseVacationYear;
use App\Modules\Payroll\Models\VacationYearClosing;
use App\Modules\Payroll\PayrollModule;
use App\Modules\Payroll\Support\Vacation\VacationLedger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Lön → Semesterår: close the semesterår that has just ended, choosing the
 * days each employee saves; the rest is paid in the next payroll run.
 */
class VacationYearPage extends Component
{
    use ShowsToasts;

    /** @var array<int, string> days to save, by employee id */
    public array $save = [];

    public function mount(): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), 'payroll:run'), 403);
    }

    public function close(CloseVacationYear $close): void
    {
        $count = $close($this->user(), $this->company(), $this->lastEnded(), $this->save);

        $this->save = [];
        $this->toast(__('Semesteråret är stängt för :count anställda. Utbetalningarna kommer med i nästa lönekörning.', ['count' => $count]));
    }

    public function render(CloseVacationYear $close): View
    {
        $year = $this->lastEnded();
        $closed = VacationYearClosing::query()->with('employee:id,employee_number,first_name,last_name')->whereDate('year_start', $year->toDateString())->get();
        $preview = [];
        $error = null;

        if ($closed->isEmpty()) {
            try {
                $preview = $close->preview($this->company(), $year);
            } catch (ValidationException $exception) {
                $error = (string) collect($exception->errors())->flatten()->first();
            }
        }

        return view('payroll::livewire.vacation-year', [
            'yearStart' => $year,
            'yearEnd' => $year->addYear()->subDay(),
            'closed' => $closed,
            'preview' => $preview,
            'error' => $error,
        ]);
    }

    /** The start of the latest semesterår that has ended. */
    private function lastEnded(): CarbonImmutable
    {
        $month = (int) (app(ModuleManager::class)->settings(PayrollModule::KEY, $this->company())['vacation_year_start_month'] ?? 4);

        return VacationLedger::years(CarbonImmutable::today(), $month)['year'][0]->subYear();
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
