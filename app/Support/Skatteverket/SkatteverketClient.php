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
     * @param  array<string, mixed>|null  $body  a JSON body
     * @param  ?string  $raw  a body sent as it is, e.g. the AGI XML, with $contentType
     * @param  ?string  $contentType  the Content-Type of a call without a body, for operations whose RAML requires one
     * @param  list<int>  $answers  error statuses returned as a response instead of thrown, for operations that answer them with content (AGD 409 with "tillstand")
     * @param  ?string  $noAnswer  the text when Skatteverket does not answer in time, for an operation that may have reached it anyway (the AGD upload)
     *
     * @throws SkatteverketFailed
     */
    public static function send(string $accessToken, string $method, string $url, ?array $body = null, string $correlationHeader = 'skv_client_correlation_id', ?string $raw = null, ?string $contentType = null, array $answers = [], ?string $noAnswer = null): Response
    {
        $request = Http::withHeaders([
            'client_id' => Skatteverket::apiClientId(),
            'client_secret' => Skatteverket::apiClientSecret(),
            $correlationHeader => (string) Str::uuid(),
        ])->withToken($accessToken)->acceptJson()->timeout(30);

        try {
            $response = match (true) {
                $raw !== null => $request->withBody($raw, $contentType ?? 'application/octet-stream')->send($method, $url),
                $body !== null => $request->asJson()->send($method, $url, ['json' => $body]),
                $contentType !== null => $request->contentType($contentType)->send($method, $url),
                default => $request->send($method, $url),
            };
        } catch (ConnectionException) {
            throw $noAnswer === null
                ? new SkatteverketFailed(503, __('Skatteverket svarar inte just nu. Försök igen om en stund.'))
                : new SkatteverketFailed(504, $noAnswer, SkatteverketFailed::NO_ANSWER);
        }

        if ($noAnswer !== null && $response->status() === 504) {
            throw new SkatteverketFailed(504, $noAnswer, SkatteverketFailed::NO_ANSWER);
        }

        if ($response->successful() || in_array($response->status(), $answers, true)) {
            return $response;
        }

        throw self::failure($response);
    }

    private static function failure(Response $response): SkatteverketFailed
    {
        $status = $response->status();
        $message = $response->json('meddelandeTillAnvandare');
        $code = $response->json('kod');
        $error = $response->json('error');

        // The token lacks the API's scope (AGD RAML: "invalid_scope", "The required scopes are not authorized"): a sign-in made before the stage was built.
        if ($status === 403 && is_string($error) && str_contains(mb_strtolower($error), 'scope')) {
            return new SkatteverketFailed($status, __('Inloggningen hos Skatteverket gäller inte den här tjänsten. Logga in hos Skatteverket igen.'), 'invalid_scope', signInAgain: true);
        }

        if (is_string($message) && $message !== '') {
            return new SkatteverketFailed($status, $message, is_scalar($code) ? (string) $code : null);
        }

        return new SkatteverketFailed($status, match (true) {
            $status === 401 => __('Inloggningen hos Skatteverket har gått ut. Logga in hos Skatteverket igen.'),
            $status === 403 => __('Skatteverket säger att du inte har behörighet att företräda företaget i den här tjänsten. Du behöver vara firmatecknare eller ombud med rätt behörighet hos Skatteverket.'),
            $status === 404 => __('Skatteverket hittar inte uppgiften för företaget och perioden.'),
            $status === 409 => __('Utkastet är låst eller håller på att skrivas under hos Skatteverket, så det går inte att ändra just nu. Försök igen när underskriften är klar.'),
            $status === 429 => __('Skatteverket tar emot för många anrop just nu. Försök igen om en minut.'),
            $status >= 500 => __('Skatteverket svarar inte just nu. Försök igen om en stund.'),
            default => __('Skatteverket tog inte emot uppgifterna (felkod :status).', ['status' => $status]),
        }, is_scalar($code) ? (string) $code : null);
    }
}
