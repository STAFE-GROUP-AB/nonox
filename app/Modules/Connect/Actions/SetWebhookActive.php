<?php

declare(strict_types=1);

namespace App\Modules\Connect\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Connect\ConnectModule;
use App\Modules\Connect\Models\ConnectWebhook;
use App\Modules\Connect\Models\ConnectWebhookDelivery;
use App\Modules\Connect\Webhooks\UnsafeWebhookAddress;
use App\Modules\Connect\Webhooks\WebhookAddress;
use App\Modules\ModuleManager;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns a webhook off or on again (ADR-032). Turning it off cancels the
 * events still waiting. Turning it on checks the address again and sends
 * only new events: what happened while it was off is not sent afterwards
 * (a receiver catches up through the API with updated_since).
 */
final class SetWebhookActive
{
    public function __construct(private readonly WebhookAddress $address) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $user, Company $company, ConnectWebhook $webhook, bool $active): void
    {
        if (! $user->hasTeamPermission($company, 'connect:manage') || $webhook->company_id !== $company->id) {
            throw new AuthorizationException;
        }

        if ($active === $webhook->isActive()) {
            return;
        }

        if ($active) {
            app(ModuleManager::class)->ensureEnabled(ConnectModule::KEY, $company);

            try {
                $this->address->target($webhook->url);
            } catch (UnsafeWebhookAddress $exception) {
                throw ValidationException::withMessages(['webhook' => $exception->getMessage()]);
            }
        }

        CurrentCompany::run($company, fn () => DB::transaction(function () use ($user, $company, $webhook, $active): void {
            $webhook->forceFill($active
                ? ['disabled_at' => null, 'disabled_reason' => null]
                : ['disabled_at' => now(), 'disabled_reason' => ConnectWebhook::DISABLED_BY_USER])->save();

            if (! $active) {
                ConnectWebhookDelivery::query()->where('webhook_id', $webhook->id)->where('status', ConnectWebhookDelivery::PENDING)
                    ->update(['status' => ConnectWebhookDelivery::CANCELLED, 'next_attempt_at' => null, 'updated_at' => now()]);
            }

            Audit::record($active ? 'connect.webhook.enabled' : 'connect.webhook.disabled', $webhook, [
                'url' => $webhook->url,
                'reason' => $active ? null : ConnectWebhook::DISABLED_BY_USER,
            ], $company, $user);
        }));
    }
}
