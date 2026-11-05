<?php

declare(strict_types=1);

namespace App\Support\Skatteverket;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * A call through Skatteverket's API gateway (ADR-054): the person's bearer
 * token, the gateway's own client id and secret as headers, and a
 * correlation id per call. Skatteverket blocks calls with headers a service
 * does not list, so each API names its correlation header (Momsdeklaration
 * "skv_client_correlation_id", AGD inlämning and Skattekonto v2
 * "SKV-client_correlationid").
 */
final class SkatteverketClient
{
    /**
     * @param  array<string, mixed>|null  $body
     *
     * @throws SkatteverketFailed
     */
    public static function send(string $accessToken, string $method, string $url, ?array $body = null, string $correlationHeader = 'skv_client_correlation_id'): Response
    {
        $request = Http::withHeaders([
            'client_id' => Skatteverket::apiClientId(),
            'client_secret' => Skatteverket::apiClientSecret(),
            $correlationHeader => (string) Str::uuid(),
        ])->withToken($accessToken)->acceptJson()->timeout(30);

        try {
            $response = $body === null
                ? $request->send($method, $url)
                : $request->asJson()->send($method, $url, ['json' => $body]);
        } catch (ConnectionException) {
            throw new SkatteverketFailed(503, __('Skatteverket svarar inte just nu. Försök igen om en stund.'));
        }

        if ($response->successful()) {
            return $response;
        }

        throw self::failure($response);
    }

    private static function failure(Response $response): SkatteverketFailed
    {
        $status = $response->status();
        $message = $response->json('meddelandeTillAnvandare');
        $code = $response->json('kod');

        if (is_string($message) && $message !== '') {
            return new SkatteverketFailed($status, $message, is_scalar($code) ? (string) $code : null);
        }

        return new SkatteverketFailed($status, match (true) {
            $status === 401 => __('Inloggningen hos Skatteverket har gått ut. Logga in hos Skatteverket igen.'),
            $status === 403 => __('Skatteverket säger att du inte har behörighet att företräda företaget i den här tjänsten. Du behöver vara firmatecknare eller ombud med rätt behörighet hos Skatteverket.'),
            $status === 404 => __('Skatteverket hittar inte uppgiften för företaget och perioden.'),
            $status === 409 => __('Utkastet är låst eller håller på att skrivas under hos Skatteverket. Lås upp det hos Skatteverket först om du vill ändra.'),
            $status === 429 => __('Skatteverket tar emot för många anrop just nu. Försök igen om en minut.'),
            $status >= 500 => __('Skatteverket svarar inte just nu. Försök igen om en stund.'),
            default => __('Skatteverket tog inte emot uppgifterna (felkod :status).', ['status' => $status]),
        }, is_scalar($code) ? (string) $code : null);
    }
}
