<?php

declare(strict_types=1);

namespace App\Modules\Connect\Http\Middleware;

use App\Modules\Connect\Models\IdempotencyRecord;
use App\Modules\Connect\Support\ConnectAccess;
use App\Modules\Connect\Support\Problem;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotency-Key on every POST (ADR-032): a retried request must never
 * book twice. Per credential, the key, a SHA-256 of the request and the
 * response are kept for 24 hours.
 *
 * - No key: 400.
 * - Same key, same request, done: the stored response again, with
 *   Idempotent-Replayed: true.
 * - Same key, different request: 422.
 * - Same key while the first request is still running: 409.
 * - A server error (5xx) is not stored, so the same key can be retried.
 */
final class RequireIdempotencyKey
{
    public const string HEADER = 'Idempotency-Key';

    public function handle(Request $request, Closure $next): Response
    {
        $credential = ConnectAccess::current();

        if ($credential === null) {
            return Problem::unauthenticated();
        }

        $key = (string) $request->headers->get(self::HEADER, '');

        if ($key === '') {
            return Problem::make(400, 'idempotency_key_required', __('Skicka en unik Idempotency-Key (till exempel ett UUID) med varje POST, så att ett nytt försök aldrig bokför två gånger.'));
        }

        if (strlen($key) > 255 || preg_match('/^[\x21-\x7E]+$/', $key) !== 1) {
            return Problem::make(400, 'idempotency_key_invalid', __('Idempotency-Key ska vara 1–255 synliga ASCII-tecken.'));
        }

        $hash = $this->fingerprint($request);
        $scope = ['credential' => $credential->key(), 'idempotency_key' => $key];

        IdempotencyRecord::query()->where($scope)->where('expires_at', '<', now())->delete();

        $inserted = DB::table('connect_idempotency_keys')->insertOrIgnore([
            ...$scope,
            'company_id' => $credential->company->id,
            'request_hash' => $hash,
            'status' => IdempotencyRecord::PROCESSING,
            'expires_at' => now()->addHours((int) config('services.connect.idempotency_hours', 24)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted === 0) {
            return $this->existing($scope, $hash);
        }

        $response = $next($request);

        if ($response->getStatusCode() >= 500) {
            IdempotencyRecord::query()->where($scope)->delete();

            return $response;
        }

        IdempotencyRecord::query()->where($scope)->update([
            'status' => IdempotencyRecord::DONE,
            'response_status' => $response->getStatusCode(),
            'response_body' => (string) $response->getContent(),
            'response_headers' => json_encode(array_filter([
                'Content-Type' => $response->headers->get('Content-Type'),
                'Location' => $response->headers->get('Location'),
            ])),
        ]);

        return $response;
    }

    /**
     * @param  array{credential: string, idempotency_key: string}  $scope
     */
    private function existing(array $scope, string $hash): Response
    {
        $record = IdempotencyRecord::query()->where($scope)->first();

        if ($record === null) {
            return Problem::make(409, 'idempotency_in_progress', __('En begäran med samma Idempotency-Key pågår. Vänta och försök igen.'), [], ['Retry-After' => '1']);
        }

        if (! hash_equals($record->request_hash, $hash)) {
            return Problem::make(422, 'idempotency_key_reused', __('Idempotency-Key har redan använts för en annan begäran. Använd en ny nyckel för en ny begäran.'));
        }

        if ($record->status !== IdempotencyRecord::DONE) {
            return Problem::make(409, 'idempotency_in_progress', __('En begäran med samma Idempotency-Key pågår. Vänta och försök igen.'), [], ['Retry-After' => '1']);
        }

        return new Response((string) $record->response_body, (int) $record->response_status, [
            ...($record->response_headers ?? []),
            'Idempotent-Replayed' => 'true',
        ]);
    }

    /** A SHA-256 of what the request asks for: method, path, body and the content of any files. */
    private function fingerprint(Request $request): string
    {
        $files = [];

        foreach ($request->allFiles() as $name => $file) {
            foreach (is_array($file) ? $file : [$file] as $index => $one) {
                $files[$name.'.'.$index] = hash_file('sha256', (string) $one->getRealPath());
            }
        }

        $body = $request->isJson() ? (string) $request->getContent() : json_encode($request->except(array_keys($request->allFiles())));

        return hash('sha256', implode("\n", [$request->method(), $request->path(), (string) $body, (string) json_encode($files)]));
    }
}
