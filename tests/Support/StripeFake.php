<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Saas\Stripe\Support\StripeSleeper;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A fake Stripe account behind Http::fake (ADR-080 build step 2: no test
 * calls Stripe). Objects are synthetic and shaped as the API reference shows
 * them for API version 2026-10-28.endive; lists page with `limit` and
 * `starting_after`; the restricted key is checked on every request. No real
 * keys, accounts or customers.
 */
final class StripeFake
{
    public const string KEY = 'rk_test_FakeRestrictedKey000000000001';

    public string $account = 'acct_1FakeAccount00001';

    /** @var array<string, array<string, mixed>> */
    public array $customers = [];

    /** @var array<string, array<string, mixed>> */
    public array $subscriptions = [];

    /** @var array<string, array<string, mixed>> */
    public array $coupons = [];

    /** @var array<string, array<string, mixed>> */
    public array $products = [];

    /** @var array<string, array<string, mixed>> */
    public array $prices = [];

    /** @var list<array<string, mixed>> newest first, as Stripe lists them */
    public array $events = [];

    /** @var list<string> paths answered 403, as for a key without the permission */
    public array $forbidden = [];

    /** @var list<int> statuses to answer before the real answer, one per request */
    public array $failures = [];

    /** @var array<string, callable(): void> run once when a request whose log line contains the key has been answered */
    public array $hooks = [];

    /** A request whose log line contains this is answered 503, failTimes times. */
    public ?string $failWhen = null;

    public int $failTimes = 0;

    /** @var list<string> every request: method, path and query (never the key) */
    public array $log = [];

    public bool $revoked = false;

    public string $key = self::KEY;

    /** @var array<string, self> the fake accounts of the running test, by key */
    private static array $accounts = [];

    /**
     * A fake account; a second one in the same test gets its own key and
     * account id (another company's Stripe account).
     */
    public static function install(int $number = 1): self
    {
        $fake = new self;
        $fake->key = sprintf('rk_test_FakeRestrictedKey%012d', $number);
        $fake->account = sprintf('acct_1FakeAccount%05d', $number);

        if ($number === 1) {
            self::$accounts = [];
            app()->instance(StripeSleeper::class, new StripeSleeper(sleeps: false));
            Http::preventStrayRequests();
            Http::fake(['https://api.stripe.com/*' => function (Request $request) use ($fake) {
                $key = substr((string) ($request->header('Authorization')[0] ?? ''), 7);

                return (self::$accounts[$key] ?? $fake)->answer($request);
            }]);
        }

        self::$accounts[$fake->key] = $fake;

        return $fake;
    }

    /** @param  array<string, mixed>  $overrides */
    public function customer(string $id, array $overrides = []): array
    {
        return $this->customers[$id] = [
            'id' => $id,
            'object' => 'customer',
            'name' => 'Kund '.$id,
            'email' => null,
            'address' => ['line1' => 'Testgatan 1', 'line2' => null, 'postal_code' => '111 22', 'city' => 'Teststad', 'state' => null, 'country' => 'SE'],
            'metadata' => [],
            'tax_ids' => ['object' => 'list', 'data' => [], 'has_more' => false],
            'livemode' => false,
            ...$overrides,
        ];
    }

    /**
     * A subscription with one licensed item.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function subscription(string $id, string $customer, int $unitAmount, string $currency = 'sek', string $interval = 'month', int $count = 1, int $quantity = 1, array $overrides = []): array
    {
        $start = (int) ($overrides['start_date'] ?? strtotime('2026-01-01 00:00:00 UTC'));

        return $this->subscriptions[$id] = [
            'id' => $id,
            'object' => 'subscription',
            'customer' => $customer,
            'currency' => $currency,
            'status' => 'active',
            'start_date' => $start,
            'created' => $start,
            'trial_start' => null,
            'trial_end' => null,
            'cancel_at' => null,
            'cancel_at_period_end' => false,
            'canceled_at' => null,
            'ended_at' => null,
            'pause_collection' => null,
            'discounts' => [],
            'livemode' => false,
            'metadata' => [],
            'items' => ['object' => 'list', 'has_more' => false, 'data' => [self::item("si_{$id}", "price_{$id}", "prod_{$id}", $unitAmount, $currency, $interval, $count, $quantity)]],
            ...$overrides,
        ];
    }

    /** @return array<string, mixed> */
    public static function item(string $id, string $price, string $product, ?int $unitAmount, string $currency = 'sek', string $interval = 'month', int $count = 1, int $quantity = 1, string $usage = 'licensed', string $scheme = 'per_unit'): array
    {
        return [
            'id' => $id,
            'object' => 'subscription_item',
            'quantity' => $quantity,
            'current_period_start' => strtotime('2026-01-01 00:00:00 UTC'),
            'current_period_end' => strtotime('2026-02-01 00:00:00 UTC'),
            'discounts' => [],
            'price' => [
                'id' => $price,
                'object' => 'price',
                'currency' => $currency,
                'product' => $product,
                'billing_scheme' => $scheme,
                'type' => 'recurring',
                'unit_amount' => $unitAmount,
                'unit_amount_decimal' => $unitAmount === null ? null : (string) $unitAmount,
                'recurring' => ['interval' => $interval, 'interval_count' => $count, 'usage_type' => $usage, 'trial_period_days' => null],
            ],
        ];
    }

