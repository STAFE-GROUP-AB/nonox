<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use App\Modules\Payroll\Actions\AbsenceRequests;
use App\Modules\Payroll\Actions\ExpenseClaims;
use App\Modules\Payroll\Models\Absence;
use App\Modules\Payroll\Models\Expense;
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

    /** @var array<int, string> cost account per outlay */
    public array $expenseAccount = [];

    /** @var array<int, string> VAT per outlay */
    public array $expenseVat = [];

    public ?int $rejectingExpense = null;

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

    public function approveExpense(int $expenseId, ExpenseClaims $claims): void
    {
        try {
            $expense = $claims->approve($this->user(), Expense::query()->findOrFail($expenseId), (int) ($this->expenseAccount[$expenseId] ?? 0), $this->expenseVat[$expenseId] ?? null);
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        $this->toast(__('Utlägget är godkänt och bokfört. Det betalas tillbaka med nästa lön.'));
    }

    public function rejectExpense(ExpenseClaims $claims): void
    {
        if ($this->rejectingExpense === null) {
            return;
        }

        $claims->reject($this->user(), Expense::query()->findOrFail($this->rejectingExpense), $this->reason);
        $this->reset('rejectingExpense', 'reason');
        $this->toast(__('Utlägget är avslaget. Den anställde ser beslutet och skälet på Min sida.'));
    }

    public function render(AbsenceRequests $requests): View
    {
        $expenses = Expense::query()->with('employee:id,first_name,last_name')->where('status', 'SUBMITTED')->orderBy('date')->get();

        foreach ($expenses as $expense) {
            $this->expenseAccount[$expense->id] ??= '';
            $this->expenseVat[$expense->id] ??= $expense->vat_ore->format(withCurrency: false);
        }

        $pending = Absence::query()->with('employee:id,employee_number,first_name,last_name')->where('status', 'REQUESTED')->orderBy('date_from')->get();

        foreach ($pending as $absence) {
            $this->hours[$absence->id] ??= $requests->defaultHours($this->company(), $absence);
        }

        return view('payroll::livewire.absence-requests', [
            'expenses' => $expenses,
            'accounts' => Account::query()->where('is_active', true)->whereBetween('number', [4000, 7999])->orderBy('number')->get(['number', 'name']),
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
