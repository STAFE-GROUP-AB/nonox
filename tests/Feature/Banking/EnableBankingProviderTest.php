<?php

declare(strict_types=1);

use App\Modules\Banking\Domain\ConsentStatus;
use App\Modules\Banking\Domain\Institution;
use App\Modules\Banking\Providers\EnableBankingJwt;
use App\Modules\Banking\Providers\EnableBankingProvider;
use App\Modules\Banking\Support\Amount;
use App\Modules\Banking\Support\ConsentEnded;
use App\Modules\Banking\Support\ProviderFailed;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * The Enable Banking driver against the documented API
 * (https://enablebanking.com/docs/api/reference/), with HTTP faked: no
 * request leaves the test. The key is generated for the test.
 */

beforeEach(function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $this->publicKey = openssl_pkey_get_details($key)['key'];
    $this->keyPath = tempnam(sys_get_temp_dir(), 'eb-test-');
    file_put_contents($this->keyPath, $pem);
    $this->provider = new EnableBankingProvider('11111111-2222-3333-4444-555555555555', $this->keyPath, 'https://api.enablebanking.test');
});

afterEach(fn () => @unlink($this->keyPath));

function jwtParts(string $token): array
{
    $decode = fn (string $part): string => base64_decode(strtr($part, '-_', '+/'));

    return array_map($decode, explode('.', $token));
}

it('signs an RS256 token with the application id, issuer, audience and at most a day of life', function () {
    $token = EnableBankingJwt::make('app-id', (string) file_get_contents($this->keyPath), 1_800_000_000);
    [$header, $payload, $signature] = jwtParts($token);
    [$headerPart, $payloadPart] = explode('.', $token);

    expect(json_decode($header, true))->toBe(['typ' => 'JWT', 'alg' => 'RS256', 'kid' => 'app-id'])
        ->and(json_decode($payload, true))->toBe(['iss' => 'enablebanking.com', 'aud' => 'api.enablebanking.com', 'iat' => 1_800_000_000, 'exp' => 1_800_003_600])
        ->and(openssl_verify("{$headerPart}.{$payloadPart}", $signature, $this->publicKey, OPENSSL_ALGO_SHA256))->toBe(1);
});

it('lists Swedish banks with their longest consent in days', function () {
    Http::fake(['*/aspsps*' => Http::response(['aspsps' => [
        ['name' => 'SEB', 'country' => 'SE', 'psu_types' => ['business', 'personal'], 'maximum_consent_validity' => 15_552_000, 'bic' => 'ESSESESS', 'beta' => false],
        ['name' => 'Handelsbanken', 'country' => 'SE', 'psu_types' => ['business'], 'maximum_consent_validity' => 7_776_000],
    ]])]);

    $institutions = $this->provider->institutions('SE');

    expect(array_map(fn (Institution $i) => [$i->name, $i->maxConsentDays, $i->psuTypes], $institutions))->toBe([
        ['Handelsbanken', 90, ['business']],
        ['SEB', 180, ['business', 'personal']],
    ]);

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.enablebanking.test/aspsps?')
        && $request['country'] === 'SE' && $request['service'] === 'AIS'
        && str_starts_with($request->header('Authorization')[0], 'Bearer '));
});

it('starts the authorisation with access, bank, state, redirect and PSU type', function () {
    Http::fake(['*/auth' => Http::response(['url' => 'https://tilisy.example/auth/abc', 'authorization_id' => 'auth-1'])]);

    $start = $this->provider->startAuthorisation(new Institution('SEB', 'SE', ['business'], 180), 'business', CarbonImmutable::parse('2026-09-18 10:00:00', 'UTC'), 'state-123', 'https://nonox.test/bank/klar');

    expect($start->url)->toBe('https://tilisy.example/auth/abc');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request['aspsp'] === ['name' => 'SEB', 'country' => 'SE']
        && $request['state'] === 'state-123'
        && $request['redirect_url'] === 'https://nonox.test/bank/klar'
        && $request['psu_type'] === 'business'
        && str_starts_with($request['access']['valid_until'], '2026-09-18T10:00:00'));
});

it('creates a session from the code with its accounts', function () {
    Http::fake(['*/sessions' => Http::response([
        'session_id' => 'sess-1',
        'accounts' => [['uid' => 'acc-uid-1', 'account_id' => ['iban' => 'SE4550000000058398257466'], 'name' => 'Företagskonto', 'currency' => 'SEK', 'identification_hash' => 'hash-1']],
        'access' => ['valid_until' => '2026-09-18T10:00:00+00:00'],
    ])]);

    $grant = $this->provider->completeAuthorisation('code-1');

    expect($grant->sessionId)->toBe('sess-1')
        ->and($grant->validUntil?->toDateString())->toBe('2026-09-18')
        ->and($grant->accounts[0]->id)->toBe('acc-uid-1')
        ->and($grant->accounts[0]->identificationHash)->toBe('hash-1')
        ->and($grant->accounts[0]->iban)->toBe('SE4550000000058398257466');
});