    /** An event the account would list, newest first. */
    public function event(string $id, string $type, string $objectId, int $created, array $object = []): array
    {
        $event = ['id' => $id, 'object' => 'event', 'type' => $type, 'created' => $created, 'livemode' => false, 'data' => ['object' => ['id' => $objectId, ...$object]]];
        array_unshift($this->events, $event);

        return $event;
    }

    private function answer(Request $request): PromiseInterface
    {
        $url = parse_url($request->url());
        $path = (string) ($url['path'] ?? '');
        parse_str((string) ($url['query'] ?? ''), $query);
        $this->log[] = $request->method().' '.$path.' '.http_build_query($query);

        foreach ($this->hooks as $needle => $hook) {
            if (str_contains(end($this->log), $needle)) {
                unset($this->hooks[$needle]);
                $hook();
            }
        }

        if ($request->method() !== 'GET') {
            return Http::response(['error' => ['type' => 'invalid_request_error']], 405);
        }

        if ($this->revoked || $request->header('Authorization') !== ['Bearer '.$this->key]) {
            return Http::response(['error' => ['type' => 'invalid_request_error', 'message' => 'Invalid API Key provided: '.substr((string) ($request->header('Authorization')[0] ?? ''), 7, 12).'***']], 401);
        }

        if ($this->failWhen !== null && $this->failTimes > 0 && str_contains(end($this->log), $this->failWhen)) {
            $this->failTimes--;

            return Http::response(['error' => ['type' => 'api_error']], 503);
        }

        if ($this->failures !== []) {
            $status = array_shift($this->failures);

            return Http::response(['error' => ['type' => 'api_error']], $status);
        }

        foreach ($this->forbidden as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return Http::response(['error' => ['type' => 'invalid_request_error', 'message' => 'The provided key does not have the required permissions.']], 403);
            }
        }

        if ($path === '/v1/account') {
            return Http::response(['id' => $this->account, 'object' => 'account']);
        }

        $collections = [
            'customers' => $this->customers,
            'subscriptions' => $this->subscriptions,
            'coupons' => $this->coupons,
            'products' => $this->products,
            'prices' => $this->prices,
        ];

        if (preg_match('#^/v1/(customers|subscriptions|coupons|products|prices)/([^/]+)$#', $path, $match) === 1) {
            $object = $collections[$match[1]][$match[2]] ?? null;

            return $object === null
                ? Http::response(['error' => ['type' => 'invalid_request_error']], 404)
                : Http::response($object);
        }

        if (preg_match('#^/v1/(customers|subscriptions|coupons|products|prices|events)$#', $path, $match) === 1) {
            $all = $match[1] === 'events'
                ? array_values(array_filter($this->events, fn (array $event): bool => $event['created'] >= (int) ($query['created']['gte'] ?? 0)))
                : array_values($match[1] === 'subscriptions' && ($query['status'] ?? null) !== 'all'
                    ? array_filter($collections[$match[1]], fn (array $subscription): bool => $subscription['status'] !== 'canceled')
                    : $collections[$match[1]]);
            $limit = (int) ($query['limit'] ?? 10);
            $offset = 0;

            if (isset($query['starting_after'])) {
                foreach ($all as $index => $object) {
                    if ($object['id'] === $query['starting_after']) {
                        $offset = $index + 1;
                    }
                }
            }

            $page = array_slice($all, $offset, $limit);

            return Http::response(['object' => 'list', 'url' => $path, 'has_more' => $offset + $limit < count($all), 'data' => $page]);
        }

        return Http::response(['error' => ['type' => 'invalid_request_error']], 404);
    }
}
