<?php

declare(strict_types=1);

namespace App\Modules\Saas\Stripe\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Saas\Jobs\RecomputeSaas;
use App\Modules\Saas\SaasModule;
use App\Modules\Saas\Stripe\Models\StripeConnection;
use App\Modules\Saas\Stripe\Notifications\StripeRevokedNotice;
use App\Modules\Saas\Stripe\Support\StripeLedger;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Ends a Stripe connection (ADR-080 §7.7): the key and the webhook secret
 * are erased at once, the connection is marked DISCONNECTED (a person) or
 * REVOKED (Stripe answered 401), and its subscriptions stop counting from
 * today as a source switched off, not as churn. Imported data stays, subject
 * to the retention in §8; the page tells the person to expire the key in
 * Stripe as well. No request to Stripe is made.
 */
class EndStripeConnection
{
    public function __construct(private readonly StripeLedger $ledger) {}

    public function __invoke(User $user, Company $company, StripeConnection $connection): void
    {
        StripePermissions::authorizeConnect($user, $company);

        $this->end($company, $connection, StripeConnection::DISCONNECTED, 'disconnected', $user);
        Audit::record('stripe.disconnected', $connection, ['connection' => $connection->id], $company, $user);
    }

    /** Stripe no longer accepts the key: the members who manage it are told in NONOX. */
    public function revokedByStripe(StripeConnection $connection): void
    {
        $company = Company::query()->findOrFail($connection->company_id);

        if ($connection->status !== StripeConnection::ACTIVE) {
            return;
        }

        $this->end($company, $connection, StripeConnection::REVOKED, 'unauthorized', null);
        Audit::record('stripe.revoked_by_provider', $connection, ['connection' => $connection->id], $company);

        $members = $company->allUsers()->filter(fn (User $member): bool => $member->hasTeamPermission($company, SaasModule::MANAGE));
        Notification::send($members, new StripeRevokedNotice($company->id, (string) $company->name));
    }

    private function end(Company $company, StripeConnection $connection, string $status, string $reason, ?User $user): void
    {
        CurrentCompany::run($company, function () use ($connection, $status, $reason, $user, $company): void {
            DB::transaction(function () use ($connection, $status, $reason, $user): void {
                $connection->forceFill([
                    'status' => $status,
                    'secret_key' => null,
                    'webhook_secret' => null,
                    'ended_at' => now(),
                    'ended_by' => $user?->id,
                    'end_reason' => $reason,
                    'cursor' => null,
                ])->save();

                $this->ledger->switchOff($connection);
            });

            RecomputeSaas::dispatch($company->id)->afterCommit();
        });
    }
}
