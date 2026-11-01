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
use App\Modules\Banking\Support\ConsentEnded;
use Carbon\CarbonImmutable;

/**
 * A pretend bank for tests and local demos (ADR-034). It is never used in
 * production (BankDataProviders refuses it there). The "bank" is a page in
 * NONOX itself that approves the consent with one click; accounts and
 * transactions are made up and the same on every fetch, so fetching twice
 * shows that nothing is stored twice. Tests replace the transactions and
 * end consents through the static properties.
 */
final class FakeBankDataProvider implements BankDataProvider
{
    public const string KEY = 'fake';

    public const int PAGE_SIZE = 25;

    public const int OPENING_ORE = 5_000_000;

    /** @var array<string, list<ProviderTransaction>> transactions by account number (1, 2), replacing the made-up ones */
    public static array $transactions = [];

    /** @var array<string, true> session ids whose consent the bank no longer accepts */
    public static array $ended = [];

    /** @var list<string> session ids revoked through revoke() */
    public static array $revoked = [];

    public static function reset(): void
    {
        self::$transactions = [];
        self::$ended = [];
        self::$revoked = [];
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function institutions(string $country): array
    {
        return [
            new Institution('Demobanken', $country, ['business', 'personal'], 180, 'DEMOSESS'),
            new Institution('Testbanken Företag', $country, ['business'], 90, 'TESTSESS'),
        ];
    }

    public function startAuthorisation(Institution $institution, string $psuType, CarbonImmutable $validUntil, string $state, string $redirectUrl): AuthorisationStart
    {
        return new AuthorisationStart(route('banking.fake-bank', ['state' => $state, 'bank' => $institution->name, 'giltig' => $validUntil->toDateString()]));
    }

    public function completeAuthorisation(string $code): ConsentGrant
    {
        // fake-<validity date>-<random>
        $validUntil = preg_match('/^fake-(\d{4}-\d{2}-\d{2})-/', $code, $match) === 1 ? CarbonImmutable::parse($match[1])->endOfDay() : CarbonImmutable::now()->addDays(90);
        $session = 'fake-session-'.substr(hash('sha256', $code), 0, 16);

        return new ConsentGrant($session, $validUntil, [
            new ProviderAccount("{$session}-1", 'fake-ident-1', 'SE4550000000058398257466', 'Företagskonto', 'SEK'),
            new ProviderAccount("{$session}-2", 'fake-ident-2', 'SE7280000810340009783242', 'Sparkonto', 'SEK'),
        ]);
    }

    public function consentStatus(string $sessionId): ConsentStatus
    {
        if (in_array($sessionId, self::$revoked, true)) {
            return ConsentStatus::Revoked;
        }

        return isset(self::$ended[$sessionId]) ? ConsentStatus::Expired : ConsentStatus::Active;
    }

    public function balances(string $sessionId, string $accountId): array
    {
        $this->guard($sessionId);
        $total = self::OPENING_ORE + array_sum(array_map(fn (ProviderTransaction $transaction): int => $transaction->amountOre, $this->all($accountId)));

        return [
            new ProviderBalance('CLBD', $total, 'SEK', CarbonImmutable::today()),
            new ProviderBalance('ITAV', $total, 'SEK', CarbonImmutable::today()),
        ];
    }

    public function transactions(string $sessionId, string $accountId, CarbonImmutable $from, ?string $continuation = null): TransactionPage
    {
        $this->guard($sessionId);
        $offset = $continuation === null ? 0 : (int) $continuation;
        $all = array_values(array_filter($this->all($accountId), fn (ProviderTransaction $transaction): bool => $transaction->bookingDate->greaterThanOrEqualTo($from->startOfDay())));
        $page = array_slice($all, $offset, self::PAGE_SIZE);

        return new TransactionPage($page, $offset + self::PAGE_SIZE < count($all) ? (string) ($offset + self::PAGE_SIZE) : null);
    }

    public function revoke(string $sessionId): void
    {
        self::$revoked[] = $sessionId;
    }

    private function guard(string $sessionId): void
    {
        if ($this->consentStatus($sessionId)->hasEnded()) {
            throw new ConsentEnded('The fake bank no longer accepts the consent.');
        }
    }

    /** @return list<ProviderTransaction> */
    private function all(string $accountId): array
    {
        $number = str_ends_with($accountId, '-2') ? '2' : '1';

        if (array_key_exists($number, self::$transactions)) {
            return self::$transactions[$number];
        }

        if ($number === '2') {
            return [];
        }

        $transactions = [];
        $start = CarbonImmutable::today()->subDays(60);

        for ($day = $start; $day->lessThanOrEqualTo(CarbonImmutable::today()); $day = $day->addDay()) {
            $n = (int) $day->format('z');

            if ($n % 7 === 0) {
                $transactions[] = new ProviderTransaction("demo-{$day->toDateString()}-in", null, $day, $day, 1_250_000, 'SEK', 'Kund Demo AB', 'Betalning faktura', null);
            }

            if ($n % 3 === 0) {
                $transactions[] = new ProviderTransaction("demo-{$day->toDateString()}-card", null, $day, $day, -24_900, 'SEK', 'Kontorsvaror Demo', 'Kortköp', null);
            }
        }

        return $transactions;
    }
}
