<?php

declare(strict_types=1);

namespace App\Livewire\Accounting;

use App\Actions\Banking\BankReconciliations;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Account;
use App\Models\BankReconciliation;
use App\Models\Company;
use App\Models\User;
use App\Models\VoucherRow;
use App\Modules\Banking\Support\BankFeedReconciliation;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Bokföring → Bankavstämning: one bank or cash account against the bank's
 * statement on a date.
 */
class BankReconciliationPage extends Component
{
    use ShowsToasts;

    #[Url(as: 'konto', except: 0)]
    public int $account = 0;

    #[Url(as: 'datum', except: '')]
    public string $date = '';

    public string $statement = '';

    /** @var list<int> rows booked but not yet on the statement */
    public array $outstanding = [];

    public string $explanation = '';

    public function mount(): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), 'vouchers:post'), 403);

        $accounts = BankReconciliations::accounts($this->company());
        $numbers = array_map(fn (array $row): int => $row['account']->number, $accounts);

        if (! in_array($this->account, $numbers, true)) {
            $this->account = in_array(1930, $numbers, true) ? 1930 : ($numbers[0] ?? 0);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date) !== 1) {
            $this->date = now()->subMonthNoOverflow()->endOfMonth()->toDateString();
        }

        $this->carryOutstanding();
    }

    public function updatedAccount(): void
    {
        $this->carryOutstanding();
    }

    public function updatedDate(): void
    {
        $this->carryOutstanding();
    }

    public function save(BankReconciliations $reconciliations): void
    {
        $account = $this->selectedAccount();

        if ($account === null) {
            return;
        }

        try {
            $reconciliation = $reconciliations->save($this->user(), $this->company(), $account, $this->date, $this->statement, array_map('intval', $this->outstanding), $this->explanation);
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        $this->reset('statement', 'explanation');
        $this->toast($reconciliation->isBalanced()
            ? __('Konto :account stämmer per :date. Avstämningen är sparad.', ['account' => $account->number, 'date' => $this->date])
            : __('Avstämningen av konto :account är sparad med en skillnad på :amount.', ['account' => $account->number, 'amount' => $reconciliation->difference_ore->format()]));
    }

    /**
     * Fills in the balance NONOX Banking fetched (ADR-034) and proposes as
     * outstanding the rows no bank transaction is matched to. The person
     * checks and saves as usual.
     */
    public function useBankFeed(BankReconciliations $reconciliations): void
    {
        $account = $this->selectedAccount();

        if ($account === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date) !== 1) {
            return;
        }

        $date = CarbonImmutable::parse($this->date);
        $feed = BankFeedReconciliation::balance($this->company(), $account, $date);

        if ($feed === null) {
            $this->toast(__('Det finns inget saldo från banken för datumet.'), 'error');

            return;
        }

        try {
            $prepared = $reconciliations->prepare($this->company(), $account, $date);
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        $this->statement = ($feed['balance_ore'] < 0 ? '-' : '').intdiv(abs($feed['balance_ore']), 100).','.str_pad((string) (abs($feed['balance_ore']) % 100), 2, '0', STR_PAD_LEFT);
        $this->outstanding = BankFeedReconciliation::outstanding($this->company(), $account, $date, $prepared['rows'], array_map('intval', $this->outstanding));
        $this->toast(__('Bankens saldo är ifyllt, och det som inte syns hos banken är markerat. Kontrollera och spara.'));
    }

    public function render(BankReconciliations $reconciliations): View
    {
        $accounts = BankReconciliations::accounts($this->company());
        $account = $this->selectedAccount();
        $prepared = null;
        $error = null;

        if ($account !== null) {
            try {
                $prepared = $reconciliations->prepare($this->company(), $account, CarbonImmutable::parse($this->date));
            } catch (ValidationException $exception) {
                $error = (string) collect($exception->errors())->flatten()->first();
            }
        }

        $outstandingOre = $prepared === null ? 0 : (int) $prepared['rows']->whereIn('id', $this->outstanding)
            ->sum(fn (VoucherRow $row): int => $row->debit_ore->ore - $row->credit_ore->ore);

        try {
            $statement = trim($this->statement) === '' ? null : Money::parse($this->statement)->ore;
        } catch (InvalidArgumentException) {
            $statement = null;
        }

        return view('livewire.accounting.bank-reconciliation', [
            'accounts' => $accounts,
            'selected' => $account,
            'prepared' => $prepared,
            'error' => $error,
            'outstandingOre' => $outstandingOre,
            'expected' => $prepared === null ? null : $prepared['ledger'] - $outstandingOre,
            'statementOre' => $statement,
            'difference' => $prepared === null || $statement === null ? null : $statement - ($prepared['ledger'] - $outstandingOre),
            'feed' => $account === null || $prepared === null ? null : BankFeedReconciliation::balance($this->company(), $account, CarbonImmutable::parse($this->date)),
            'history' => $account === null ? collect() : BankReconciliation::query()->where('account_id', $account->id)->with('reconciler')
                ->orderByDesc('statement_date')->orderByDesc('id')->limit(12)->get(),
        ]);
    }

    /** Rows the previous reconciliation left outstanding stay outstanding until they are unmarked. */
    private function carryOutstanding(): void
    {
        $account = $this->selectedAccount();
        $this->outstanding = [];

        if ($account === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date) !== 1) {
            return;
        }

        $previous = BankReconciliation::query()->where('account_id', $account->id)->whereDate('statement_date', '<', $this->date)
            ->orderByDesc('statement_date')->orderByDesc('id')->first();
        $this->outstanding = array_map('intval', $previous->outstanding_row_ids ?? []);
    }

    private function selectedAccount(): ?Account
    {
        return $this->account === 0 ? null : Account::query()->where('number', $this->account)
            ->whereBetween('number', [BankReconciliations::FIRST_ACCOUNT, BankReconciliations::LAST_ACCOUNT])->first();
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
