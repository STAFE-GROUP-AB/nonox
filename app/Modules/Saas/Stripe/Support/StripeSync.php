<?php

declare(strict_types=1);

namespace App\Modules\Saas\Stripe\Support;

use App\Modules\Saas\Jobs\RecomputeSaas;
use App\Modules\Saas\Stripe\Actions\EndStripeConnection;
use App\Modules\Saas\Stripe\Models\StripeConnection;
use App\Modules\Saas\Stripe\Models\StripeCustomerLink;
use App\Modules\Saas\Stripe\Models\StripeEvent;
use App\Modules\Saas\Stripe\Models\StripeObject;
use App\Modules\Saas\Stripe\Models\StripeSyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Reads a company's Stripe account into NONOX (ADR-080 §7.2–§7.3, step 2):
 * customers, products, prices, coupons and subscriptions, and the events
 * since the last run.
 *
 * Fetch first, then write (step-1 follow-up): every request to Stripe is made
 * before the short transaction that stores what it returned and takes the
 * ledger lock, so a slow Stripe never holds up the company's agreements.
 *
 * - Initial import: every object of each type, a page of 100 at a time with
 *   `starting_after`; the cursor is stored with each page, so a run that
 *   stops goes on from the same page.
 * - Incremental: the Events list from the last event seen, with a three-day
 *   overlap, plus the events the webhook has queued; each event's object is
 *   fetched again with the company's key and stored by Stripe id and hash.
 *   Events are kept 30 days by Stripe, so a connection not read for 27 days
 *   is read in full again.
 * - Once only: an event id is handled once per connection; an object is
 *   stored again only when its hash changes; a subscription writes a version
 *   only when what counts changed. Fetching in another order gives the same
 *   versions.
 * - A 401 ends the connection (REVOKED); 429 and 5xx are retried by the
 *   client and, if they persist, the run stops and the next one continues.
 */
final class StripeSync
{
    /** Stripe keeps events for 30 days (docs.stripe.com/api/events/list: "going back up to 30 days"). */
    public const int EVENT_DAYS = 27;

    public const int OVERLAP_SECONDS = 3 * 86_400;

    /** The event types that can change a customer, a subscription or what a subscription is priced with (at most 20, `types`). */
    public const array EVENT_TYPES = [
        'customer.created',
        'customer.updated',
        'customer.deleted',
        'customer.tax_id.created',
        'customer.tax_id.updated',
        'customer.tax_id.deleted',
        'customer.subscription.created',
        'customer.subscription.updated',
        'customer.subscription.deleted',
        'customer.subscription.paused',
        'customer.subscription.resumed',
        'customer.subscription.pending_update_applied',
        'customer.subscription.pending_update_expired',
        'coupon.created',
        'coupon.updated',
        'product.created',
        'product.updated',
        'price.created',
        'price.updated',
    ];

    /** @var array<string, array{0: string, 1: array<string, mixed>}> resource => [path, query] */
    private const array RESOURCES = [
        'coupons' => ['/v1/coupons', []],
        'products' => ['/v1/products', []],
        'prices' => ['/v1/prices', []],
        'customers' => ['/v1/customers', ['expand' => ['data.tax_ids']]],
        'subscriptions' => ['/v1/subscriptions', ['status' => 'all', 'expand' => ['data.discounts', 'data.items.data.discounts']]],
    ];

    /** @var array<string, int> */
    private array $counts = [];

    private bool $versionsWritten = false;

    public function __construct(
        private readonly StripeLedger $ledger,
        private readonly CustomerMatcher $matcher,
    ) {}

