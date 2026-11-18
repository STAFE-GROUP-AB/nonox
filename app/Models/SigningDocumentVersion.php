<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A file of a signing envelope with its SHA-256 (ADR-046 amendment, gaps 2
 * and 4): the original that was sent (ORIGINAL, package S2), later the
 * version after each signer (AFTER_SIGNER, S6), the sealed one (SEALED,
 * S10) and the timestamped one (TIMESTAMPED, S11). Every file a signer was
 * shown or received is here, so signing:verify can check it against the
 * stored bytes. Append-only (PostgreSQL trigger and here); deleted only
 * with its envelope.
 *
 * @property int $id
 * @property int $company_id
 * @property string $envelope_id
 * @property string $kind
 * @property ?int $signing_request_id
 * @property string $path
 * @property string $sha256
 * @property ?int $bytes
 * @property CarbonImmutable $created_at
 */
class SigningDocumentVersion extends Model
{
    use BelongsToCompany;

    public const string ORIGINAL = 'ORIGINAL';

    public const string AFTER_SIGNER = 'AFTER_SIGNER';

    public const string SEALED = 'SEALED';

    public const string TIMESTAMPED = 'TIMESTAMPED';

    public const null UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'envelope_id',
        'kind',
        'signing_request_id',
        'path',
        'sha256',
        'bytes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'bytes' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Signing document versions are append-only (ADR-046 S2).'));
    }

    /** @return BelongsTo<SigningEnvelope, $this> */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(SigningEnvelope::class, 'envelope_id');
    }
}
