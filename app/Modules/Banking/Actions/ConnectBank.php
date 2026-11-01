<?php

declare(strict_types=1);

namespace App\Modules\Banking\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Banking\BankingModule;
use App\Modules\Banking\Domain\ConnectionStatus;
use App\Modules\Banking\Domain\Institution;
use App\Modules\Banking\Models\BankConnection;
use App\Modules\Banking\Models\BankFeedAccount;
use App\Modules\Banking\Support\BankDataProviders;
use App\Modules\Banking\Support\BankingGate;
use App\Modules\ModuleManager;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Connecting a bank (ADR-034): start sends the user to the bank's own
 * approval (BankID) with a random state; complete takes the code back,
 * only for the flow this browser started, for the same company and user,
 * once and within fifteen minutes. The state, company and user travel in
 * the session, never in the URL.
 */
class ConnectBank
{
    public const int FLOW_MINUTES = 15;

    /** NONOX asks for at most this many days, also where a bank allows more. */
    public const int MAX_CONSENT_DAYS = 180;

    public const array PSU_TYPES = ['business', 'personal'];

    /**
     * @return array{url: string, flow: array{state: string, company_id: int, user_id: int, provider: string, institution: string, country: string, psu_type: string, valid_until: string, expires_at: int}}
     */
    public function start(User $user, Company $company, string $institutionName, string $psuType, string $redirectUrl): array
    {
        BankingGate::manage($user, $company);
        app(ModuleManager::class)->ensureEnabled(BankingModule::KEY, $company);

        if (! in_array($psuType, self::PSU_TYPES, true)) {
            throw ValidationException::withMessages(['psuType' => __('Välj om kontot är ett företagskonto eller ett privatkonto.')]);
        }

        $provider = BankDataProviders::make();
        $institution = collect($provider->institutions('SE'))->first(fn (Institution $institution): bool => $institution->name === $institutionName);

        if (! $institution instanceof Institution) {
            throw ValidationException::withMessages(['institution' => __('Välj en bank i listan.')]);
        }

        if (! $institution->supports($psuType)) {
            throw ValidationException::withMessages(['psuType' => $psuType === 'business'
                ? __(':bank går inte att koppla som företagskonto här. Välj privatkonto om företaget är en enskild firma med privatkonto.', ['bank' => $institution->name])
                : __(':bank går bara att koppla som företagskonto.', ['bank' => $institution->name])]);
        }

        $validUntil = CarbonImmutable::now()->addDays(min($institution->maxConsentDays, self::MAX_CONSENT_DAYS));
        $state = Str::random(48);
        $start = $provider->startAuthorisation($institution, $psuType, $validUntil, $state, $redirectUrl);

        Audit::record('banking.connect_started', null, ['bank' => $institution->name, 'psu_type' => $psuType, 'provider' => $provider->key()], $company, $user);

        return [
            'url' => $start->url,
            'flow' => [
                'state' => $state,
                'company_id' => $company->id,
                'user_id' => $user->id,
                'provider' => $provider->key(),
                'institution' => $institution->name,
                'country' => $institution->country,
                'psu_type' => $psuType,
                'valid_until' => $validUntil->toIso8601String(),
                'expires_at' => CarbonImmutable::now()->addMinutes(self::FLOW_MINUTES)->getTimestamp(),
            ],
        ];
    }

    /**
     * Whether the answer from the bank belongs to the flow: same state
     * (constant time), company and user, and not too old.
     *
     * @param  array<string, mixed>  $flow
     */
    public static function belongs(array $flow, string $state, User $user, Company $company): bool
    {
        return is_string($flow['state'] ?? null)
            && $state !== ''
            && hash_equals($flow['state'], $state)
            && ($flow['company_id'] ?? null) === $company->id
            && ($flow['user_id'] ?? null) === $user->id
            && is_int($flow['expires_at'] ?? null)
            && $flow['expires_at'] >= CarbonImmutable::now()->getTimestamp();
    }

    /**
     * @param  array{state: string, company_id: int, user_id: int, provider: string, institution: string, country: string, psu_type: string, valid_until: string, expires_at: int}  $flow
     */
    public function complete(User $user, Company $company, array $flow, string $code): BankConnection
    {
        BankingGate::manage($user, $company);
        app(ModuleManager::class)->ensureEnabled(BankingModule::KEY, $company);

        $provider = BankDataProviders::make($flow['provider']);
        $grant = $provider->completeAuthorisation($code);

        $connection = CurrentCompany::run($company, fn (): BankConnection => DB::transaction(function () use ($user, $company, $flow, $grant, $provider): BankConnection {
            $connection = BankConnection::create([
                'provider' => $provider->key(),
                'institution_name' => mb_substr($flow['institution'], 0, 255),
                'institution_country' => $flow['country'],
                'psu_type' => $flow['psu_type'],
                'session_id' => $grant->sessionId,
                'status' => ConnectionStatus::Active,
                'valid_until' => $grant->validUntil ?? CarbonImmutable::parse($flow['valid_until']),
                'connected_by' => $user->id,
                'connected_at' => now(),
            ]);

            $hashes = array_map(fn ($account): string => $account->identificationHash, $grant->accounts);
            // The same account from an earlier consent keeps its ledger account and where fetching stopped.
            $earlier = BankFeedAccount::query()->whereIn('identification_hash', $hashes)->where('is_active', true)->get()->keyBy('identification_hash');

            foreach ($grant->accounts as $account) {
                $previous = $earlier->get($account->identificationHash);
                $previous?->forceFill(['is_active' => false])->save();

                BankFeedAccount::create([
                    'bank_connection_id' => $connection->id,
                    'provider_account_id' => $account->id,
                    'identification_hash' => mb_substr($account->identificationHash, 0, 255),
                    'iban' => $account->iban,
                    'iban_last4' => $account->iban === null ? null : substr(preg_replace('/\s+/', '', $account->iban) ?? '', -4),
                    'name' => $account->name,
                    'currency' => $account->currency,
                    'account_id' => $previous?->account_id,
                    'is_active' => true,
                    'fetched_through' => $previous?->fetched_through,
                ]);
            }

            Audit::record('banking.connected', $connection, [
                'bank' => $connection->institution_name,
                'psu_type' => $connection->psu_type,
                'provider' => $connection->provider,
                'accounts' => count($grant->accounts),
                'valid_until' => $connection->valid_until?->toIso8601String(),
            ], $company, $user);

            return $connection;
        }));

        $this->retireReplaced($user, $company, $connection);

        return $connection;
    }

    /**
     * A renewed consent replaces the old one: an older active connection
     * whose accounts all moved to the new one is ended, so the bank stops
     * sharing through it and nobody is reminded about it.
     */
    private function retireReplaced(User $user, Company $company, BankConnection $connection): void
    {
        $replaced = CurrentCompany::run($company, fn () => BankConnection::query()
            ->where('status', ConnectionStatus::Active->value)
            ->whereKeyNot($connection->id)
            ->whereHas('accounts', fn ($query) => $query->where('is_active', false))
            ->whereDoesntHave('accounts', fn ($query) => $query->where('is_active', true))
            ->get());

        foreach ($replaced as $old) {
            app(RevokeBankConnection::class)($user, $company, $old);
        }
    }
}
