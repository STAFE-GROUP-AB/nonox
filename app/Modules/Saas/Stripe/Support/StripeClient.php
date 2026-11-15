<?php

declare(strict_types=1);

namespace App\Modules\Saas\Stripe\Support;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Read-only calls to the Stripe API with one company's restricted key
 * (ADR-080 §7.1, §7.3). Plain Laravel Http, no SDK (addendum step 2): GET
 * only, to api.stripe.com only, no redirects, a timeout, the API version
 * pinned with the Stripe-Version header, and the key sent only as the Bearer
 * credential. Nothing here logs: a failure is a StripeFailure with a fixed
 * reason, never Stripe's message or the key.
 *
 * Limits (docs.stripe.com/rate-limits, read 2026-11-15: 100 requests per
 * second globally in live mode, 25 per endpoint, 429 with
 * Stripe-Rate-Limited-Reason, lock timeouts also 429): a client-side window of
 * at most MAX_PER_SECOND requests per second for the connection (one request
 * at a time, since the sync of a connection never overlaps itself), and 429,
 * 5xx and lost connections retried with exponential backoff and jitter.
 */
final class StripeClient
{
    /** The version this integration was written against (docs.stripe.com/api/versioning: "The current version is 2026-10-28.endive"). */
    public const string API_VERSION = '2026-10-28.endive';

    public const int MAX_PER_SECOND = 20;

    public const int MAX_ATTEMPTS = 5;

    public const int PAGE_SIZE = 100;

    public int $requests = 0;

    /** @var list<float> when the last requests were sent */
    private array $sent = [];

    /** @var Closure(int): void */
    private Closure $sleep;

    /**
     * @param  ?Closure(int): void  $sleep  waits the given milliseconds; tests pass a no-op
     */
    public function __construct(private readonly string $key, ?Closure $sleep = null)
    {
        $this->sleep = $sleep ?? app(StripeSleeper::class)->closure();
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        $attempt = 0;

        while (true) {
            $attempt++;
            $this->throttle();

            try {
                $response = $this->request()->get(self::base().$path, $query);
            } catch (ConnectionException) {
                $response = null;
            }

            $this->requests++;
            $status = $response?->status() ?? 0;

            if ($response !== null && $response->successful()) {
                $body = $response->json();

                return is_array($body) ? $body : throw new StripeFailure('failed', $path);
            }

            $retry = $status === 0 || $status === 429 || $status >= 500;

            if ($retry && $attempt < self::MAX_ATTEMPTS) {
                ($this->sleep)($this->backoff($attempt, $response));

                continue;
            }

            throw new StripeFailure(match (true) {
                $status === 401 => 'unauthorized',
                $status === 403 => 'forbidden',
                $status === 404 => 'not_found',
                $status === 429 => 'rate_limited',
                $status === 0, $status >= 500 => 'unavailable',
                default => 'failed',
            }, $path);
        }
    }

    /**
     * One page of a list endpoint (docs.stripe.com/api/pagination: `limit` up
     * to 100, `starting_after` the last id of the previous page, `has_more`).
     *
     * @param  array<string, mixed>  $query
     * @return array{data: list<array<string, mixed>>, has_more: bool, last: ?string}
     */
    public function page(string $path, array $query = [], ?string $startingAfter = null): array
    {
        $query = ['limit' => self::PAGE_SIZE, ...$query];

        if ($startingAfter !== null) {
            $query['starting_after'] = $startingAfter;
        }

        $body = $this->get($path, $query);
        $data = array_values(array_filter(is_array($body['data'] ?? null) ? $body['data'] : [], 'is_array'));
        $last = $data === [] ? null : (string) ($data[array_key_last($data)]['id'] ?? '');

        return ['data' => $data, 'has_more' => ($body['has_more'] ?? false) === true && $last !== null && $last !== '', 'last' => $last];
    }

    public static function base(): string
    {
        return rtrim((string) config('services.stripe.base_url', 'https://api.stripe.com'), '/');
    }

    private function request(): PendingRequest
    {
        return Http::withToken($this->key)
            ->withHeaders(['Stripe-Version' => self::API_VERSION])
            ->acceptJson()
            ->timeout(20)
            ->connectTimeout(5)
            ->withOptions(['allow_redirects' => false]);
    }

    /** At most MAX_PER_SECOND requests in any second. */
    private function throttle(): void
    {
        $now = microtime(true);
        $this->sent = array_values(array_filter($this->sent, fn (float $at): bool => $now - $at < 1.0));

        if (count($this->sent) >= self::MAX_PER_SECOND) {
            ($this->sleep)((int) ceil((1.0 - ($now - $this->sent[0])) * 1000));
        }

        $this->sent[] = microtime(true);
    }

    /** Exponential backoff with full jitter: up to 0.5, 1, 2, 4 s; Retry-After when Stripe sends it. */
    private function backoff(int $attempt, ?Response $response): int
    {
        $after = $response?->header('Retry-After');

        if (is_string($after) && ctype_digit($after)) {
            return min(30, (int) $after) * 1000;
        }

        $cap = 500 * (2 ** ($attempt - 1));

        return random_int(intdiv($cap, 2), $cap);
    }
}
