<?php

declare(strict_types=1);

namespace App\Modules\Connect\Livewire;

use App\Models\Company;
use App\Models\User;
use App\Modules\Connect\Actions\CreateTestCompany;
use App\Support\Toast;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Under Anslutningar (ADR-032): an owner or administrator can make a test
 * company with demo data, to try the API (or let an integrator try it)
 * without touching real bookkeeping.
 */
class TestCompanyPanel extends Component
{
    public const string NAME = 'connect.test-company-panel';

    public string $name = '';

    public function mount(): void
    {
        abort_unless($this->canCreate(), 403);
    }

    public function create(CreateTestCompany $create): void
    {
        abort_unless($this->canCreate(), 403);

        $company = $create($this->user(), $this->name === '' ? null : $this->name);

        Toast::flash(__(':name är skapat med exempeluppgifter. Du arbetar nu i testföretaget.', ['name' => $company->name]));
        $this->redirectRoute('dashboard');
    }

    public function render(): View
    {
        return view('connect::livewire.test-company-panel', [
            'owned' => Company::query()->where('user_id', $this->user()->id)->where('is_test_company', true)->whereNull('archived_at')->orderBy('name')->get(['id', 'name']),
            'max' => CreateTestCompany::MAX_PER_USER,
        ]);
    }

    private function canCreate(): bool
    {
        $company = $this->user()->currentCompany();

        return $company instanceof Company && $this->user()->hasTeamPermission($company, 'connect:manage');
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
