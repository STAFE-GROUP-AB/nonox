<?php

declare(strict_types=1);

namespace App\Livewire\Accounting;

use App\Actions\Accruals\Accruals;
use App\Domain\Accruals\AccrualKind;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Accrual;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Bokföring → Periodiseringar: prepaid and accrued items and the month's run.
 */
class AccrualsPage extends Component
{
    use ShowsToasts;

    /** @var array<string, mixed> */
    public array $form = [];

    public ?int $editing = null;

    public bool $creating = false;

    #[Url(as: 'manad', except: '')]
    public string $month = '';

    public ?int $showing = null;

    public function mount(): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), 'fiscal-years:manage'), 403);

        $this->month = $this->month !== '' ? $this->month : now()->subMonthNoOverflow()->format('Y-m');
    }

    public function create(): void
    {
        $kind = AccrualKind::PrepaidExpense;
        $this->form = [
            'name' => '', 'kind' => $kind->value, 'total' => '', 'balance_account' => (string) $kind->balanceAccount(),
            'result_account' => '', 'start_month' => now()->format('Y-m'), 'months' => '12', 'move_from_result' => false,
            'cost_center' => '', 'notes' => '',
        ];
        $this->creating = true;
        $this->editing = null;
        $this->resetErrorBag();
    }

    public function updatedFormKind(string $value): void
    {
        $kind = AccrualKind::tryFrom($value) ?? AccrualKind::PrepaidExpense;
        $this->form['balance_account'] = (string) $kind->balanceAccount();
        $this->form['months'] = $kind->isSpread() ? '12' : '1';
    }

    public function edit(int $accrualId): void
    {
        $accrual = Accrual::query()->with('costCenter')->findOrFail($accrualId);
        $this->form = [
            'name' => $accrual->name, 'kind' => $accrual->kind->value, 'total' => $accrual->total_ore->format(withCurrency: false),
            'balance_account' => (string) $accrual->balance_account, 'result_account' => (string) $accrual->result_account,
            'start_month' => $accrual->start_month, 'months' => (string) $accrual->months, 'move_from_result' => $accrual->move_from_result,
            'cost_center' => (string) ($accrual->costCenter->code ?? ''), 'notes' => (string) $accrual->notes,
        ];
        $this->editing = $accrual->id;
        $this->creating = true;
        $this->resetErrorBag();
    }

    public function save(Accruals $accruals): void
    {
        $accrual = $accruals->save($this->user(), $this->company(), $this->form, $this->editing === null ? null : Accrual::query()->findOrFail($this->editing));

        $this->reset('creating', 'editing', 'form');
        $this->toast(__(':name är sparad.', ['name' => $accrual->name]));
    }

    public function delete(Accruals $accruals, int $accrualId): void
    {
        $accrual = Accrual::query()->findOrFail($accrualId);

        try {
            $accruals->delete($this->user(), $accrual);
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        $this->toast(__(':name är borttagen.', ['name' => $accrual->name]));
    }

    public function book(Accruals $accruals): void
    {
        try {
            $vouchers = $accruals->run($this->user(), $this->company(), $this->month);
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        $this->toast($vouchers === []
            ? __('Det finns inget att periodisera för månaden.')
            : __('Periodiseringarna för :month är bokförda som :vouchers.', ['month' => $this->month, 'vouchers' => implode(', ', array_map(fn (Voucher $voucher): string => $voucher->reference(), $vouchers))]));
    }

    public function render(Accruals $accruals): View
    {
        $list = Accrual::query()->with('postings')->orderByDesc('start_month')->orderByDesc('number')->get();
        $preview = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month) === 1 ? $accruals->preview($this->company(), $this->month) : [];
        $months = array_map(fn (int $i): string => now()->startOfMonth()->subMonthsNoOverflow($i)->format('Y-m'), range(-1, 12));
        $open = $list->reject(fn (Accrual $accrual): bool => $accrual->isDone());
        $done = $list->filter(fn (Accrual $accrual): bool => $accrual->isDone());

        return view('livewire.accounting.accruals', [
            'open' => $open,
            'done' => $done,
            'preview' => $preview,
            'months' => $months,
            'kinds' => AccrualKind::cases(),
            'costCenters' => CostCenter::query()->orderBy('code')->get(['code', 'name']),
            'kindLocked' => $this->editing !== null && $list->firstWhere('id', $this->editing)?->postings->isNotEmpty() === true,
            'shown' => $this->showing === null ? null : $list->firstWhere('id', $this->showing),
            'balances' => [
                'prepaid' => $open->filter(fn (Accrual $accrual): bool => $accrual->kind === AccrualKind::PrepaidExpense)->sum(fn (Accrual $accrual): int => $accrual->remainingOre()),
                'deferred' => $open->filter(fn (Accrual $accrual): bool => $accrual->kind === AccrualKind::PrepaidIncome)->sum(fn (Accrual $accrual): int => $accrual->remainingOre()),
            ],
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
