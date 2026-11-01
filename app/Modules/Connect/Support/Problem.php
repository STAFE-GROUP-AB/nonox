<?php

declare(strict_types=1);

namespace App\Modules\Connect\Support;

use App\Modules\ModuleDisabledException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Errors from the API as RFC 9457 problem details (ADR-032): a stable
 * machine-readable `type` and `code`, an HTTP `status`, a short `title` and a
 * Swedish `detail` for people. Validation errors add `errors`, field by
 * field. A server error never reveals its cause.
 */
final class Problem
{
    public const string TYPE_PREFIX = 'urn:nonox:problem:';

    /**
     * @param  array<string, mixed>  $extra
     * @param  array<string, string>  $headers
     */
    public static function make(int $status, string $code, string $detail, array $extra = [], array $headers = []): JsonResponse
    {
        $body = [
            'type' => self::TYPE_PREFIX.$code,
            'title' => self::title($status),
            'status' => $status,
            'code' => $code,
            'detail' => $detail,
            ...$extra,
        ];

        $requestId = request()->attributes->get('connect.request_id');

        if (is_string($requestId)) {
            $body['instance'] = 'urn:nonox:request:'.$requestId;
        }

        return new JsonResponse($body, $status, [
            'Content-Type' => 'application/problem+json',
            'Cache-Control' => 'no-store',
            ...$headers,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function notFound(): JsonResponse
    {
        return self::make(404, 'not_found', __('Det du söker finns inte.'));
    }

    /** Renders any exception on /api/v1 and /oauth as problem+json; null leaves it to Laravel. */
    public static function fromException(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! self::applies($request)) {
            return null;
        }

        return match (true) {
            $exception instanceof HttpResponseException => null,
            $exception instanceof ValidationException => self::make(422, 'validation_failed', __('Uppgifterna kunde inte godtas. Se errors.'), ['errors' => $exception->errors()]),
            $exception instanceof AuthenticationException => self::unauthenticated(),
            $exception instanceof AuthorizationException => self::make(403, 'forbidden', __('Behörighet saknas för det här. Kontrollera nyckelns eller appens behörigheter och din roll i företaget.')),
            $exception instanceof ModelNotFoundException, $exception instanceof ModuleDisabledException => self::notFound(),
            $exception instanceof ThrottleRequestsException => self::make(429, 'rate_limited', __('För många anrop. Vänta och försök igen.'), [], array_map('strval', $exception->getHeaders())),
            $exception instanceof HttpExceptionInterface => self::fromHttp($exception),
            default => self::make(500, 'server_error', __('Något gick fel hos NONOX. Försök igen om en stund.')),
        };
    }

    public static function unauthenticated(): JsonResponse
    {
        return self::make(401, 'unauthenticated', __('Nyckeln eller token saknas, är ogiltig, har gått ut eller har återkallats.'), [], [
            'WWW-Authenticate' => 'Bearer realm="nonox", error="invalid_token"',
        ]);
    }

    public static function applies(Request $request): bool
    {
        return $request->is('api/*');
    }

    private static function fromHttp(HttpExceptionInterface $exception): JsonResponse
    {
        $status = $exception->getStatusCode();

        return match ($status) {
            404 => self::notFound(),
            403 => self::make(403, 'forbidden', $exception->getMessage() !== '' && $exception->getMessage() !== 'This action is unauthorized.' ? $exception->getMessage() : __('Behörighet saknas för det här.')),
            405 => self::make(405, 'method_not_allowed', __('Metoden stöds inte för den här adressen.'), [], array_map('strval', $exception->getHeaders())),
            409 => self::make(409, 'conflict', $exception->getMessage() !== '' ? $exception->getMessage() : __('Begäran krockar med resursens tillstånd.')),
            413 => self::make(413, 'payload_too_large', __('Begäran är för stor.')),
            415 => self::make(415, 'unsupported_media_type', __('Skicka JSON (application/json), eller multipart/form-data för underlag.')),
            default => self::make($status, $status >= 500 ? 'server_error' : 'http_error', $status >= 500 ? __('Något gick fel hos NONOX. Försök igen om en stund.') : __('Begäran kunde inte utföras.'), [], array_map('strval', $exception->getHeaders())),
        };
    }

    private static function title(int $status): string
    {
        return match ($status) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            409 => 'Conflict',
            413 => 'Content Too Large',
            415 => 'Unsupported Media Type',
            422 => 'Unprocessable Content',
            428 => 'Precondition Required',
            429 => 'Too Many Requests',
            default => $status >= 500 ? 'Internal Server Error' : 'Error',
        };
    }
}
