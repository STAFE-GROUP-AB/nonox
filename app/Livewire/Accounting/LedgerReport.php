<?php

declare(strict_types=1);

namespace App\Livewire\Accounting;

use App\Domain\Closing\LedgerReconciliation;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Rapporter → Reskontra: the open customer or supplier invoices on a chosen
 * day against the balance of 1510 or 2440 that day (#57).
 */
class LedgerReport extends Component
{
    #[Url(as: 'typ', except: LedgerReconciliation::CUSTOMERS)]
    public string $side = LedgerReconciliation::CUSTOMERS;

    #[Url(as: 'datum', except: '')]
    public string $date = '';

    public function mount(): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), 'reports:view'), 403);

        if (! in_array($this->side, [LedgerReconciliation::CUSTOMERS, LedgerReconciliation::SUPPLIERS], true)) {
            $this->side = LedgerReconciliation::CUSTOMERS;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date) !== 1) {
            $this->date = now()->toDateString();
        }
    }

    public function render(): View
    {
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date) === 1 ? CarbonImmutable::parse($this->date) : CarbonImmutable::today();

        return view('livewire.accounting.ledger-report', [
            'report' => LedgerReconciliation::on($this->side, $day),
            'day' => $day,
            'company' => $this->company(),
        ]);
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
