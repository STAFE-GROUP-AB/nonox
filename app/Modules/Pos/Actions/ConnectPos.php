<?php

declare(strict_types=1);

namespace App\Modules\Pos\Actions;

use App\Actions\Accounting\CreateDefaultVoucherSeries;
use App\Models\Company;
use App\Models\User;
use App\Modules\Pos\Domain\ConnectionStatus;
use App\Modules\Pos\Domain\Provider;
use App\Modules\Pos\Drivers\Zettle\ZettleDriver;
use App\Modules\Pos\Models\PosConnection;
use App\Modules\Pos\PosModule;
use App\Modules\Pos\Support\Pkce;
use App\Modules\Pos\Support\PosApiFailed;
use App\Modules\Pos\Support\PosDrivers;
use App\Modules\Pos\Support\PosGate;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Connecting a till (ADR-042): start sends the user to the till's own
 * approval with a random state (and a PKCE verifier where the till requires
 * one); complete takes the code back only for the flow this browser
 * started, for the same company and user, once and within fifteen minutes.
 * The state, verifier, company and user travel in the session, never in
 * the URL.
 */
class ConnectPos
{
    public const int FLOW_MINUTES = 15;

    /**
     * @return array{url: string, flow: array{state: string, verifier: ?string, provider: string, company_id: int, user_id: int, expires_at: int}}
     */
    public function start(User $user, Company $company, Provider $provider, string $redirectUri): array
    {
        PosGate::manage($user, $company);

        if (! $provider->isConfigured()) {
            throw ValidationException::withMessages(['provider' => __('Inte konfigurerad på den här servern.')]);
        }

        $state = Str::random(48);
        $verifier = $provider === Provider::Onslip ? Pkce::verifier() : null;
        $url = PosDrivers::for($provider)->authorizeUrl($state, $redirectUri, $verifier === null ? null : Pkce::challenge($verifier));

        Audit::record('pos.connect_started', null, ['provider' => $provider->value], $company, $user);

        return [
            'url' => $url,
            'flow' => [
                'state' => $state,
                'verifier' => $verifier,
                'provider' => $provider->value,
                'company_id' => $company->id,
                'user_id' => $user->id,
                'expires_at' => CarbonImmutable::now()->addMinutes(self::FLOW_MINUTES)->getTimestamp(),
            ],
        ];
    }

    /**
     * Whether the answer belongs to the flow: same state (constant time),
     * provider, company and user, and not too old.
     *
     * @param  array<string, mixed>  $flow
     */
    public static function belongs(array $flow, string $state, Provider $provider, User $user, Company $company): bool
    {
        return is_string($flow['state'] ?? null)
            && $state !== ''
            && hash_equals($flow['state'], $state)
            && ($flow['provider'] ?? null) === $provider->value
            && ($flow['company_id'] ?? null) === $company->id
            && ($flow['user_id'] ?? null) === $user->id
            && is_int($flow['expires_at'] ?? null)
            && $flow['expires_at'] >= CarbonImmutable::now()->getTimestamp();
    }

    /**
     * @param  array<string, mixed>  $flow  a flow that belongs()
     *
     * @throws PosApiFailed
     */
    public function complete(User $user, Company $company, Provider $provider, array $flow, string $code, string $redirectUri): PosConnection
    {
        PosGate::manage($user, $company);

        $driver = PosDrivers::for($provider);
        $result = $driver->exchangeCode($code, $redirectUri, is_string($flow['verifier'] ?? null) ? $flow['verifier'] : null);

        // A merchant account feeds one company at a time.
        $elsewhere = CompanyScope::bypass(fn (): bool => PosConnection::query()
            ->where('provider', $provider->value)
            ->where('external_org_id', $result['external_org_id'])
            ->where('status', '!=', ConnectionStatus::Disconnected->value)
            ->where('company_id', '!=', $company->id)
            ->exists());

        if ($elsewhere) {
            throw ValidationException::withMessages(['provider' => __('Det här kassakontot är redan kopplat till ett annat företag i NONOX. Koppla bort det där först.')]);
        }

        $connection = CurrentCompany::run($company, fn (): PosConnection => DB::transaction(function () use ($user, $company, $provider, $result): PosConnection {
            $connection = PosConnection::query()
                ->where('provider', $provider->value)
                ->where('external_org_id', $result['external_org_id'])
                ->where('status', '!=', ConnectionStatus::Disconnected->value)
                ->lockForUpdate()
                ->first();

            $values = [
                'status' => ConnectionStatus::Active,
                'credentials' => $result['credentials'],
                'display_name' => $result['display_name'],
                'last_error' => null,
                'connected_by' => $user->id,
                'connected_at' => now(),
            ];

            if ($connection instanceof PosConnection) {
                $connection->forceFill($values)->save();
            } else {
                $connection = PosConnection::create([
                    ...$values,
                    'provider' => $provider,
                    'external_org_id' => $result['external_org_id'],
                    'webhook_token' => Str::random(48),
                    'settings' => ['sync_direction' => PosConnection::ONE_WAY, 'push_products' => true, 'push_stock' => $provider->supportsStock()],
                ]);
            }

            app(CreateDefaultVoucherSeries::class)($company, [PosModule::SERIES]);

            Audit::record('pos.connected', $connection, ['provider' => $provider->value], $company, $user);
            $connection->log('info', __(':till kopplades av :name.', ['till' => $provider->label(), 'name' => $user->name]));

            return $connection;
        }));

        if ($provider->supportsWebhook()) {
            $this->registerWebhook($connection);
        }

        return $connection;
    }

    /** Registers (again) the till's webhook; a failure is logged, and the nightly fetch still brings the sales in. */
    public function registerWebhook(PosConnection $connection): bool
    {
        return CurrentCompany::run($connection->company_id, fn (): bool => $this->register($connection));
    }

    private function register(PosConnection $connection): bool
    {
        $driver = PosDrivers::for($connection->provider);

        if (! $driver instanceof ZettleDriver) {
            return false;
        }

        try {
            $driver->registerWebhook($connection, route('pos.webhook.zettle', ['token' => $connection->webhook_token]));
        } catch (PosApiFailed $exception) {
            $connection->log('warning', __('Webhooken kunde inte registreras hos kassan. Försäljningen hämtas i stället varje timme.'), ['status' => $exception->status]);

            return false;
        }

        $connection->log('info', __('Webhooken för ny försäljning är registrerad.'));

        return true;
    }
}
