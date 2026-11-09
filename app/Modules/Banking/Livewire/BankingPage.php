<?php

declare(strict_types=1);

namespace App\Modules\Banking\Livewire;

use App\Actions\Banking\BankReconciliations;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use App\Modules\Banking\Actions\ConnectBank;
use App\Modules\Banking\Actions\FetchBankData;
use App\Modules\Banking\Actions\MapBankAccount;
use App\Modules\Banking\Actions\MatchBankTransactions;
use App\Modules\Banking\Actions\RevokeBankConnection;
use App\Modules\Banking\Domain\Institution;
use App\Modules\Banking\Http\BankAuthController;
use App\Modules\Banking\Models\BankConnection;
use App\Modules\Banking\Models\BankFeedAccount;
use App\Modules\Banking\Models\BankFeedTransaction;
use App\Modules\Banking\Support\BankDataProviders;
use App\Modules\Banking\Support\ProviderFailed;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Bank: the connected banks and their accounts, each mapped to a ledger
 * account; connecting a new bank with BankID; and the fetched transactions
 * of one account with NONOX's match proposals for a person to confirm.
 */
class BankingPage extends Component
{
    use ShowsToasts;
    use WithPagination;

    public const int PER_PAGE = 50;

    #[Url(as: 'konto', except: 0)]
    public int $feedAccount = 0;

    public bool $connecting = false;

    public string $institution = '';

    public string $psuType = 'business';

    public ?int $confirmingRevoke = null;

