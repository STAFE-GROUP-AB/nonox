<?php

declare(strict_types=1);

namespace App\Modules\Connect\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A remembered POST (ADR-032): the Idempotency-Key, a hash of the request
 * and, once it is done, the response, kept for 24 hours per credential.
 *
 * @property int $id
 * @property int $company_id
 * @property string $credential
 * @property string $idempotency_key
 * @property string $request_hash
 * @property string $status
 * @property ?int $response_status
 * @property ?string $response_body
 * @property ?array<string, string> $response_headers
 * @property CarbonImmutable $expires_at
 */
class IdempotencyRecord extends Model
{
    use BelongsToCompany;

    public const string PROCESSING = 'processing';

    public const string DONE = 'done';

    protected $table = 'connect_idempotency_keys';

    /** @var list<string> */
    protected $fillable = ['company_id', 'credential', 'idempotency_key', 'request_hash', 'status', 'response_status', 'response_body', 'response_headers', 'expires_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
            'response_headers' => 'array',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
