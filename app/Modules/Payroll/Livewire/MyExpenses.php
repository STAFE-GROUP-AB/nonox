<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Modules\Payroll\Actions\ExpenseClaims;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\Expense;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Min sida: claim an outlay with a photo of the receipt and follow it until
 * it is repaid with the pay.
 */
class MyExpenses extends Component
{
    use ShowsToasts;
    use WithFileUploads;

    public bool $open = false;

    /** @var array<string, string> */
    public array $expense = ['date' => '', 'description' => '', 'amount' => '', 'vat' => ''];

    public ?TemporaryUploadedFile $receipt = null;

    public function start(): void
    {
        $this->open = true;
        $this->expense = ['date' => now()->toDateString(), 'description' => '', 'amount' => '', 'vat' => ''];
        $this->receipt = null;
        $this->resetErrorBag();
    }

    public function send(ExpenseClaims $claims): void
    {
        if (! $this->receipt instanceof TemporaryUploadedFile) {
            $this->addError('receipt', __('Lägg till en bild eller PDF av kvittot.'));

            return;
        }

        $claims->submit($this->user(), $this->company(), $this->expense, $this->receipt);

        $this->reset('open', 'receipt');
        $this->toast(__('Utlägget är skickat. Det betalas tillbaka med lönen när det är godkänt.'));
    }

    public function render(): View
    {
        $employee = Employee::query()->where('user_id', $this->user()->id)->first();

        return view('payroll::livewire.my-expenses', [
            'employee' => $employee,
            'expenses' => $employee === null ? collect() : Expense::query()->where('employee_id', $employee->id)->whereNotNull('requested_by')->latest('date')->limit(10)->get(),
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