    public function run(StripeConnection $connection, ?CarbonImmutable $now = null): ?StripeSyncRun
    {
        $now ??= CarbonImmutable::now();

        if (! $connection->isActive()) {
            return null;
        }

        $full = $connection->initial_import_done_at === null
            || $connection->last_event_created === null
            || $connection->last_event_created < $now->subDays(self::EVENT_DAYS)->getTimestamp();
        $kind = $connection->initial_import_done_at === null ? StripeSyncRun::INITIAL : ($full ? StripeSyncRun::FULL : StripeSyncRun::INCREMENTAL);

        $run = StripeSyncRun::create([
            'company_id' => $connection->company_id,
            'connection_id' => $connection->id,
            'kind' => $kind,
            'status' => StripeSyncRun::RUNNING,
            'started_at' => $now,
        ]);
        $client = new StripeClient((string) $connection->secret_key);
        $this->counts = ['customers' => 0, 'subscriptions' => 0, 'objects' => 0, 'versions' => 0, 'events' => 0, 'linked' => 0];
        $this->versionsWritten = false;

        try {
            if ($full) {
                $this->importAll($connection, $client, $now);
            }

            $this->incremental($connection, $client);
            $run->status = StripeSyncRun::OK;
            $connection->forceFill(['consecutive_failures' => 0, 'last_synced_at' => now()])->save();
        } catch (StripeFailure $failure) {
            $run->status = $failure->retryable() ? StripeSyncRun::INTERRUPTED : StripeSyncRun::FAILED;
            $run->error = $failure->reason;

            if ($failure->reason === 'unauthorized') {
                app(EndStripeConnection::class)->revokedByStripe($connection->fresh() ?? $connection);
            } else {
                $connection->forceFill(['consecutive_failures' => $connection->consecutive_failures + 1])->save();
            }
        } finally {
            $run->forceFill(['finished_at' => now(), 'requests' => $client->requests, 'counts' => $this->counts])->save();

            if ($this->versionsWritten) {
                RecomputeSaas::dispatch($connection->company_id);
            }
        }

        return $run;
    }

    /**
     * Stores objects already in hand, as a sync would after fetching them:
     * for the demo companies, which have made-up Stripe data and never call
     * Stripe. Returns the counts.
     *
     * @param  array<string, list<array<string, mixed>>>  $fetched  resource => objects
     * @return array<string, int>
     */
    public function storeFetched(StripeConnection $connection, array $fetched): array
    {
        $this->counts = ['customers' => 0, 'subscriptions' => 0, 'objects' => 0, 'versions' => 0, 'events' => 0, 'linked' => 0];
        DB::transaction(fn () => $this->write($connection, $fetched));

        return $this->counts;
    }

    /** The whole account, resumable page by page. */
    private function importAll(StripeConnection $connection, StripeClient $client, CarbonImmutable $now): void
    {
        $cursor = (array) ($connection->cursor ?? []);
        // Events from a little before the import started are read afterwards, so nothing changed meanwhile is missed.
        $watermark = (int) ($cursor['watermark'] ?? $now->subMinutes(5)->getTimestamp());
        $stages = array_keys(self::RESOURCES);
        $start = in_array($cursor['stage'] ?? null, $stages, true) ? array_search($cursor['stage'], $stages, true) : 0;

        foreach (array_slice($stages, (int) $start) as $stage) {
            [$path, $query] = self::RESOURCES[$stage];
            $after = ($cursor['stage'] ?? null) === $stage ? ($cursor['after'] ?? null) : null;

            do {
                $page = $client->page($path, $query, is_string($after) ? $after : null);
                $fetched = $this->fetchDependencies($connection, $client, $stage, $page['data']);
                $after = $page['last'];
                $next = $page['has_more']
                    ? ['stage' => $stage, 'after' => $after, 'watermark' => $watermark]
                    : ['stage' => $stages[array_search($stage, $stages, true) + 1] ?? 'done', 'after' => null, 'watermark' => $watermark];

                DB::transaction(function () use ($connection, $stage, $page, $fetched, $next): void {
                    $this->write($connection, [$stage => $page['data'], ...$fetched]);
                    $connection->forceFill(['cursor' => $next])->save();
                });
            } while ($page['has_more']);

            $cursor = ['stage' => $stages[array_search($stage, $stages, true) + 1] ?? 'done', 'after' => null, 'watermark' => $watermark];
        }

        $connection->forceFill([
            'cursor' => null,
            'initial_import_done_at' => $connection->initial_import_done_at ?? now(),
            'last_event_created' => max((int) $connection->last_event_created, $watermark),
        ])->save();
    }

