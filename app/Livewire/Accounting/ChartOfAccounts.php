<?php

declare(strict_types=1);

namespace App\Livewire\Accounting;

use App\Actions\Accounting\CreateAccount;
use App\Actions\Accounting\UpdateAccount;
use App\Domain\Accounting\AccountClass;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class ChartOfAccounts extends Component
{
    use ShowsToasts;
    use WithPagination;

    #[Url(as: 'sok', except: '')]
    public string $search = '';

    #[Url(as: 'klass', except: null)]
    public ?int $class = null;

    #[Url(as: 'inaktiva', except: false)]
    public bool $showInactive = false;

    public string $newNumber = '';

    public string $newName = '';

    public ?int $editingId = null;

    public string $editingName = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'class', 'showInactive'], true)) {
            $this->resetPage();
        }
    }

    public function addAccount(CreateAccount $createAccount): void
    {
        $this->resetErrorBag();

        $account = $createAccount($this->user(), $this->company(), [
            'number' => $this->newNumber,
            'name' => $this->newName,
        ]);

        $this->reset('newNumber', 'newName');
        $this->toast(__('Konto :number :name är tillagt.', ['number' => $account->number, 'name' => $account->name]));
    }

    public function startEditing(int $accountId): void
    {
        $account = Account::findOrFail($accountId);

        $this->editingId = $account->id;
        $this->editingName = $account->name;
        $this->resetErrorBag();
    }

    public function cancelEditing(): void
    {
        $this->reset('editingId', 'editingName');
    }

    public function saveName(UpdateAccount $updateAccount): void
    {
        $account = Account::findOrFail($this->editingId);

        $updateAccount($this->user(), $account, ['name' => $this->editingName]);

        $this->reset('editingId', 'editingName');
        $this->toast(__('Konto :number heter nu :name.', ['number' => $account->number, 'name' => $account->name]));
    }

    public function toggleActive(int $accountId, UpdateAccount $updateAccount): void
    {
        $account = Account::findOrFail($accountId);
        $active = ! $account->is_active;

        $updateAccount($this->user(), $account, ['is_active' => $active]);

        $this->toast($active
            ? __('Konto :number är aktiverat igen.', ['number' => $account->number])
            : __('Konto :number är inaktiverat. Det syns inte längre när du bokför.', ['number' => $account->number]));
    }

    public function render(): View
    {
        $company = $this->company();

        return view('livewire.accounting.chart-of-accounts', [
            'accounts' => $this->accounts(),
            'classes' => AccountClass::all(),
            'canManage' => $this->user()->can('create', [Account::class, $company]),
        ]);
    }

    /** @return LengthAwarePaginator<int, Account> */
    private function accounts(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return Account::query()
            ->when(! $this->showInactive, fn ($query) => $query->where('is_active', true))
            ->when($this->class !== null, fn ($query) => $query->whereBetween('number', [$this->class * 1000, $this->class * 1000 + 999]))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'ilike', '%'.addcslashes($search, '%_\\').'%');

                    if (ctype_digit($search)) {
                        $query->orWhereRaw('CAST(number AS TEXT) LIKE ?', [$search.'%']);
                    }
                });
            })
            ->orderBy('number')
            ->paginate(50);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    private function company(): Company
    {
        /** @var Company $company */
        $company = $this->user()->currentCompany();

        return $company;
    }
}
