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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Ends a Stripe connection (ADR-080 §7.7): the key and the webhook secret
 * are erased at once, the connection is marked DISCONNECTED (a person) or
 * REVOKED (Stripe answered 401), and its subscriptions stop counting from
 * today as a source switched off, not as churn. Imported data stays, subject
 * to the retention in §8; the page tells the person to expire the key in
 * Stripe as well. No request to Stripe is made.
 *
 * Disconnecting is allowed also while Stripe is switched off for the
 * installation or the company: erasing a key is always safe.
 */
class EndStripeConnection
{
    public function __construct(private readonly StripeLedger $ledger) {}

    public function __invoke(User $user, Company $company, StripeConnection $connection): void
    {
        if (! $user->hasTeamPermission($company, SaasModule::MANAGE) || ! $user->hasTeamPermission($company, 'settings:manage')) {
            throw new AuthorizationException(__('Du behöver behörighet att ändra inställningar och att hantera återkommande intäkter för att koppla Stripe.'));
        }

        $this->end($company, $connection, StripeConnection::DISCONNECTED, 'disconnected', $user, null);
        Audit::record('stripe.disconnected', $connection, ['connection' => $connection->id], $company, $user);
    }

    /**
     * Stripe no longer accepts the key the run used: the members who manage
     * it are told in NONOX. Only when the connection still holds that key
     * (review P2-1): a key replaced while the run was going is left alone.
     *
     * @return bool whether the connection was revoked
     */
    public function revokedByStripe(StripeConnection $connection, int $keyVersion): bool
    {
        $company = Company::query()->findOrFail($connection->company_id);

        if (! $this->end($company, $connection, StripeConnection::REVOKED, 'unauthorized', null, $keyVersion)) {
            return false;
        }

        Audit::record('stripe.revoked_by_provider', $connection, ['connection' => $connection->id], $company);

        $members = $company->allUsers()->filter(fn (User $member): bool => $member->hasTeamPermission($company, SaasModule::MANAGE));
        Notification::send($members, new StripeRevokedNotice($company->id, (string) $company->name));

        return true;
    }

    /** @param  ?int  $keyVersion  end only while the row still holds this key; null for a person's disconnect */
    private function end(Company $company, StripeConnection $connection, string $status, string $reason, ?User $user, ?int $keyVersion): bool
    {
        return CurrentCompany::run($company, function () use ($connection, $status, $reason, $user, $company, $keyVersion): bool {
            $ended = DB::transaction(function () use ($connection, $status, $reason, $user, $keyVersion): bool {
                $fresh = StripeConnection::query()->lockForUpdate()->find($connection->id);

                if (! $fresh instanceof StripeConnection || $fresh->status !== StripeConnection::ACTIVE) {
                    return false;
                }

                if ($keyVersion !== null && $fresh->key_version !== $keyVersion) {
                    return false;
                }

                $fresh->forceFill([
                    'status' => $status,
                    'secret_key' => null,
                    'webhook_secret' => null,
                    'ended_at' => now(),
                    'ended_by' => $user?->id,
                    'end_reason' => $reason,
                    'cursor' => null,
                ])->save();
                $connection->setRawAttributes($fresh->getAttributes(), true);

                $this->ledger->switchOff($fresh);

                return true;
            });

            if ($ended) {
                RecomputeSaas::dispatch($company->id)->afterCommit();
            }

            return $ended;
        });
    }
}
