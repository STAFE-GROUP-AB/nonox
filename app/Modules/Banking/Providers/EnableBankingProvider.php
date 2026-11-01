<?php

declare(strict_types=1);

namespace App\Modules\Banking\Providers;

use App\Modules\Banking\Contracts\BankDataProvider;
use App\Modules\Banking\Domain\AuthorisationStart;
use App\Modules\Banking\Domain\ConsentGrant;
use App\Modules\Banking\Domain\ConsentStatus;
use App\Modules\Banking\Domain\Institution;
use App\Modules\Banking\Domain\ProviderAccount;
use App\Modules\Banking\Domain\ProviderBalance;
use App\Modules\Banking\Domain\ProviderTransaction;
use App\Modules\Banking\Domain\TransactionPage;
use App\Modules\Banking\Support\Amount;
use App\Modules\Banking\Support\ConsentEnded;
use App\Modules\Banking\Support\ProviderFailed;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Enable Banking (https://enablebanking.com/docs/api/reference/), the
 * primary provider (ADR-034). Endpoints used: GET /aspsps, POST /auth,
 * POST /sessions, GET /sessions/{id}, DELETE /sessions/{id},
 * GET /accounts/{uid}/balances and GET /accounts/{uid}/transactions.
 * Account information only; the payment endpoints are never called.
 *
 * No PSU headers are sent: a bank that lists required_psu_headers takes all
 * of them or none (API reference), and a scheduled fetch has no user
 * present. Response bodies are never logged or stored.
 */
final class EnableBankingProvider implements BankDataProvider
{
    public const string KEY = 'enable_banking';

    /** Error codes that mean the consent is over (API reference, "Errors"). */
    private const array ENDED = ['EXPIRED_SESSION', 'CLOSED_SESSION', 'REVOKED_SESSION', 'EXPIRED_AUTHORIZATION_CODE', 'ACCOUNT_DOES_NOT_EXIST'];

    public function __construct(
        private readonly string $applicationId,
        private readonly string $privateKeyPath,
        private readonly string $baseUrl = 'https://api.enablebanking.com',
    ) {}

    public static function isConfigured(): bool
    {
        $path = (string) config('services.banking.enable_banking.private_key_path');

        return (string) config('services.banking.enable_banking.application_id') !== '' && $path !== '' && is_readable($path);
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function institutions(string $country): array
    {
        $body = $this->json($this->send(fn (): Response => $this->request()->get('/aspsps', ['country' => $country, 'service' => 'AIS'])));
        $institutions = [];

        foreach ((array) ($body['aspsps'] ?? []) as $aspsp) {
            if (! is_array($aspsp) || ! is_string($aspsp['name'] ?? null)) {
                continue;
            }

            $seconds = is_numeric($aspsp['maximum_consent_validity'] ?? null) ? (int) $aspsp['maximum_consent_validity'] : 90 * 86400;

            $institutions[] = new Institution(
                name: $aspsp['name'],
                country: is_string($aspsp['country'] ?? null) ? $aspsp['country'] : $country,
                psuTypes: array_values(array_filter((array) ($aspsp['psu_types'] ?? ['business', 'personal']), 'is_string')),
                maxConsentDays: max(1, intdiv($seconds, 86400)),
                bic: is_string($aspsp['bic'] ?? null) ? $aspsp['bic'] : null,
                beta: (bool) ($aspsp['beta'] ?? false),
            );
        }

        usort($institutions, fn (Institution $a, Institution $b): int => strcasecmp($a->name, $b->name));

        return $institutions;
    }

    public function startAuthorisation(Institution $institution, string $psuType, CarbonImmutable $validUntil, string $state, string $redirectUrl): AuthorisationStart
    {
        $body = $this->json($this->send(fn (): Response => $this->request()->post('/auth', [
            'access' => ['valid_until' => $validUntil->utc()->format('Y-m-d\TH:i:s.uP')],
            'aspsp' => ['name' => $institution->name, 'country' => $institution->country],
            'state' => $state,
            'redirect_url' => $redirectUrl,
            'psu_type' => $psuType,
            'language' => 'sv',
        ])));

        if (! is_string($body['url'] ?? null)) {
            throw new ProviderFailed('Enable Banking did not return an authorisation URL.');
        }

        return new AuthorisationStart($body['url'], is_string($body['authorization_id'] ?? null) ? $body['authorization_id'] : null);
    }

    public function completeAuthorisation(string $code): ConsentGrant
    {
        $body = $this->json($this->send(fn (): Response => $this->request()->post('/sessions', ['code' => $code])));

        if (! is_string($body['session_id'] ?? null)) {
            throw new ProviderFailed('Enable Banking did not return a session.');
        }

        $accounts = [];

        foreach ((array) ($body['accounts'] ?? []) as $account) {
            if (! is_array($account) || ! is_string($account['uid'] ?? null)) {
                continue;
            }

            $iban = is_array($account['account_id'] ?? null) && is_string($account['account_id']['iban'] ?? null) ? $account['account_id']['iban'] : null;

            $accounts[] = new ProviderAccount(
                id: $account['uid'],
                identificationHash: is_string($account['identification_hash'] ?? null) ? $account['identification_hash'] : hash('sha256', $iban ?? $account['uid']),
                iban: $iban,
                name: is_string($account['name'] ?? null) ? mb_substr($account['name'], 0, 255) : null,
                currency: is_string($account['currency'] ?? null) ? strtoupper($account['currency']) : 'SEK',
            );
        }

        $validUntil = is_array($body['access'] ?? null) && is_string($body['access']['valid_until'] ?? null)
            ? $this->date($body['access']['valid_until'])
            : null;

        return new ConsentGrant($body['session_id'], $validUntil, $accounts);
    }

    public function consentStatus(string $sessionId): ConsentStatus
    {
        $response = $this->send(fn (): Response => $this->request()->get('/sessions/'.rawurlencode($sessionId)));

        if ($this->ended($response)) {
            return ConsentStatus::Expired;
        }

        $status = $this->json($response)['status'] ?? null;

        return match ($status) {
            'AUTHORIZED' => ConsentStatus::Active,
            'PENDING_AUTHORIZATION', 'RETURNED_FROM_BANK' => ConsentStatus::Pending,
            'EXPIRED' => ConsentStatus::Expired,
            'REVOKED', 'CLOSED', 'CANCELLED', 'INVALID' => ConsentStatus::Revoked,
            default => ConsentStatus::Unknown,
        };
    }

    public function balances(string $sessionId, string $accountId): array
    {
        $response = $this->send(fn (): Response => $this->request()->get('/accounts/'.rawurlencode($accountId).'/balances'));
        $this->guardConsent($response);
        $balances = [];

        foreach ((array) ($this->json($response)['balances'] ?? []) as $balance) {
            if (! is_array($balance) || ! is_array($balance['balance_amount'] ?? null) || ! isset($balance['balance_amount']['amount'])) {
                continue;
            }

            $balances[] = new ProviderBalance(
                type: is_string($balance['balance_type'] ?? null) ? mb_substr($balance['balance_type'], 0, 10) : 'OTHR',
                amountOre: Amount::toOre($balance['balance_amount']['amount']),
                currency: is_string($balance['balance_amount']['currency'] ?? null) ? strtoupper($balance['balance_amount']['currency']) : 'SEK',
                date: is_string($balance['reference_date'] ?? null) ? $this->date($balance['reference_date'])->startOfDay() : CarbonImmutable::today(),
            );
        }

        return $balances;
    }

    public function transactions(string $sessionId, string $accountId, CarbonImmutable $from, ?string $continuation = null): TransactionPage
    {
        $query = array_filter([
            'date_from' => $from->toDateString(),
            'transaction_status' => 'BOOK',
            'continuation_key' => $continuation,
        ], fn (?string $value): bool => $value !== null);

        $response = $this->send(fn (): Response => $this->request()->get('/accounts/'.rawurlencode($accountId).'/transactions', $query));
        $this->guardConsent($response);
        $body = $this->json($response);
        $transactions = [];

        foreach ((array) ($body['transactions'] ?? []) as $row) {
            if (is_array($row) && ($transaction = $this->transaction($row)) !== null) {
                $transactions[] = $transaction;
            }
        }

        $next = is_string($body['continuation_key'] ?? null) && $body['continuation_key'] !== '' ? $body['continuation_key'] : null;

        return new TransactionPage($transactions, $next);
    }

    public function revoke(string $sessionId): void
    {
        $response = $this->send(fn (): Response => $this->request()->delete('/sessions/'.rawurlencode($sessionId)));

        // Already ended at the provider is what we want.
        if ($response->successful() || $this->ended($response)) {
            return;
        }

        throw new ProviderFailed("Enable Banking answered {$response->status()} when the session was closed.");
    }

    /** @param array<mixed> $row */
    private function transaction(array $row): ?ProviderTransaction
    {
        if (($row['status'] ?? 'BOOK') !== 'BOOK' || ! is_array($row['transaction_amount'] ?? null) || ! isset($row['transaction_amount']['amount'])) {
            return null;
        }

        $date = $row['booking_date'] ?? $row['value_date'] ?? $row['transaction_date'] ?? null;

        if (! is_string($date)) {
            return null;
        }

        $credit = ($row['credit_debit_indicator'] ?? 'CRDT') === 'CRDT';
        $amount = abs(Amount::toOre($row['transaction_amount']['amount']));
        $party = $credit ? ($row['debtor'] ?? null) : ($row['creditor'] ?? null);
        $remittance = array_values(array_filter((array) ($row['remittance_information'] ?? []), 'is_string'));

        return new ProviderTransaction(
            transactionId: $this->text($row['transaction_id'] ?? null, 255),
            entryReference: $this->text($row['entry_reference'] ?? null, 255),
            bookingDate: $this->date($date)->startOfDay(),
            valueDate: is_string($row['value_date'] ?? null) ? $this->date($row['value_date'])->startOfDay() : null,
            amountOre: $credit ? $amount : -$amount,
            currency: is_string($row['transaction_amount']['currency'] ?? null) ? strtoupper($row['transaction_amount']['currency']) : 'SEK',
            counterparty: is_array($party) ? $this->text($party['name'] ?? null, 255) : null,
            message: $this->text(trim(implode(' ', $remittance)), 500),
            reference: $this->text($row['reference_number'] ?? null, 64),
        );
    }

    private function text(mixed $value, int $max): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, $max) : null;
    }

    private function date(string $value): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value)->setTimezone(config('app.timezone'));
        } catch (Throwable) {
            throw new ProviderFailed('Enable Banking sent a date that cannot be read.');
        }
    }

    private function guardConsent(Response $response): void
    {
        if ($this->ended($response)) {
            throw new ConsentEnded('The bank no longer accepts the consent.');
        }
    }

    private function ended(Response $response): bool
    {
        if ($response->successful()) {
            return false;
        }

        $body = (string) $response->body();

        foreach (self::ENDED as $code) {
            if (str_contains($body, $code)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<mixed> */
    private function json(Response $response): array
    {
        if (! $response->successful()) {
            $code = $response->json('error');

            throw new ProviderFailed('Enable Banking answered '.$response->status().(is_string($code) ? " ({$code})" : '').'.');
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new ProviderFailed('Enable Banking did not answer with JSON.');
        }

        return $body;
    }

    /** @param callable(): Response $call */
    private function send(callable $call): Response
    {
        try {
            return $call();
        } catch (ConnectionException) {
            throw new ProviderFailed('Enable Banking could not be reached.');
        }
    }

    private function request(): PendingRequest
    {
        $key = @file_get_contents($this->privateKeyPath);

        if ($key === false || $this->applicationId === '') {
            throw new ProviderFailed('Enable Banking is not configured on this installation.');
        }

        return Http::baseUrl($this->baseUrl)
            ->withToken(EnableBankingJwt::make($this->applicationId, $key, time()))
            ->acceptJson()
            ->asJson()
            ->timeout(30)
            ->connectTimeout(10);
    }
}
