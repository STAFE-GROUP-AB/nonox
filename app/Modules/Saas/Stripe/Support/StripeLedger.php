<?php

declare(strict_types=1);

namespace App\Modules\Saas\Stripe\Support;

use App\Modules\Saas\Domain\ExclusionReason;
use App\Modules\Saas\Domain\SourceType;
use App\Modules\Saas\Domain\VersionCause;
use App\Modules\Saas\Models\SaasContractVersion;
use App\Modules\Saas\Stripe\Domain\SubscriptionContract;
use App\Modules\Saas\Stripe\Models\StripeConnection;
use App\Modules\Saas\Stripe\Models\StripeCustomerLink;
use App\Modules\Saas\Support\ContractVersions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Stripe subscriptions in the recurring-revenue ledger (ADR-080 §3.1, §7):
 * one STRIPE_SUBSCRIPTION version per change of a subscription as fetched,
 * the subscriptions moved when their Stripe customer is linked or unlinked
 * (§7.5), and switched off as a source when the connection ends. Every
 * method writes only and takes the ledger lock through ContractVersions:
 * call it inside a short transaction, after every request to Stripe is done
 * (step-1 follow-up).
 */
final class StripeLedger
{
    public function __construct(private readonly ContractVersions $versions) {}

    /**
     * @param  array<string, mixed>  $subscription
     * @param  array<string, array<string, mixed>>  $coupons
     */
    public function record(StripeConnection $connection, array $subscription, array $coupons, StripeCustomerLink $link, string $sha256, ?CarbonImmutable $today = null): ?SaasContractVersion
    {
        $state = SubscriptionContract::from($subscription, $coupons);

        return $this->versions->recordExternal(SourceType::StripeSubscription, (string) $subscription['id'], [
            'customer_id' => $link->ledgerCustomerId(),
            'status' => $state['status'],
            'starts_on' => $state['starts_on'],
            'cancel_effective_on' => $state['cancel_effective_on'],
            'excluded' => $state['excluded'],
            'lines' => $state['lines'],
            'cause' => $state['cause'],
            // Ids only (review 7): the connection, the subscription, its Stripe customer and the object it was read from.
            'source_ref' => [
                'connection' => $connection->id,
                'subscription' => (string) $subscription['id'],
                'customer' => $link->stripe_customer_id,
                'object_sha256' => $sha256,
            ],
        ], $today);
    }

    /**
     * Moves every subscription of the Stripe customer to the customer it is
     * counted under now: the linked NONOX customer, or its provisional id.
     */
    public function follow(StripeCustomerLink $link, ?int $userId): int
    {
        return $this->versions->moveToCustomer(SourceType::StripeSubscription, self::subscriptionsOf($link), $link->ledgerCustomerId(), $userId);
    }

    /**
     * The connection ended (disconnected, or the key revoked): every
     * subscription of it gets a version from today that is not counted, its
     * source switched off (StripeDisconnected), so its MRR leaves through the
     * bridge's source row, not as churn. Reconnecting the same account reads
     * each again as it stands.
     */
    public function switchOff(StripeConnection $connection, ?CarbonImmutable $today = null): int
    {
        $written = 0;

        foreach (self::latestOf($connection) as $version) {
            if ($version->excluded?->isSourceOff() === true) {
                continue;
            }

            $state = [
                'customer_id' => $version->customer_id,
                'status' => $version->status,
                'starts_on' => $version->starts_on?->toDateString(),
                'cancel_effective_on' => $version->cancel_effective_on?->toDateString(),
                'excluded' => ExclusionReason::StripeDisconnected,
                'lines' => $version->lines,
                'cause' => VersionCause::Changed,
                'source_ref' => [...(array) $version->source_ref, 'switched_off' => true],
            ];

            $written += $this->versions->recordExternal(SourceType::StripeSubscription, $version->source_id, $state, $today) === null ? 0 : 1;
        }

        return $written;
    }

    /**
     * The ids of the Stripe customer's subscriptions, read from the ledger's
     * own versions (their source_ref), so they are found also after the raw
     * objects were erased or pruned. One query.
     *
     * @return list<string>
     */
    public static function subscriptionsOf(StripeCustomerLink $link): array
    {
        return array_values(SaasContractVersion::query()
            ->where('source_type', SourceType::StripeSubscription->value)
            ->whereRaw("source_ref->>'connection' = ?", [(string) $link->connection_id])
            ->whereRaw("source_ref->>'customer' = ?", [$link->stripe_customer_id])
            ->distinct()
            ->orderBy('source_id')
            ->pluck('source_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all());
    }

    /** @return Collection<int, SaasContractVersion> the latest version of each subscription of the connection */
    private static function latestOf(StripeConnection $connection): Collection
    {
        return SaasContractVersion::query()
            ->whereIn('id', SaasContractVersion::query()
                ->selectRaw('max(id)')
                ->where('source_type', SourceType::StripeSubscription->value)
                ->whereRaw("source_ref->>'connection' = ?", [(string) $connection->id])
                ->groupBy('source_id'))
            ->orderBy('id')
            ->get();
    }
}
