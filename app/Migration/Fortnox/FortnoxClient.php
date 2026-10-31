<?php

declare(strict_types=1);

namespace App\Migration\Fortnox;

use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * Read-only calls to Fortnox's API (https://api.fortnox.se/3) for one
 * connection. Refreshes the access token when it is about to expire, keeps
 * within Fortnox's limit of 25 requests per 5 seconds per token, waits and
 * retries on 429, and pages through lists (page and limit, MetaInformation).
 */
class FortnoxClient
{
    public const string BASE_URL = 'https://api.fortnox.se/3/';

    public const int REQUESTS_PER_WINDOW = 25;

    public const int WINDOW_SECONDS = 5;

    public const int PAGE_SIZE = 500;

    /** @var list<float> */
    private array $sent = [];

    public function __construct(private readonly FortnoxConnection $connection) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        $json = $this->send($path, $query)->json();

        if (! is_array($json)) {
            throw new RuntimeException("Fortnox answered {$path} with something that is not JSON.");
        }

        return $json;
    }

    /**
     * The raw body, for files and SIE exports.
     *
     * @param  array<string, mixed>  $query
     */
    public function download(string $path, array $query = []): string
    {
        return $this->send($path, $query)->body();
    }

    /**
     * Every item of a paged list.
     *
     * @param  array<string, mixed>  $query
     * @return Generator<int, array<string, mixed>>
     */
    public function each(string $path, string $key, array $query = []): Generator
    {
        $page = 1;

        do {
            $json = $this->get($path, [...$query, 'page' => $page, 'limit' => self::PAGE_SIZE]);

            foreach ((array) ($json[$key] ?? []) as $item) {
                if (is_array($item)) {
                    yield $item;
                }
            }

            $pages = (int) ($json['MetaInformation']['@TotalPages'] ?? 1);
            $page++;
        } while ($page <= $pages);
    }

    /** @param array<string, mixed> $query */
    private function send(string $path, array $query, int $attempt = 1): Response
    {
        $this->throttle();

        try {
            $response = Http::baseUrl(self::BASE_URL)
                ->withToken($this->accessToken())
                ->acceptJson()
                ->timeout(60)
                ->get(ltrim($path, '/'), $query);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Fortnox did not answer.', 0, $exception);
        }

        if ($response->status() === 429 && $attempt < 5) {
            Sleep::for(2 * $attempt)->seconds();

            return $this->send($path, $query, $attempt + 1);
        }

        if ($response->status() === 401 && $attempt === 1) {
            $this->refreshToken();

            return $this->send($path, $query, $attempt + 1);
        }

        if ($response->failed()) {
            $message = $response->json('ErrorInformation.message') ?? $response->json('ErrorInformation.Message');

            throw new RuntimeException('Fortnox answered '.$response->status()." for {$path}".(is_string($message) ? ": {$message}" : '.'));
        }

        return $response;
    }

    private function accessToken(): string
    {
        if ($this->connection->access_token === null || $this->connection->expires_at === null || $this->connection->expires_at->lt(CarbonImmutable::now()->addMinute())) {
            $this->refreshToken();
        }

        return (string) $this->connection->access_token;
    }

    private function refreshToken(): void
    {
        if ($this->connection->refresh_token === null) {
            throw new RuntimeException('The connection to Fortnox is closed.');
        }

        $token = FortnoxOAuth::refresh($this->connection->refresh_token);

        $this->connection->forceFill([
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'],
            'expires_at' => CarbonImmutable::now()->addSeconds($token['expires_in']),
        ])->save();
    }

    /** Waits so that at most REQUESTS_PER_WINDOW requests are sent within any WINDOW_SECONDS. */
    private function throttle(): void
    {
        $now = microtime(true);
        $this->sent = array_values(array_filter($this->sent, fn (float $at): bool => $at > $now - self::WINDOW_SECONDS));

        if (count($this->sent) >= self::REQUESTS_PER_WINDOW) {
            Sleep::for((int) ceil(($this->sent[0] + self::WINDOW_SECONDS - $now) * 1000))->milliseconds();
        }

        $this->sent[] = microtime(true);
    }
}
