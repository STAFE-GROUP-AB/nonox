<?php

declare(strict_types=1);

namespace App\Actions\Signing;

use App\Domain\Signing\Providers\Csc\CscProvider;
use App\Domain\Signing\Providers\ProviderFailure;
use App\Domain\Signing\Providers\ProviderRegistry;
use App\Domain\Signing\Providers\SignatureLevel;
use App\Models\Company;
use App\Models\SigningProvider;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * A company's external signing providers (ADR-046 amendment, gap 12,
 * package S17), under Inställningar för företaget → Signering. Needs
 * signing:settings.
 *
 * - add: the configuration is checked against the service before it is
 *   saved (for CSC: its info method, over the address checks of
 *   ProviderHttp: public https only, pinned, no redirects), then stored with
 *   the OAuth client id and secret encrypted.
 * - retire: the configuration stops being offered and loses its secret;
 *   signers already sent to it see that it cannot be used and are told to
 *   contact the sender.
 *
 * A configuration is never edited (PostgreSQL trigger): a change is a new
 * one and retiring the old. Audit entries hold ids, the driver and the
 * stated level only; never the address, the client id or the secret.
 */
class ManageSigningProviders
{
    public const int MAX_ACTIVE = 5;

    /**
     * @param  array<string, mixed>  $input  driver, name, base_url, client_id, client_secret, claimed_level
     *
     * @throws ValidationException
     */
    public function add(User $actor, Company $company, #[SensitiveParameter] array $input): SigningProvider
    {
        $this->authorize($actor, $company);

        $validated = Validator::make($input, [
            'driver' => ['required', 'string', Rule::in(ProviderRegistry::keys())],
            'name' => ['required', 'string', 'max:120', 'regex:/^[^\p{C}]+$/u'],
            'base_url' => ['required', 'string', 'max:500'],
            'client_id' => ['required', 'string', 'max:500', 'regex:/^[^\p{C}]+$/u'],
            'client_secret' => ['required', 'string', 'max:2000'],
            'claimed_level' => ['required', 'string', Rule::in(array_map(fn (SignatureLevel $level): string => $level->value, SignatureLevel::cases()))],
        ], [
            'name.required' => __('Skriv ett namn på tjänsten, som undertecknarna ser.'),
            'base_url.required' => __('Skriv tjänstens adress.'),
            'client_id.required' => __('Skriv klient-id:t som tjänsten har gett företaget.'),
            'client_secret.required' => __('Skriv klienthemligheten som tjänsten har gett företaget.'),
            'claimed_level.required' => __('Välj vilken nivå tjänsten anger i ert avtal.'),
        ], [
            'name' => __('namn'),
            'base_url' => __('adress'),
            'client_id' => __('klient-id'),
            'client_secret' => __('klienthemlighet'),
            'claimed_level' => __('nivå'),
        ])->validate();

        if ($validated['driver'] === CscProvider::KEY && ! CscProvider::isBaseUrl((string) $validated['base_url'])) {
            throw ValidationException::withMessages(['base_url' => __('Adressen ska börja med https:// och sluta med /csc/v2, till exempel https://tjanst.example/csc/v2.')]);
        }

        $count = CurrentCompany::run($company, fn (): int => SigningProvider::query()->active()->count());

        if ($count >= self::MAX_ACTIVE) {
            throw ValidationException::withMessages(['name' => __('Högst :max tjänster för underskrift samtidigt. Sluta använda en först.', ['max' => self::MAX_ACTIVE])]);
        }

        $provider = new SigningProvider([
            'company_id' => $company->id,
            'driver' => (string) $validated['driver'],
            'name' => trim((string) $validated['name']),
            'base_url' => trim((string) $validated['base_url']),
            'client_id' => trim((string) $validated['client_id']),
            'client_secret' => (string) $validated['client_secret'],
            'claimed_level' => (string) $validated['claimed_level'],
            'created_by' => $actor->id,
        ]);

        // The service is asked before anything is saved; an address that is not public https is refused here.
        try {
            $description = ProviderRegistry::for($provider)->describe($provider);
        } catch (ProviderFailure $failure) {
            throw ValidationException::withMessages(['base_url' => $failure->message()]);
        }

        $provider->forceFill([
            'oauth_url' => $description->oauthUrl === null ? null : mb_substr($description->oauthUrl, 0, 500),
            'service_name' => mb_substr($description->name, 0, 255),
            'service_region' => $description->region,
        ]);

        return CurrentCompany::run($company, fn (): SigningProvider => DB::transaction(function () use ($actor, $company, $provider): SigningProvider {
            $provider->save();

            Audit::record('signing.provider_added', $provider, [
                'provider_id' => $provider->id,
                'driver' => $provider->driver,
                'claimed_level' => $provider->claimed_level->value,
            ], $company, $actor);

            return $provider;
        }));
    }

    public function retire(User $actor, Company $company, int $providerId): void
    {
        $this->authorize($actor, $company);

        CurrentCompany::run($company, fn () => DB::transaction(function () use ($actor, $company, $providerId): void {
            // Another company's id is not found here (the company scope): 404.
            $provider = SigningProvider::query()->active()->whereKey($providerId)->lockForUpdate()->firstOrFail();

            $provider->forceFill(['retired_at' => now(), 'retired_by' => $actor->id, 'client_secret' => null])->save();

            Audit::record('signing.provider_retired', $provider, ['provider_id' => $provider->id, 'driver' => $provider->driver], $company, $actor);
        }));
    }

    private function authorize(User $actor, Company $company): void
    {
        if (! $actor->hasTeamPermission($company, 'signing:settings')) {
            throw new AuthorizationException;
        }
    }
}
