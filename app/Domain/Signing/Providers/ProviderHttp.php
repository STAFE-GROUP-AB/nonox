<?php

declare(strict_types=1);

namespace App\Domain\Signing\Providers;

use App\Modules\Connect\Webhooks\UnsafeWebhookAddress;
use App\Modules\Connect\Webhooks\WebhookAddress;
use App\Modules\Connect\Webhooks\WebhookTarget;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Throwable;

/**
 * Every request NONOX makes to a signing provider (ADR-046 S17). The
 * address is company-supplied, so it passes the same server-side request
 * forgery guard as webhooks (WebhookAddress, ADR-032): https only, no user
 * name or password in it, every address the host resolves to public
 * unicast (no loopback, private, link-local, carrier-grade NAT, IPv4-mapped,
 * 6to4, Teredo or NAT64 address), and the request then goes to exactly the
 * address checked (CURLOPT_RESOLVE, so a second DNS answer cannot move it:
 * DNS pinning). Redirects are never followed, no proxy is used, TLS is
 * verified, the answer is capped, and nothing the provider answers is
 * logged. The check runs before every request, not only when saved.
 */
class ProviderHttp
{
    public const int CONNECT_TIMEOUT_SECONDS = 5;

    public const int TIMEOUT_SECONDS = 15;

    /** A certificate chain and a few signatures fit easily; anything larger is refused while it is read. */
    public const int MAX_RESPONSE_BYTES = 1024 * 1024;

    public const string USER_AGENT = 'NONOX-Signing/1.0';

    public function __construct(private readonly WebhookAddress $address) {}

    /** Whether an address may be used at all (checked again before every request). */
    public function check(string $url): void
    {
        $this->target($url);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws ProviderFailure
     */
    public function postJson(string $url, array $body, #[SensitiveParameter] ?string $bearer = null): array
    {
        return $this->send($url, function (PendingRequest $request) use ($url, $body, $bearer): Response {
            if ($bearer !== null) {
                $request = $request->withToken($bearer);
            }

            return $request->asJson()->post($url, $body === [] ? (object) [] : $body);
        });
    }

    /**
     * An OAuth 2.0 token request: form-encoded (RFC 6749 4.1.3), JSON answer.
     *
     * @param  array<string, string>  $fields
     * @return array<string, mixed>
     *
     * @throws ProviderFailure
     */
    public function postForm(string $url, #[SensitiveParameter] array $fields): array
    {
        return $this->send($url, fn (PendingRequest $request): Response => $request->asForm()->post($url, $fields));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ProviderFailure
     */
    public function getJson(string $url): array
    {
        return $this->send($url, fn (PendingRequest $request): Response => $request->get($url));
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     * @return array<string, mixed>
     *
     * @throws ProviderFailure
     */
    private function send(string $url, callable $call): array
    {
        $target = $this->target($url);

        try {
            $response = $call(Http::withHeaders(['User-Agent' => self::USER_AGENT, 'Accept' => 'application/json'])
                ->withOptions([
                    'allow_redirects' => false,
                    'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
                    'timeout' => self::TIMEOUT_SECONDS,
                    'verify' => true,
                    'proxy' => '',
                    'curl' => [
                        // Connect to the address that was checked, whatever DNS answers now.
                        CURLOPT_RESOLVE => [$target->curlResolve()],
                        CURLOPT_MAXFILESIZE => self::MAX_RESPONSE_BYTES,
                        CURLOPT_NOPROGRESS => false,
                        CURLOPT_PROGRESSFUNCTION => static fn (mixed $handle, int $downloadTotal, int $downloaded): int => $downloaded > self::MAX_RESPONSE_BYTES ? 1 : 0,
                    ],
                ]));
        } catch (ConnectionException) {
            throw new ProviderFailure(ProviderFailure::UNREACHABLE);
        } catch (ProviderFailure $failure) {
            throw $failure;
        } catch (Throwable) {
            throw new ProviderFailure(ProviderFailure::UNREACHABLE);
        }

        if ($response->status() >= 300 && $response->status() < 400) {
            // A redirect is never followed: a provider that answers with one is refused, not chased.
            throw new ProviderFailure(ProviderFailure::REFUSED, 'redirect');
        }

        if (! $response->successful()) {
            throw new ProviderFailure(ProviderFailure::REFUSED, "http_{$response->status()}");
        }

        if (strlen($response->body()) > self::MAX_RESPONSE_BYTES) {
            throw new ProviderFailure(ProviderFailure::INVALID_ANSWER, 'too_large');
        }

        $json = json_decode($response->body(), true);

        if (! is_array($json)) {
            throw new ProviderFailure(ProviderFailure::INVALID_ANSWER);
        }

        /** @var array<string, mixed> $json */
        return $json;
    }

    private function target(string $url): WebhookTarget
    {
        if (strlen($url) > 1000 || preg_match('/[\x00-\x20]/', $url) === 1) {
            throw new ProviderFailure(ProviderFailure::UNSAFE_ADDRESS);
        }

        try {
            return $this->address->target($url);
        } catch (UnsafeWebhookAddress $unsafe) {
            throw new ProviderFailure(ProviderFailure::UNSAFE_ADDRESS, $unsafe->reason);
        }
    }
}
