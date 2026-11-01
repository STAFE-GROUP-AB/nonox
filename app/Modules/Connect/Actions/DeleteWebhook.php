<?php

declare(strict_types=1);

namespace App\Modules\Connect\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Connect\Models\ConnectWebhook;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Removes a webhook and its delivery log (ADR-032). Nothing more is sent;
 * the audit log keeps that it existed, where it sent to and who removed it.
 */
final class DeleteWebhook
{
    /** @throws AuthorizationException */
    public function __invoke(User $user, Company $company, ConnectWebhook $webhook): void
    {
        if (! $user->hasTeamPermission($company, 'connect:manage') || $webhook->company_id !== $company->id) {
            throw new AuthorizationException;
        }

        CurrentCompany::run($company, fn () => DB::transaction(function () use ($user, $company, $webhook): void {
            Audit::record('connect.webhook.deleted', $webhook, ['url' => $webhook->url, 'events' => $webhook->events], $company, $user);

            $webhook->delete();
        }));
    }
}
