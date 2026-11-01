<?php

declare(strict_types=1);

namespace App\Modules\Connect\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Connect\ConnectModule;
use App\Modules\Connect\Mail\ApiKeyCreatedMail;
use App\Modules\Connect\Models\ConnectApiKey;
use App\Modules\Connect\Support\ConnectScopes;
use App\Modules\Connect\Support\ConnectSettings;
use App\Modules\Connect\Support\PasskeyStepUp;
use App\Modules\ModuleManager;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creates an API key for a script (ADR-032): one company, chosen scopes, an
 * expiry date that cannot be left out, optionally a list of addresses it
 * may be used from. Only an owner or administrator, only after a fresh
 * passkey confirmation. The key is returned once and only its SHA-256 hash
 * is kept; the company's owner gets an e-mail about it.
 */
final class CreateApiKey
{
    /**
     * @param  array<string, mixed>  $input  name, scopes (list), expires_on (Y-m-d), ip_allowlist (text, optional)
     * @return array{key: ConnectApiKey, plaintext: string}
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $user, Company $company, array $input): array
    {
        if (! $user->hasTeamPermission($company, 'connect:manage')) {
            throw new AuthorizationException;
        }

        app(ModuleManager::class)->ensureEnabled(ConnectModule::KEY, $company);
        $maxDays = (int) config('services.connect.max_key_days', 365);

        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:100'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in(ConnectScopes::SCOPES)],
            'expires_on' => ['required', 'date_format:Y-m-d', 'after:today', 'before_or_equal:'.CarbonImmutable::today()->addDays($maxDays)->toDateString()],
            'ip_allowlist' => ['nullable', 'string', 'max:2000'],
        ], [
            'scopes.required' => __('Välj minst en behörighet.'),
            'expires_on.after' => __('Nyckeln måste gälla till ett datum efter i dag.'),
            'expires_on.before_or_equal' => __('En nyckel kan gälla i högst :days dagar.', ['days' => $maxDays]),
        ], [
            'name' => __('namn'),
            'expires_on' => __('giltig till'),
        ])->validate();

        $allowlist = ConnectSettings::parseAllowlist((string) ($validated['ip_allowlist'] ?? ''));

        if ($allowlist === null) {
            throw ValidationException::withMessages(['ip_allowlist' => __('Skriv en IP-adress eller ett nät (till exempel 203.0.113.0/24) per rad.')]);
        }

        /** @var list<string> $requested */
        $requested = (array) $validated['scopes'];
        $missing = array_values(array_filter(ConnectScopes::filter($requested), fn (string $scope): bool => ! $user->hasTeamPermission($company, $scope)));

        if ($missing !== []) {
            throw ValidationException::withMessages(['scopes' => __('Du har inte själv behörigheten: :scopes.', ['scopes' => implode(', ', $missing)])]);
        }

        PasskeyStepUp::consume($user);

        $plaintext = ConnectApiKey::PREFIX.Str::random(48);
        $expiresAt = CarbonImmutable::createFromFormat('Y-m-d', (string) $validated['expires_on'], 'Europe/Stockholm')?->endOfDay()
            ?? throw ValidationException::withMessages(['expires_on' => __('Datumet ska skrivas som ÅÅÅÅ-MM-DD.')]);

        $key = CurrentCompany::run($company, fn (): ConnectApiKey => DB::transaction(function () use ($user, $company, $validated, $allowlist, $plaintext, $expiresAt): ConnectApiKey {
            $key = ConnectApiKey::create([
                'name' => trim((string) $validated['name']),
                'prefix' => substr($plaintext, 0, 12),
                'token_hash' => ConnectApiKey::hash($plaintext),
                'scopes' => ConnectScopes::filter((array) $validated['scopes']),
                'ip_allowlist' => $allowlist === [] ? null : $allowlist,
                'expires_at' => $expiresAt,
                'created_by' => $user->id,
            ]);

            Audit::record('connect.api_key.created', $key, [
                'name' => $key->name,
                'prefix' => $key->prefix,
                'scopes' => $key->scopes,
                'expires_at' => $expiresAt->toIso8601String(),
                'ip_allowlist' => $key->ip_allowlist,
            ], $company, $user);

            return $key;
        }));

        $this->notify($company, $user, $key);

        return ['key' => $key, 'plaintext' => $plaintext];
    }

    /** The owner always hears about a new key, and so does the one who created it. */
    private function notify(Company $company, User $creator, ConnectApiKey $key): void
    {
        $owner = User::query()->find($company->user_id);
        $recipients = array_unique(array_filter([$owner?->email, $creator->email]));

        foreach ($recipients as $email) {
            Mail::to($email)->send(new ApiKeyCreatedMail($company->name, $creator->name, $key->name, $key->prefix, $key->scopes, $key->expires_at));
        }
    }
}
