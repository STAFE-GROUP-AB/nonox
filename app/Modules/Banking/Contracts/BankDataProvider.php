<?php

declare(strict_types=1);

namespace App\Modules\Banking\Contracts;

use App\Modules\Banking\Domain\AuthorisationStart;
use App\Modules\Banking\Domain\ConsentGrant;
use App\Modules\Banking\Domain\ConsentStatus;
use App\Modules\Banking\Domain\Institution;
use App\Modules\Banking\Domain\ProviderBalance;
use App\Modules\Banking\Domain\TransactionPage;
use App\Modules\Banking\Support\ConsentEnded;
use App\Modules\Banking\Support\ProviderFailed;
use Carbon\CarbonImmutable;

/**
 * An open-banking provider that reads a company's bank accounts with the
 * company's consent (PSD2 account information, ADR-034). Read only: nothing
 * here can move money. Every method throws ProviderFailed when the provider
 * cannot be reached or answers unexpectedly, and the data methods throw
 * ConsentEnded when the bank no longer accepts the consent.
 */
interface BankDataProvider
{
    /** The driver's key, stored on each connection ('enable_banking', 'fake'). */
    public function key(): string;

    /**
     * The banks a company can connect in the country.
     *
     * @return list<Institution>
     *
     * @throws ProviderFailed
     */
    public function institutions(string $country): array;

    /**
     * Starts the bank's own authorisation (BankID). The user is sent to the
     * returned URL and comes back to $redirectUrl with a code and $state.
     *
     * @throws ProviderFailed
     */
    public function startAuthorisation(Institution $institution, string $psuType, CarbonImmutable $validUntil, string $state, string $redirectUrl): AuthorisationStart;

    /**
     * Exchanges the code from the redirect for a consent and its accounts.
     *
     * @throws ProviderFailed
     */
    public function completeAuthorisation(string $code): ConsentGrant;

    /** @throws ProviderFailed */
    public function consentStatus(string $sessionId): ConsentStatus;

    /**
     * @return list<ProviderBalance>
     *
     * @throws ConsentEnded
     * @throws ProviderFailed
     */
    public function balances(string $sessionId, string $accountId): array;

    /**
     * Booked transactions from the date, one page at a time.
     *
     * @throws ConsentEnded
     * @throws ProviderFailed
     */
    public function transactions(string $sessionId, string $accountId, CarbonImmutable $from, ?string $continuation = null): TransactionPage;

    /**
     * Ends the consent at the provider and, where the bank allows, at the bank.
     *
     * @throws ProviderFailed
     */
    public function revoke(string $sessionId): void;
}
