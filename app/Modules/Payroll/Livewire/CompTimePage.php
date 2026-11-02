<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Modules\Payroll\Actions\CompTimeEntries;
use App\Modules\Payroll\Models\CompTimeEntry;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Support\CompTimeLedger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Lön → Komptid (/lon/komptid, gap G8): every employee's comp-time balance,
 * one employee's entries, and registering leave taken, a payout or an
 * adjustment. Earned comp time comes from Planering. Thin: CompTimeEntries
 * does the work. A fixed number of queries whatever the number of people.
 */
class CompTimePage extends Component
{
    use ShowsToasts;

    #[Url(as: 'person')]
    public ?int $person = null;

    /** @var array{employee_id: ?int, kind: string, date: string, hours: string, note: string} */
    public array $form = ['employee_id' => null, 'kind' => CompTimeEntry::TAKEN, 'date' => '', 'hours' => '', 'note' => ''];

    public function mount(): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), 'payroll:view'), 403);
        $this->form['date'] = CarbonImmutable::today()->toDateString();
        $this->form['employee_id'] = $this->person;
    }

    public function record(CompTimeEntries $entries): void
    {
        try {
            $entry = $entries->record($this->user(), $this->company(), $this->form);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        $this->resetErrorBag();
        $this->person = $entry->employee_id;
        $this->form = ['employee_id' => $entry->employee_id, 'kind' => CompTimeEntry::TAKEN, 'date' => $this->form['date'], 'hours' => '', 'note' => ''];
        $this->toast(match ($entry->kind) {
            CompTimeEntry::PAID_OUT => __('Utbetalningen av komptid är registrerad. Den betalas i lönekörningen för månaden.'),
            CompTimeEntry::TAKEN => __('Uttaget av komptid är registrerat.'),
            default => __('Justeringen av komptid är sparad.'),
        });
    }

    public function delete(int $entryId, CompTimeEntries $entries): void
    {
        try {
            $entries->delete($this->user(), $this->company(), CompTimeEntry::query()->findOrFail($entryId));
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        $this->toast(__('Raden är borttagen ur komptidssaldot.'));
    }

    public function render(): View
    {
        $employees = Employee::query()->orderBy('first_name')->orderBy('last_name')->get(['id', 'first_name', 'last_name', 'employee_number']);
        $balances = CompTimeLedger::balances(array_values(array_map('intval', $employees->modelKeys())));
        $entries = $this->person === null ? collect() : CompTimeEntry::query()->where('employee_id', $this->person)->orderByDesc('date')->orderByDesc('id')->limit(200)->get();

        return view('payroll::livewire.comp-time', [
            'employees' => $employees,
            'balances' => $balances,
            'entries' => $entries,
            'canManage' => $this->user()->hasTeamPermission($this->company(), 'payroll:manage'),
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
