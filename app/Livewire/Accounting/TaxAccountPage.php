<?php

declare(strict_types=1);

namespace App\Livewire\Accounting;

use App\Actions\TaxAccount\BookTaxAccountTransaction;
use App\Actions\TaxAccount\ReconcileTaxAccount;
use App\Domain\TaxAccount\TaxAccountMatcher;
use App\Domain\TaxAccount\TaxAccountProposals;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Account;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Support\Skatteverket\Skatteverket;
use App\Support\Skatteverket\SkatteverketAuth;
use App\Support\Skatteverket\SkatteverketFailed;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * @phpstan-import-type Reconciliation from ReconcileTaxAccount
 *
 * Bokföring → Skattekontot (ADR-054 stage 3): the company's tax account at
 * Skatteverket next to account 1630, and the transactions that are not in
 * the books, each booked only when a person confirms it.
 */
class TaxAccountPage extends Component
{
    use ShowsToasts;

    /** Fetches per person and minute: each fetch is three calls to Skatteverket, which limits calls per second. */
    public const int FETCHES_PER_MINUTE = 5;

    #[Url(as: 'datum', except: '')]
    public string $date = '';

    public string $from = '';

    /** @var array<int|string, string> the chosen counter account per transaction id */
    public array $accounts = [];

    /** @var ?Reconciliation what Skatteverket answered and the reconciliation, as shown; never stored */
    #[Locked]
    public ?array $result = null;

    public function mount(): void
    {
        abort_unless(Skatteverket::isAvailableTo($this->user()), 404);
        abort_unless($this->user()->hasTeamPermission($this->company(), 'reports:view'), 403);

        $today = CarbonImmutable::today();

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date) !== 1) {
            $this->date = $today->toDateString();
        }

        $year = FiscalYear::query()->whereDate('start_date', '<=', $this->date)->whereDate('end_date', '>=', $this->date)->first();
        $this->from = ($year->start_date ?? CarbonImmutable::parse($this->date)->startOfYear())->toDateString();
    }

    public function fetch(ReconcileTaxAccount $reconcile): void
    {
        $key = "tax-account:{$this->user()->id}";

        if (RateLimiter::tooManyAttempts($key, self::FETCHES_PER_MINUTE)) {
            $this->toast(__('Du har hämtat skattekontot många gånger på kort tid. Vänta en minut och försök igen.'), 'error');

            return;
        }

        RateLimiter::hit($key, 60);

        $this->attempt(function () use ($reconcile): void {
            $this->load($reconcile);
            $this->toast(__('Skattekontot är hämtat från Skatteverket.'));
        });
    }

    public function book(int $transactionId, BookTaxAccountTransaction $book, ReconcileTaxAccount $reconcile): void
    {
        if ($this->result === null) {
            return;
        }

        $this->attempt(function () use ($transactionId, $book, $reconcile): void {
            $account = (int) ($this->accounts[$transactionId] ?? 0);

            if ($account === 0) {
                throw ValidationException::withMessages(['account' => __('Välj ett konto att bokföra mot.')]);
            }

            $voucher = $book($this->user(), $this->company(), $transactionId, $account, CarbonImmutable::parse($this->result['date'] ?? ''), CarbonImmutable::parse($this->result['from'] ?? ''));
            $this->toast(__('Transaktionen är bokförd som verifikation :reference.', ['reference' => $voucher->reference()]));
            $this->load($reconcile);
        });
    }

    public function signOut(): void
    {
        SkatteverketAuth::forget($this->user());
        $this->result = null;
        $this->toast(__('Du är utloggad från Skatteverket i NONOX.'));
    }

    public function render(): View
    {
        $company = $this->company();
        $canBook = $this->user()->hasTeamPermission($company, 'vouchers:post');
        $missing = $this->result !== null && collect($this->result['transactions'])->contains('status', TaxAccountMatcher::MISSING);

        return view('livewire.accounting.tax-account-page', [
            'signedIn' => SkatteverketAuth::isSignedIn($this->user(), Skatteverket::SCOPE_SKATTEKONTO),
            'canBook' => $canBook,
            'test' => Skatteverket::isTest(),
            'accountOptions' => $canBook && $missing ? $this->accountOptions() : [],
            'back' => route('accounting.taxAccount', absolute: false),
        ]);
    }

    private function load(ReconcileTaxAccount $reconcile): void
    {
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date) === 1 ? CarbonImmutable::parse($this->date) : null;
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->from) === 1 ? CarbonImmutable::parse($this->from) : null;

        if ($date === null || $from === null) {
            throw ValidationException::withMessages(['date' => __('Datumet ska skrivas som ÅÅÅÅ-MM-DD.')]);
        }

        $this->result = $reconcile($this->user(), $this->company(), $date, $from);
        $this->accounts = [];

        foreach ($this->result['transactions'] as $row) {
            if ($row['status'] === TaxAccountMatcher::MISSING) {
                $this->accounts[$row['id']] = $row['proposal'] === null ? '' : (string) $row['proposal'];
            }
        }
    }

    /**
     * The active accounts to book against, the common ones first.
     *
     * @return list<array{number: int, name: string}>
     */
    private function accountOptions(): array
    {
        $accounts = Account::query()->where('is_active', true)->where('number', '!=', TaxAccountProposals::TAX_ACCOUNT)->orderBy('number')->get(['number', 'name']);
        $common = array_flip(TaxAccountProposals::COMMON);

        return array_values($accounts->map(fn (Account $account): array => ['number' => $account->number, 'name' => $account->name])
            ->sortBy(fn (array $account): array => [isset($common[$account['number']]) ? 0 : 1, $common[$account['number']] ?? $account['number']])
            ->all());
    }

    private function attempt(callable $call): void
    {
        try {
            $call();
        } catch (AuthorizationException) {
            $this->toast(__('Du har inte behörighet att göra det.'), 'error');
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');
        } catch (SkatteverketFailed $exception) {
            if ($exception->needsSignIn()) {
                SkatteverketAuth::forget($this->user());
            }

            if ($exception->status >= 500) {
                report($exception);
            }

            $this->toast($exception->userMessage, 'error');
        }
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
