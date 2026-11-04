<?php

declare(strict_types=1);

namespace App\Livewire\Accounting;

use App\Domain\Accounting\AccountBalances;
use App\Domain\Accounting\Reports\LedgerReports;
use App\Domain\Accounting\Reports\ReportFilters;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\FiscalYear;
use App\Models\Project;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The ledger reports, with the choices kept in the URL so a report can be
 * bookmarked or sent to the accountant (AGENTS.md §11.3).
 */
class Reports extends Component
{
    #[Url(except: 'saldobalans')]
    public string $rapport = 'saldobalans';

    #[Url(except: '')]
    public string $ar = '';

    #[Url(except: '')]
    public string $fran = '';

    #[Url(except: '')]
    public string $till = '';

    #[Url(except: '')]
    public string $kst = '';

    #[Url(except: '')]
    public string $projekt = '';

    #[Url(except: '')]
    public string $konto = '';

    #[Url(except: false)]
    public bool $jamfor = false;

    /** New opening balances change what the page shows (StaleOpeningBalancesNotice). */
    #[On('opening-balances-updated')]
    public function openingBalancesUpdated(): void {}

    public function mount(): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), 'reports:view'), 403);
    }

    public function updatedAr(): void
    {
        $this->reset('fran', 'till');
    }

    public function show(string $report): void
    {
        $this->rapport = in_array($report, ReportFilters::REPORTS, true) ? $report : 'saldobalans';
    }

    public function render(LedgerReports $reports): View
    {
        $filters = ReportFilters::fromInput($this->only(['rapport', 'ar', 'fran', 'till', 'kst', 'projekt', 'konto', 'jamfor']));

        if ($filters === null) {
            return view('livewire.accounting.reports-empty');
        }

        $query = $filters->query();
        $data = match ($filters->report) {
            'resultat' => $reports->incomeStatement($query, $filters->compare ? LedgerReports::previousPeriod($query) : null),
            'balans' => $reports->balanceSheet($query),
            'huvudbok' => $reports->generalLedger($query, $filters->account),
            'verifikationer' => $reports->voucherList($query),
            default => $reports->trialBalance($query),
        };

        return view('livewire.accounting.reports', [
            'filters' => $filters,
            'fiscalYears' => FiscalYear::query()->orderByDesc('start_date')->get(),
            'costCenters' => CostCenter::query()->orderBy('code')->get(['id', 'code', 'name']),
            'projects' => Project::query()->orderBy('code')->get(['id', 'code', 'name']),
            'data' => $data,
            // The Verifikationslista shows each account's balance after the row, all rows in one query.
            'balances' => $data instanceof Collection
                ? AccountBalances::afterRows($filters->fiscalYear->id, $data->flatMap(fn (Voucher $voucher) => $voucher->rows))
                : [],
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
