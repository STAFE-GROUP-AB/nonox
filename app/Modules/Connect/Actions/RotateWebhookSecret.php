<?php

declare(strict_types=1);

namespace App\Modules\Connect\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Connect\Models\ConnectWebhook;
use App\Modules\Connect\Support\PasskeyStepUp;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

/**
 * Gives a webhook a new signing secret, for example when the old one may
 * have leaked (ADR-032). The old secret stops working at once. Owner or
 * administrator, after a fresh passkey confirmation; the new secret is
 * returned once.
 */
final class RotateWebhookSecret
{
    /** @throws AuthorizationException */
    public function __invoke(User $user, Company $company, ConnectWebhook $webhook): string
    {
        if (! $user->hasTeamPermission($company, 'connect:manage') || $webhook->company_id !== $company->id) {
            throw new AuthorizationException;
        }

        PasskeyStepUp::consume($user);

        $secret = CreateWebhook::SECRET_PREFIX.Str::random(48);

        CurrentCompany::run($company, function () use ($user, $company, $webhook, $secret): void {
            $webhook->forceFill(['secret' => $secret, 'secret_prefix' => substr($secret, 0, 12)])->save();

            Audit::record('connect.webhook.secret_rotated', $webhook, ['url' => $webhook->url, 'secret_prefix' => $webhook->secret_prefix], $company, $user);
        });

        return $secret;
    }
}