    /**
     * The events since the last run (Events list, three days' overlap) and the
     * ones the webhook queued; each is handled once.
     */
    private function incremental(StripeConnection $connection, StripeClient $client): void
    {
        $since = max(0, (int) $connection->last_event_created - self::OVERLAP_SECONDS);
        $listed = [];
        $after = null;

        do {
            $page = $client->page('/v1/events', ['created' => ['gte' => $since], 'types' => self::EVENT_TYPES], $after);
            array_push($listed, ...$page['data']);
            $after = $page['last'];
        } while ($page['has_more']);

        if ($listed !== []) {
            StripeEvent::query()->insertOrIgnore(array_map(fn (array $event): array => [
                'company_id' => $connection->company_id,
                'connection_id' => $connection->id,
                'event_id' => (string) $event['id'],
                'type' => (string) ($event['type'] ?? ''),
                'object_id' => self::eventObject($event),
                'event_created' => (int) ($event['created'] ?? 0),
                'received_from' => StripeEvent::FROM_LIST,
                'received_at' => now(),
            ], array_filter($listed, fn (array $event): bool => is_string($event['id'] ?? null))));
        }

        $pending = StripeEvent::query()->where('connection_id', $connection->id)->whereNull('processed_at')->orderBy('event_created')->orderBy('id')->get();
        $newest = max((int) $connection->last_event_created, ...array_map(fn (array $event): int => (int) ($event['created'] ?? 0), $listed ?: [[]]));

        if ($pending->isEmpty()) {
            $connection->forceFill(['last_event_created' => $newest])->save();

            return;
        }

        $targets = ['coupons' => [], 'products' => [], 'prices' => [], 'customers' => [], 'subscriptions' => []];

        foreach ($pending as $event) {
            $resource = match (true) {
                str_starts_with($event->type, 'customer.subscription.') => 'subscriptions',
                str_starts_with($event->type, 'customer.') => 'customers',
                str_starts_with($event->type, 'coupon.') => 'coupons',
                str_starts_with($event->type, 'product.') => 'products',
                str_starts_with($event->type, 'price.') => 'prices',
                default => null,
            };

            if ($resource !== null && $event->object_id !== null && $event->object_id !== '') {
                $targets[$resource][$event->object_id] = true;
            }
        }

        // Fetched again with the company's key: the event is a hint, never the source (§7.4).
        $fetched = [];

        foreach ($targets as $resource => $ids) {
            foreach (array_keys($ids) as $id) {
                $object = $this->retrieve($client, $resource, (string) $id);

                if ($object !== null) {
                    $fetched[$resource][] = $object;
                }
            }
        }

        foreach (['customers', 'subscriptions'] as $stage) {
            $fetched = array_merge_recursive($fetched, $this->fetchDependencies($connection, $client, $stage, $fetched[$stage] ?? []));
        }

        DB::transaction(function () use ($connection, $fetched, $pending, $newest): void {
            $this->write($connection, $fetched);
            StripeEvent::query()->whereIn('id', $pending->pluck('id')->all())->update(['processed_at' => now()]);
            $connection->forceFill(['last_event_created' => $newest])->save();
        });

        $this->counts['events'] += $pending->count();
    }

    /**
     * What a page needs before it can be written: the Stripe customers of its
     * subscriptions that NONOX has not seen, and coupons it refers to.
     *
     * @param  list<array<string, mixed>>  $objects
     * @return array<string, list<array<string, mixed>>>
     */
    private function fetchDependencies(StripeConnection $connection, StripeClient $client, string $stage, array $objects): array
    {
        if ($stage !== 'subscriptions' || $objects === []) {
            return [];
        }

        $customers = array_values(array_unique(array_filter(array_map(fn (array $subscription): string => is_string($subscription['customer'] ?? null) ? $subscription['customer'] : '', $objects))));
        $known = StripeCustomerLink::query()->where('connection_id', $connection->id)->whereIn('stripe_customer_id', $customers)->pluck('stripe_customer_id')->all();
        $coupons = [];

        foreach ($objects as $subscription) {
            foreach ([$subscription['discounts'] ?? [], ...array_map(fn ($item): mixed => is_array($item) ? ($item['discounts'] ?? []) : [], (array) ($subscription['items']['data'] ?? []))] as $discounts) {
                foreach ((array) $discounts as $discount) {
                    $coupon = is_array($discount) ? ($discount['source']['coupon'] ?? null) : null;

                    if (is_string($coupon)) {
                        $coupons[$coupon] = true;
                    }
                }
            }
        }

        $knownCoupons = $coupons === [] ? [] : StripeObject::query()->where('connection_id', $connection->id)->where('type', StripeObject::COUPON)->whereIn('stripe_id', array_keys($coupons))->distinct()->pluck('stripe_id')->all();
        $fetched = [];

        foreach (array_diff($customers, $known) as $customer) {
            $object = $this->retrieve($client, 'customers', $customer);

            if ($object !== null) {
                $fetched['customers'][] = $object;
            }
        }

        foreach (array_diff(array_keys($coupons), $knownCoupons) as $coupon) {
            $object = $this->retrieve($client, 'coupons', (string) $coupon);

            if ($object !== null) {
                $fetched['coupons'][] = $object;
            }
        }

        return $fetched;
    }