    public function mount(): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), 'banking:view'), 403);
    }

    public function updatedFeedAccount(): void
    {
        $this->resetPage();
    }

    public function startConnecting(): void
    {
        $this->authorizeManage();

        if (BankDataProviders::isPaused()) {
            $this->toast(BankDataProviders::pausedMessage(), 'error');

            return;
        }

        $this->connecting = true;
    }

    public function connect(ConnectBank $connect): void
    {
        $this->authorizeManage();

        try {
            ['url' => $url, 'flow' => $flow] = $connect->start($this->user(), $this->company(), $this->institution, $this->psuType, route('banking.callback'));
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->errors());
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        } catch (ProviderFailed $exception) {
            report($exception);
            $this->toast(__('Banken går inte att nå just nu. Försök igen om en stund.'), 'error');

            return;
        }

        session()->put(BankAuthController::SESSION, $flow);
        $this->redirect($url);
    }

    public function map(int $feedAccountId, string $number, MapBankAccount $map): void
    {
        $account = BankFeedAccount::query()->findOrFail($feedAccountId);

        try {
            $map($this->user(), $this->company(), $account, $number === '' ? null : (int) $number);
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        $this->toast($number === ''
            ? __(':account kopplas inte längre till bokföringen och hämtas inte.', ['account' => $account->label()])
            : __(':account hör nu till konto :number. Transaktionerna hämtas vid nästa hämtning.', ['account' => $account->label(), 'number' => $number]));
    }

    public function fetch(int $connectionId, FetchBankData $fetch): void
    {
        $this->authorizeManage();
        $connection = BankConnection::query()->findOrFail($connectionId);

        if (BankDataProviders::isPaused($connection->provider)) {
            $this->toast(BankDataProviders::pausedMessage(), 'error');

            return;
        }

        // A bank may count every fetch; one a quarter of an hour is plenty by hand.
        $lastAttempt = FetchBankData::lastAttemptAt($connection);

        if ($lastAttempt !== null && $lastAttempt->greaterThan(CarbonImmutable::now()->subMinutes(15))) {
            $this->toast(__('Banken hämtades nyss. Försök igen om en kvart.'), 'error');

            return;
        }

        $result = $fetch($this->company(), $connection, $this->user());

        match (true) {
            $result->consentEnded => $this->toast(__('Medgivandet hos :bank har gått ut. Koppla banken igen med BankID.', ['bank' => $connection->institution_name]), 'error'),
            $result->error === 'busy' => $this->toast(__('Banken hämtas redan. Vänta en stund.'), 'error'),
            $result->error !== null => $this->toast(__('Banken svarade inte som väntat. Försök igen om en stund.'), 'error'),
            default => $this->toast(__(':count nya transaktioner hämtades från :bank.', ['count' => $result->new, 'bank' => $connection->institution_name])),
        };
    }

    public function askRevoke(int $connectionId): void
    {
        $this->authorizeManage();
        $this->confirmingRevoke = BankConnection::query()->findOrFail($connectionId)->id;
    }

    public function revoke(RevokeBankConnection $revoke): void
    {
        if ($this->confirmingRevoke === null) {
            return;
        }

        $connection = BankConnection::query()->findOrFail($this->confirmingRevoke);
        $revoke($this->user(), $this->company(), $connection);

        $this->confirmingRevoke = null;
        $this->toast(__(':bank är bortkopplad. Inget mer hämtas, och det som redan hämtats finns kvar.', ['bank' => $connection->institution_name]));
    }

    public function match(int $transactionId, int $voucherRowId, MatchBankTransactions $matcher): void
    {
        try {
            $matcher->confirm($this->user(), $this->company(), BankFeedTransaction::query()->findOrFail($transactionId), $voucherRowId);
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        $this->toast(__('Transaktionen är matchad mot bokföringen.'));
    }

    public function matchAll(MatchBankTransactions $matcher): void
    {
        $account = $this->selectedAccount();

        if ($account === null) {
            return;
        }

        $proposals = $matcher->proposals($account, $this->transactions($account)->getCollection());
        $count = 0;

        foreach ($proposals as $transactionId => $row) {
            try {
                $matcher->confirm($this->user(), $this->company(), BankFeedTransaction::query()->findOrFail($transactionId), $row->id);
                $count++;
            } catch (ValidationException) {
                continue;
            }
        }

        $this->toast($count === 1 ? __('1 transaktion är matchad.') : __(':count transaktioner är matchade.', ['count' => $count]));
    }

    public function unmatch(int $transactionId, MatchBankTransactions $matcher): void
    {
        $matcher->unmatch($this->user(), $this->company(), BankFeedTransaction::query()->findOrFail($transactionId));
        $this->toast(__('Matchningen är borttagen.'));
    }

    public function render(MatchBankTransactions $matcher): View
    {
        $connections = BankConnection::query()->with(['accounts' => fn ($query) => $query->where('is_active', true)->with('ledgerAccount:id,number,name')->orderBy('id')])
            ->orderByDesc('connected_at')->get();
        $mapped = $connections->flatMap(fn (BankConnection $connection) => $connection->accounts)->filter(fn (BankFeedAccount $account): bool => $account->account_id !== null)->values();

        if ($this->feedAccount === 0 || ! $mapped->contains('id', $this->feedAccount)) {
            $this->feedAccount = (int) ($mapped->first()->id ?? 0);
        }

        $account = $mapped->firstWhere('id', $this->feedAccount);
        $transactions = $account instanceof BankFeedAccount ? $this->transactions($account) : null;

        return view('banking::livewire.page', [
            'connections' => $connections,
            'mapped' => $mapped,
            'selected' => $account,
            'transactions' => $transactions,
            'proposals' => $account instanceof BankFeedAccount && $transactions !== null ? $matcher->proposals($account, $transactions->getCollection()) : [],
            'ledgerAccounts' => Account::query()->whereBetween('number', [BankReconciliations::FIRST_ACCOUNT, BankReconciliations::LAST_ACCOUNT])->where('is_active', true)->orderBy('number')->get(['id', 'number', 'name']),
            'institutions' => $this->connecting && ! BankDataProviders::isPaused() ? $this->institutions() : [],
            'paused' => BankDataProviders::isPaused(),
            'canManage' => $this->user()->hasTeamPermission($this->company(), 'banking:manage'),
            'canMatch' => $this->user()->hasTeamPermission($this->company(), 'vouchers:post'),
            'today' => CarbonImmutable::today(),
        ]);
    }

    /** @return LengthAwarePaginator<int, BankFeedTransaction> */
    private function transactions(BankFeedAccount $account): LengthAwarePaginator
    {
        return BankFeedTransaction::query()->where('bank_feed_account_id', $account->id)
            ->with(['voucherRow.voucher.series', 'incomingPayment.invoice:id,number,external_ref,import_source,ocr,customer_snapshot,total_ore,paid_ore,status,kind,due_date'])
            ->orderByDesc('booking_date')->orderByDesc('id')
            ->paginate(self::PER_PAGE);
    }

    /**
     * The banks to choose from, cached an hour per driver as plain arrays.
     *
     * @return list<Institution>
     */
    private function institutions(): array
    {
        try {
            /** @var list<array{name: string, country: string, psuTypes: list<string>, maxConsentDays: int, bic: ?string, beta: bool}> $cached */
            $cached = Cache::remember(
                'banking:institutions:v2:'.BankDataProviders::driver().':SE',
                3600,
                fn (): array => array_map(fn (Institution $institution): array => $institution->toArray(), BankDataProviders::make()->institutions('SE')),
            );

            return array_map(Institution::fromArray(...), $cached);
        } catch (ProviderFailed $exception) {
            report($exception);

            return [];
        }
    }

    private function selectedAccount(): ?BankFeedAccount
    {
        return $this->feedAccount === 0 ? null : BankFeedAccount::query()->where('is_active', true)->whereNotNull('account_id')->find($this->feedAccount);
    }

    private function authorizeManage(): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), 'banking:manage'), 403);
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