it('reads booked transactions with signed amounts and follows the continuation key', function () {
    Http::fake([
        '*/accounts/acc-1/transactions?date_from=2026-03-01&transaction_status=BOOK' => Http::response(['transactions' => [
            ['entry_reference' => 'REF-1', 'transaction_amount' => ['amount' => '1549.00', 'currency' => 'SEK'], 'credit_debit_indicator' => 'CRDT', 'status' => 'BOOK', 'booking_date' => '2026-03-20', 'debtor' => ['name' => 'Kontorshuset AB'], 'remittance_information' => ['Faktura', '1029'], 'reference_number' => '10294'],
            ['transaction_id' => 'T-2', 'transaction_amount' => ['amount' => '249.5', 'currency' => 'SEK'], 'credit_debit_indicator' => 'DBIT', 'status' => 'BOOK', 'booking_date' => '2026-03-21', 'creditor' => ['name' => 'Kontorsvaror AB']],
            ['transaction_id' => 'T-3', 'transaction_amount' => ['amount' => '10.00', 'currency' => 'SEK'], 'credit_debit_indicator' => 'DBIT', 'status' => 'PDNG', 'booking_date' => '2026-03-22'],
        ], 'continuation_key' => 'next-1']),
        '*/accounts/acc-1/transactions?date_from=2026-03-01&transaction_status=BOOK&continuation_key=next-1' => Http::response(['transactions' => [], 'continuation_key' => null]),
    ]);

    $page = $this->provider->transactions('sess-1', 'acc-1', CarbonImmutable::parse('2026-03-01'));
    $last = $this->provider->transactions('sess-1', 'acc-1', CarbonImmutable::parse('2026-03-01'), $page->continuation);

    expect($page->continuation)->toBe('next-1')
        ->and($last->continuation)->toBeNull()
        ->and($page->transactions)->toHaveCount(2)
        ->and([$page->transactions[0]->amountOre, $page->transactions[0]->counterparty, $page->transactions[0]->message, $page->transactions[0]->reference, $page->transactions[0]->entryReference])
        ->toBe([154_900, 'Kontorshuset AB', 'Faktura 1029', '10294', 'REF-1'])
        ->and([$page->transactions[1]->amountOre, $page->transactions[1]->counterparty])->toBe([-24_950, 'Kontorsvaror AB']);
});

it('reads balances, and treats an ended session as an ended consent', function () {
    Http::fake([
        '*/accounts/acc-1/balances' => Http::response(['balances' => [['name' => 'Bokfört', 'balance_amount' => ['amount' => '-120.40', 'currency' => 'SEK'], 'balance_type' => 'CLBD', 'reference_date' => '2026-03-21']]]),
        '*/accounts/acc-2/balances' => Http::response(['code' => 401, 'message' => 'Session is expired', 'error' => 'EXPIRED_SESSION'], 401),
        '*/sessions/sess-1' => Http::response(['status' => 'REVOKED']),
    ]);

    $balances = $this->provider->balances('sess-1', 'acc-1');

    expect([$balances[0]->type, $balances[0]->amountOre, $balances[0]->date->toDateString()])->toBe(['CLBD', -12_040, '2026-03-21'])
        ->and(fn () => $this->provider->balances('sess-1', 'acc-2'))->toThrow(ConsentEnded::class)
        ->and($this->provider->consentStatus('sess-1'))->toBe(ConsentStatus::Revoked);
});

it('reports provider trouble without tokens or data in the message', function () {
    Http::fake(['*/accounts/acc-1/transactions*' => Http::response(['error' => 'ASPSP_RATE_LIMIT_EXCEEDED'], 429), '*/aspsps*' => fn () => throw new ConnectionException('timeout')]);

    expect(fn () => $this->provider->transactions('sess-1', 'acc-1', CarbonImmutable::parse('2026-03-01')))->toThrow(ProviderFailed::class, 'Enable Banking answered 429 (ASPSP_RATE_LIMIT_EXCEEDED).')
        ->and(fn () => $this->provider->institutions('SE'))->toThrow(ProviderFailed::class, 'Enable Banking could not be reached.');
});

it('closes the session when the connection is revoked', function () {
    Http::fake(['*/sessions/sess-1' => Http::response(['message' => 'OK'])]);

    $this->provider->revoke('sess-1');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/sessions/sess-1'));
});

it('reads decimal amounts into öre without floating point', function () {
    expect(Amount::toOre('1234.5'))->toBe(123_450)
        ->and(Amount::toOre('-0.07'))->toBe(-7)
        ->and(Amount::toOre('10'))->toBe(1_000)
        ->and(Amount::toOre('99.990'))->toBe(9_999)
        ->and(fn () => Amount::toOre('1,5'))->toThrow(ProviderFailed::class);
});
