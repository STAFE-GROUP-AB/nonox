<?php

declare(strict_types=1);

namespace App\Modules\Connect\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Connect\ConnectModule;
use App\Modules\Connect\Mail\WebhookCreatedMail;
use App\Modules\Connect\Models\ConnectWebhook;
use App\Modules\Connect\Support\PasskeyStepUp;
use App\Modules\Connect\Webhooks\UnsafeWebhookAddress;
use App\Modules\Connect\Webhooks\WebhookAddress;
use App\Modules\Connect\Webhooks\WebhookEvents;
use App\Modules\ModuleManager;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Adds a webhook (ADR-032): the company's chosen events sent to an HTTPS
 * address on the internet. Only an owner or administrator, only after a
 * fresh passkey confirmation, and only for events whose data they may see
 * themselves. The address is checked against server-side request forgery
 * (WebhookAddress). The signing secret is returned once; the owner gets an
 * e-mail.
 */
final class CreateWebhook
{
    public const int MAX_PER_COMPANY = 10;

    public const string SECRET_PREFIX = 'nnxwh_';

    public function __construct(private readonly WebhookAddress $address) {}

    /**
     * @param  array<string, mixed>  $input  url, description (optional), events (list)
     * @return array{webhook: ConnectWebhook, secret: string}
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

        $validated = Validator::make($input, [
            'url' => ['required', 'string', 'max:2000', 'url:https'],
            'description' => ['nullable', 'string', 'max:100'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', Rule::in(array_keys(WebhookEvents::EVENTS))],
        ], [
            'url.url' => __('Skriv en fullständig adress som börjar med https://.'),
            'events.required' => __('Välj minst en händelse.'),
        ], [
            'url' => __('adress'),
            'description' => __('beskrivning'),
        ])->validate();

        $url = trim((string) $validated['url']);

        try {
            $this->address->target($url);
        } catch (UnsafeWebhookAddress $exception) {
            throw ValidationException::withMessages(['url' => $exception->getMessage()]);
        }

        $events = WebhookEvents::filter((array) $validated['events']);
        $missing = array_values(array_filter($events, fn (string $event): bool => ! self::mayReceive($user, $company, $event)));

        if ($missing !== []) {
            throw ValidationException::withMessages(['events' => __('Du har inte själv behörighet att se: :events.', ['events' => implode(', ', $missing)])]);
        }

        $count = CurrentCompany::run($company, fn (): int => ConnectWebhook::query()->count());

        if ($count >= self::MAX_PER_COMPANY) {
            throw ValidationException::withMessages(['url' => __('Ett företag kan ha högst :max webhooks.', ['max' => self::MAX_PER_COMPANY])]);
        }

        PasskeyStepUp::consume($user);

        $secret = self::SECRET_PREFIX.Str::random(48);

        $webhook = CurrentCompany::run($company, fn (): ConnectWebhook => DB::transaction(function () use ($user, $company, $validated, $url, $events, $secret): ConnectWebhook {
            $webhook = ConnectWebhook::create([
                'url' => $url,
                'description' => filled($validated['description'] ?? null) ? trim((string) $validated['description']) : null,
                'events' => $events,
                'secret' => $secret,
                'secret_prefix' => substr($secret, 0, 12),
                'created_by' => $user->id,
            ]);

            Audit::record('connect.webhook.created', $webhook, [
                'url' => $webhook->url,
                'events' => $events,
                'secret_prefix' => $webhook->secret_prefix,
            ], $company, $user);

            return $webhook;
        }));

        $owner = User::query()->find($company->user_id);

        foreach (array_unique(array_filter([$owner?->email, $user->email])) as $email) {
            Mail::to($email)->send(new WebhookCreatedMail($company->name, $user->name, $webhook->url, $events));
        }

        return ['webhook' => $webhook, 'secret' => $secret];
    }

    public static function mayReceive(User $user, Company $company, string $event): bool
    {
        foreach (WebhookEvents::permissions($event) as $permission) {
            if ($user->hasTeamPermission($company, $permission)) {
                return true;
            }
        }

        return false;
    }
}
