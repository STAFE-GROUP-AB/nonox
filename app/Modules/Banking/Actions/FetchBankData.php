<?php

declare(strict_types=1);

namespace App\Modules\Banking\Actions;

use App\Domain\Invoicing\IncomingPaymentMatcher;
use App\Models\BankTransaction;
use App\Models\Company;
use App\Models\User;
use App\Modules\Banking\BankingModule;
use App\Modules\Banking\Contracts\BankDataProvider;
use App\Modules\Banking\Domain\ConnectionStatus;
use App\Modules\Banking\Domain\FetchResult;
use App\Modules\Banking\Domain\ProviderTransaction;
use App\Modules\Banking\Models\BankConnection;
use App\Modules\Banking\Models\BankFeedAccount;
use App\Modules\Banking\Models\BankFeedBalance;
use App\Modules\Banking\Models\BankFeedTransaction;
use App\Modules\Banking\Support\BankDataProviders;
use App\Modules\Banking\Support\ConsentEnded;
use App\Modules\Banking\Support\ProviderFailed;
use App\Modules\ModuleManager;
use App\Support\Audit\Audit;
use App\Support\Camt054\BankCredit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Fetches booked transactions and balances for a connection's mapped
 * accounts (ADR-034). Idempotent: each transaction is stored once per
 * account by its fingerprint (the bank's id, else its content), and every
 * fetch goes a few days back to catch late bookings. A credit that pays an
 * unpaid invoice, or carries an OCR number, also becomes an incoming
 * payment (bank_transactions) with the invoice suggested, exactly as from a
 * camt.054 file. Nothing is booked here: a person confirms every match and
 * every payment.
 */
class FetchBankData
{
    /** How far back the first fetch goes; most banks give 90 days without a new BankID. */
    public const int FIRST_DAYS = 90;

    /** Each fetch repeats the last days, for transactions the bank books late. */
    public const int OVERLAP_DAYS = 5;

    private const int MAX_PAGES = 200;

    public function __invoke(Company $company, BankConnection $connection, ?User $by = null): FetchResult
    {
        if (! app(ModuleManager::class)->isEnabled(BankingModule::KEY, $company)) {
            return new FetchResult(error: 'module-off');
        }

        $lock = Cache::lock("banking:fetch:{$connection->id}", 600);

        if (! $lock->get()) {
            return new FetchResult(error: 'busy');
        }

        try {
            return CurrentCompany::run($company, fn (): FetchResult => $this->fetch($company, $connection, $by));
        } finally {
            $lock->release();
        }
    }

    private function fetch(Company $company, BankConnection $connection, ?User $by): FetchResult
    {
        if (! $connection->isActive()) {
            return new FetchResult(error: 'inactive');
        }

        $provider = BankDataProviders::make($connection->provider);
        $sessionId = (string) $connection->session_id;
        $accounts = $connection->accounts()->where('is_active', true)->whereNotNull('account_id')->get();
        $totals = ['new' => 0, 'skipped' => 0, 'payments' => 0];

        try {
            foreach ($accounts as $account) {
                $counts = $this->fetchAccount($provider, $sessionId, $account);

                foreach ($counts as $key => $count) {
                    $totals[$key] += $count;
                }
            }
        } catch (ConsentEnded) {
            $connection->forceFill(['status' => ConnectionStatus::Expired, 'session_id' => null, 'last_error' => null])->save();
            Audit::record('banking.consent_ended', $connection, ['bank' => $connection->institution_name, ...$totals], $company, $by);

            return new FetchResult(...$totals, consentEnded: true);
        } catch (ProviderFailed $exception) {
            $connection->forceFill(['last_error' => mb_substr($exception->getMessage(), 0, 500)])->save();
            Audit::record('banking.fetch_failed', $connection, ['bank' => $connection->institution_name, 'error' => $exception->getMessage(), ...$totals], $company, $by);

            return new FetchResult(...$totals, error: $exception->getMessage());
        }

        $connection->forceFill(['last_fetched_at' => now(), 'last_error' => null])->save();
        Audit::record('banking.fetched', $connection, ['bank' => $connection->institution_name, 'accounts' => $accounts->count(), ...$totals], $company, $by);

        return new FetchResult(...$totals);
    }

    /** @return array{new: int, skipped: int, payments: int} */
    private function fetchAccount(BankDataProvider $provider, string $sessionId, BankFeedAccount $account): array
    {
        $today = CarbonImmutable::today();
        $from = $account->fetched_through === null
            ? $today->subDays(self::FIRST_DAYS)
            : $account->fetched_through->subDays(self::OVERLAP_DAYS);

        $fetched = [];
        $continuation = null;
        $pages = 0;

        do {
            $page = $provider->transactions($sessionId, $account->provider_account_id, $from, $continuation);
            $fetched = [...$fetched, ...$page->transactions];
            $continuation = $page->continuation;
        } while ($continuation !== null && ++$pages < self::MAX_PAGES);

        $balances = $provider->balances($sessionId, $account->provider_account_id);

        return DB::transaction(function () use ($account, $fetched, $balances, $today): array {
            $byFingerprint = [];
            $seen = [];

            foreach ($fetched as $transaction) {
                $key = $transaction->contentKey();
                $seen[$key] = ($seen[$key] ?? 0) + 1;
                $byFingerprint[$transaction->fingerprint($seen[$key])] = $transaction;
            }

            $known = BankFeedTransaction::query()->where('bank_feed_account_id', $account->id)
                ->whereIn('fingerprint', array_keys($byFingerprint))->pluck('fingerprint')->all();
            $new = array_diff_key($byFingerprint, array_flip($known));
            $payments = $this->incomingPayments($account, $new);

            foreach ($new as $fingerprint => $transaction) {
                BankFeedTransaction::create([
                    'bank_feed_account_id' => $account->id,
                    'fingerprint' => (string) $fingerprint,
                    'provider_transaction_id' => $transaction->transactionId,
                    'entry_reference' => $transaction->entryReference,
                    'booking_date' => $transaction->bookingDate,
                    'value_date' => $transaction->valueDate,
                    'amount_ore' => $transaction->amountOre,
                    'currency' => $transaction->currency,
                    'counterparty' => $transaction->counterparty,
                    'message' => $transaction->message,
                    'reference' => $transaction->reference,
                    'bank_transaction_id' => $payments[$fingerprint] ?? null,
                ]);
            }

            foreach ($balances as $balance) {
                BankFeedBalance::query()->updateOrCreate(
                    ['bank_feed_account_id' => $account->id, 'balance_date' => $balance->date->toDateString(), 'balance_type' => $balance->type],
                    ['amount_ore' => $balance->amountOre, 'currency' => $balance->currency, 'fetched_at' => now()],
                );
            }

            $account->forceFill(['fetched_through' => $today, 'last_fetched_at' => now()])->save();

            return ['new' => count($new), 'skipped' => count($known), 'payments' => count($payments)];
        });
    }

    /**
     * Credits in the company's currency that pay an unpaid invoice or carry
     * an OCR number become incoming payments, unless the same payment came
     * from a camt.054 file already (same bank reference). Returns the
     * incoming payment's id by fingerprint.
     *
     * @param  array<string, ProviderTransaction>  $transactions
     * @return array<string, int>
     */
    private function incomingPayments(BankFeedAccount $account, array $transactions): array
    {
        $credits = array_filter($transactions, fn (ProviderTransaction $transaction): bool => $transaction->amountOre > 0 && $transaction->currency === $account->currency);

        if ($credits === []) {
            return [];
        }

        $references = [];

        foreach ($credits as $fingerprint => $transaction) {
            $references[$fingerprint] = mb_substr($transaction->entryReference ?? $transaction->transactionId ?? "bank:{$fingerprint}", 0, 100);
        }

        $existing = BankTransaction::query()->whereIn('reference', array_values($references))->pluck('reference')->all();
        $matcher = new IncomingPaymentMatcher;
        $created = [];

        foreach ($credits as $fingerprint => $transaction) {
            if (in_array($references[$fingerprint], $existing, true)) {
                continue;
            }

            $ocr = $transaction->reference !== null && preg_match('/^\d{2,25}$/', $transaction->reference) === 1 ? $transaction->reference : null;
            $credit = new BankCredit($references[$fingerprint], $transaction->bookingDate, $transaction->amountOre, $ocr, $transaction->message, $transaction->counterparty);
            $invoice = $matcher->match($credit);

            if ($invoice === null && $ocr === null) {
                continue;
            }

            $created[$fingerprint] = BankTransaction::create([
                'reference' => $credit->reference,
                'booking_date' => $credit->bookingDate,
                'amount_ore' => $credit->amountOre,
                'ocr' => $ocr,
                'message' => $credit->message === null ? null : mb_substr($credit->message, 0, 500),
                'payer_name' => $credit->payerName,
                'invoice_id' => $invoice?->id,
            ])->id;
        }

        return $created;
    }
}