    /** @return ?array<string, mixed> */
    private function retrieve(StripeClient $client, string $resource, string $id): ?array
    {
        if (preg_match('/^[A-Za-z0-9_\-]{1,255}$/', $id) !== 1) {
            return null;
        }

        [$path, $query] = self::RESOURCES[$resource];
        $query = isset($query['expand']) ? ['expand' => array_map(fn (string $expand): string => (string) preg_replace('/^data\./', '', $expand), $query['expand'])] : [];

        try {
            return $client->get("{$path}/{$id}", $query);
        } catch (StripeFailure $failure) {
            if ($failure->reason === 'not_found') {
                return null;
            }

            throw $failure;
        }
    }

    /**
     * Stores what was fetched, in order: coupons, products, prices,
     * customers (and their matching), subscriptions (and their versions).
     * Runs inside the caller's transaction; makes no request.
     *
     * @param  array<string, list<array<string, mixed>>>  $fetched
     */
    private function write(StripeConnection $connection, array $fetched): void
    {
        $types = ['coupons' => StripeObject::COUPON, 'products' => StripeObject::PRODUCT, 'prices' => StripeObject::PRICE];

        foreach ($types as $resource => $type) {
            foreach ($fetched[$resource] ?? [] as $object) {
                $this->store($connection, $type, $object);
            }
        }

        $links = collect();

        foreach ($fetched['customers'] ?? [] as $customer) {
            $link = $this->customer($connection, $customer);

            if ($link !== null) {
                $links->push($link);
            }
        }

        foreach ($this->matcher->match($links) as $link) {
            $this->counts['linked']++;
            $this->versionsWritten = $this->ledger->follow($link, null) > 0 || $this->versionsWritten;
        }

        $subscriptions = $fetched['subscriptions'] ?? [];

        if ($subscriptions === []) {
            return;
        }

        $coupons = [];

        foreach (StripeObject::query()->where('connection_id', $connection->id)->where('type', StripeObject::COUPON)->orderBy('id')->get(['stripe_id', 'payload']) as $coupon) {
            $coupons[$coupon->stripe_id] = $coupon->payload;
        }

        $customerIds = array_values(array_unique(array_map(fn (array $subscription): string => (string) ($subscription['customer'] ?? ''), $subscriptions)));
        $byCustomer = StripeCustomerLink::query()->where('connection_id', $connection->id)->whereIn('stripe_customer_id', $customerIds)->get()->keyBy('stripe_customer_id');

        foreach ($subscriptions as $subscription) {
            $customer = (string) ($subscription['customer'] ?? '');
            $link = $byCustomer->get($customer) ?? $this->placeholder($connection, $customer);
            $byCustomer->put($customer, $link);
            $sha = $this->store($connection, StripeObject::SUBSCRIPTION, $subscription);
            $this->counts['subscriptions']++;

            if ($this->ledger->record($connection, $subscription, $coupons, $link, $sha) !== null) {
                $this->counts['versions']++;
                $this->versionsWritten = true;
            }
        }
    }

