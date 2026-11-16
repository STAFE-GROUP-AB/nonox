<?php

declare(strict_types=1);

namespace App\Modules\Saas\Stripe\Actions;

use App\Events\CustomerAnonymised;
use App\Modules\Saas\Stripe\Models\StripeConnection;
use App\Modules\Saas\Stripe\Models\StripeCustomerLink;
use App\Modules\Saas\Stripe\Models\StripeEvent;
use App\Modules\Saas\Stripe\Models\StripeObject;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;

/**
 * Retention of the Stripe data (ADR-080 §8, docs/gdpr/lagringstider.md):
 *
 * - 90 days after a connection ended, the raw objects and the events are
 *   deleted, and the Stripe customers' name, e-mail, address and tax ids are
 *   cleared. Nothing of it is underlag for bookkeeping in step 2. The ledger
 *   keeps only ids and amounts and is not touched.
 * - When a NONOX customer is anonymised (EraseRegisterEntry), or a Stripe
 *   customer is erased on request on the Stripe page, it is erased at once
 *   (erase()): cleared, marked so that no sync reads it back in, and its raw
 *   subscriptions deleted. A customer deleted in Stripe is cleared by the
 *   sync the same way.
 *
 * Step 3 (Stripe bookkeeping) must mark what becomes underlag and keep it out
 * of this prune (ADR-080 addendum, step-3 rule); TODO(legal-verify) then.
 */
class PruneStripe
{
    public const int DAYS_AFTER_END = 90;

    /** @return array{objects: int, events: int, customers: int} */
    public function __invoke(): array
    {
        // Across companies on purpose: the nightly prune has no company context.
        return CompanyScope::bypass(function (): array {
            $ended = StripeConnection::query()
                ->where('status', '!=', StripeConnection::ACTIVE)
                ->where('ended_at', '<', now()->subDays(self::DAYS_AFTER_END))
                ->pluck('id')
                ->all();

            if ($ended === []) {
                return ['objects' => 0, 'events' => 0, 'customers' => 0];
            }

            return [
                'objects' => StripeObject::query()->whereIn('connection_id', $ended)->delete(),
                'events' => StripeEvent::query()->whereIn('connection_id', $ended)->delete(),
                'customers' => self::clear(StripeCustomerLink::query()->whereIn('connection_id', $ended)->where(fn ($query) => $query->whereNotNull('name')->orWhereNotNull('email')->orWhereNotNull('address')->orWhereNotNull('tax_ids'))),
            ];
        });
    }

    /**
     * A NONOX customer anonymised on request: the Stripe customers linked to
     * it are erased at once, in the same transaction.
     */
    public function customerAnonymised(CustomerAnonymised $event): void
    {
        CurrentCompany::run($event->companyId, function () use ($event): void {
            foreach (StripeCustomerLink::query()->where('customer_id', $event->customerId)->get() as $link) {
                self::erase($link);
            }
        });
    }

    /**
     * Erases one Stripe customer (ADR-080 §8, review P2-2): its name, e-mail,
     * address, tax ids and metadata number are cleared and marked erased, so
     * no later sync reads them back in, and the raw subscriptions it was the
     * customer of are deleted. The ledger's versions keep ids and amounts only
     * and stay. Run inside the company.
     */
    public static function erase(StripeCustomerLink $link): void
    {
        // The connection's row lock, as a running sync takes before it writes (re-check P3d): a sync never writes this customer back meanwhile.
        StripeConnection::query()->lockForUpdate()->find($link->connection_id);

        $link->forceFill([...StripeCustomerLink::ERASED_FIELDS, 'erased_at' => $link->erased_at ?? now()])->save();

        StripeObject::query()
            ->where('connection_id', $link->connection_id)
            ->where('type', StripeObject::SUBSCRIPTION)
            ->whereRaw("payload->>'customer' = ?", [$link->stripe_customer_id])
            ->delete();
    }

    /** @param  Builder<StripeCustomerLink>  $query */
    private static function clear($query): int
    {
        return $query->update(StripeCustomerLink::ERASED_FIELDS);
    }
}
