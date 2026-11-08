<?php

declare(strict_types=1);

namespace App\Livewire\Accounting;

use App\Domain\Accounting\LedgerVerifier;
use App\Domain\Accounting\VoucherStatus;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Models\Voucher;
use App\Support\Audit\Audit;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class VoucherList extends Component
{
    use ShowsToasts;
    use WithPagination;

    #[Url(as: 'sok', except: '')]
    public string $search = '';

    #[Url(as: 'visa', except: 'alla')]
    public string $status = 'alla';

    #[Url(as: 'ar', except: null)]
    public ?int $fiscalYearId = null;

    public function mount(): void
    {
        $this->fiscalYearId ??= FiscalYear::query()
            ->where('start_date', '<=', now())
            ->where('end_date', '>=', now())
            ->value('id');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'fiscalYearId'], true)) {
            $this->resetPage();
        }
    }

    public function checkLedger(LedgerVerifier $verifier): void
    {
        $company = $this->company();

        // A full hash-chain check is heavy: only for those who see the vouchers, a few times a minute.
        abort_unless((bool) Auth::guard('web')->user()?->hasTeamPermission($company, 'vouchers:view'), 403);

        if (RateLimiter::tooManyAttempts("ledger-check:{$company->id}", 3)) {
            $this->toast(__('Kontrollen kördes nyss. Vänta en minut och försök igen.'), 'error');

            return;
        }

        RateLimiter::hit("ledger-check:{$company->id}", 60);
        $result = $verifier->verify($company);

        Audit::record('ledger.verified', $company, [
            'ok' => $result['ok'],
            'vouchers' => $result['vouchers'],
            'problems' => $result['problems'],
        ], $company);

        $this->toast(
            $result['ok']
                ? __('Bokföringen är kontrollerad: :count verifikationer, allt stämmer.', ['count' => $result['vouchers']])
                : __('Kontrollen hittade avvikelser. Se listan ovanför verifikationerna.'),
            $result['ok'] ? 'success' : 'error',
        );
    }

    public function render(): View
    {
        $company = $this->company();

        return view('livewire.accounting.voucher-list', [
            'vouchers' => $this->vouchers(),
            'fiscalYears' => FiscalYear::query()->orderByDesc('start_date')->get(),
            'lastCheck' => AuditLog::forCompany($company)->where('action', 'ledger.verified')->latest('id')->first(),
            'canCreate' => $this->user()->can('create', [Voucher::class, $company]),
        ]);
    }

    /** @return LengthAwarePaginator<int, Voucher> */
    private function vouchers(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return Voucher::query()
            ->with('series')
            ->withSum('rows as debit_total', 'debit_ore')
            ->when($this->fiscalYearId !== null, fn ($query) => $query->where('fiscal_year_id', $this->fiscalYearId))
            ->when($this->status === 'bokforda', fn ($query) => $query->where('status', VoucherStatus::Posted->value))
            ->when($this->status === 'utkast', fn ($query) => $query->where('status', VoucherStatus::Draft->value))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('description', 'ilike', '%'.addcslashes($search, '%_\\').'%');

                    if (preg_match('/^[A-Za-z]?(\d+)$/', $search, $match)) {
                        $query->orWhere('number', (int) $match[1]);
                    }
                });
            })
            ->orderByRaw("CASE WHEN status = 'DRAFT' THEN 0 ELSE 1 END")
            ->orderByDesc('voucher_date')
            ->orderByDesc('number')
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