    /**
     * The Stripe customer's link, created the first time it is seen, with
     * the fields matching needs (minimised, §8). A deleted customer keeps
     * its link and is marked as such.
     *
     * @param  array<string, mixed>  $customer
     */
    private function customer(StripeConnection $connection, array $customer): ?StripeCustomerLink
    {
        $id = $customer['id'] ?? null;

        if (! is_string($id) || $id === '') {
            return null;
        }

        $link = StripeCustomerLink::query()->firstOrNew(['connection_id' => $connection->id, 'stripe_customer_id' => $id]);

        if (! $link->exists) {
            $link->forceFill(['company_id' => $connection->company_id, 'status' => StripeCustomerLink::UNLINKED]);
        }

        if (($customer['deleted'] ?? false) === true) {
            $link->forceFill(['deleted_in_stripe' => true])->save();
            $this->counts['customers']++;

            return $link;
        }

        $address = is_array($customer['address'] ?? null) ? $customer['address'] : null;
        $taxIds = [];

        foreach ((array) (is_array($customer['tax_ids'] ?? null) ? ($customer['tax_ids']['data'] ?? []) : []) as $taxId) {
            if (is_array($taxId) && is_string($taxId['type'] ?? null) && is_string($taxId['value'] ?? null)) {
                $taxIds[] = ['type' => $taxId['type'], 'value' => $taxId['value'], 'country' => is_string($taxId['country'] ?? null) ? $taxId['country'] : null];
            }
        }

        $metadata = is_array($customer['metadata'] ?? null) ? $customer['metadata'] : [];
        $country = is_string($address['country'] ?? null) ? strtoupper(substr($address['country'], 0, 2)) : null;

        $link->forceFill([
            'name' => is_string($customer['name'] ?? null) ? mb_substr($customer['name'], 0, 255) : null,
            'email' => is_string($customer['email'] ?? null) ? mb_substr($customer['email'], 0, 255) : null,
            'country' => $country,
            'address' => $address === null ? null : array_map(fn (mixed $value): ?string => is_string($value) ? mb_substr($value, 0, 255) : null, array_intersect_key($address, array_flip(['line1', 'line2', 'postal_code', 'city', 'state', 'country']))),
            'tax_ids' => $taxIds,
            'nonox_customer_number' => is_scalar($metadata['nonox_customer_number'] ?? null) ? mb_substr((string) $metadata['nonox_customer_number'], 0, 64) : null,
            'deleted_in_stripe' => false,
        ])->save();
        $this->counts['customers']++;

        return $link;
    }

    /** A subscription whose Stripe customer Stripe could not return: counted under a provisional customer of its own. */
    private function placeholder(StripeConnection $connection, string $customer): StripeCustomerLink
    {
        return StripeCustomerLink::query()->firstOrCreate(
            ['connection_id' => $connection->id, 'stripe_customer_id' => $customer === '' ? 'okand' : $customer],
            ['company_id' => $connection->company_id, 'status' => StripeCustomerLink::UNLINKED],
        );
    }

    /**
     * The object as received, once per hash.
     *
     * @param  array<string, mixed>  $object
     */
    private function store(StripeConnection $connection, string $type, array $object): string
    {
        $json = (string) json_encode(self::canonical($object), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $sha = hash('sha256', $json);

        $this->counts['objects'] += StripeObject::query()->insertOrIgnore([
            'company_id' => $connection->company_id,
            'connection_id' => $connection->id,
            'type' => $type,
            'stripe_id' => (string) ($object['id'] ?? ''),
            'payload' => $json,
            'sha256' => $sha,
            'fetched_at' => now(),
        ]);

        return $sha;
    }

    /**
     * The id of the object an event is about (for a tax id, its customer).
     *
     * @param  array<string, mixed>  $event
     */
    public static function eventObject(array $event): ?string
    {
        $object = is_array($event['data']['object'] ?? null) ? $event['data']['object'] : [];
        $type = (string) ($event['type'] ?? '');
        $id = str_starts_with($type, 'customer.tax_id.') || str_starts_with($type, 'customer.discount.') ? ($object['customer'] ?? null) : ($object['id'] ?? null);

        return is_string($id) && $id !== '' ? mb_substr($id, 0, 255) : null;
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }
}
