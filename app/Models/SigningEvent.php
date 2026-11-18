<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Signing\SigningEventType;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A step in the audit trail of a link sent for signing (ADR-046). Never
 * updated (PostgreSQL trigger and here). The IP address and browser are
 * kept only for the signer's own acts, signing and declining.
 *
 * Every event of an envelope is a link in its hash chain (ADR-046
 * amendment, gap 4): written only through SigningChain::record, which
 * takes the envelope's row lock and sets the sequence, the previous hash
 * and the hash. The time is written in whole seconds, as the column
 * keeps it, so the chain hashes what is stored.
 *
 * @property int $id
 * @property int $company_id
 * @property int $signing_request_id
 * @property SigningEventType $event
 * @property ?int $user_id
 * @property ?string $ip
 * @property ?string $user_agent
 * @property ?string $envelope_id
 * @property ?int $sequence
 * @property ?string $previous_hash
 * @property ?string $hash
 * @property ?array<string, mixed> $payload
 * @property CarbonImmutable $created_at
 */
class SigningEvent extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $table = 'crm_signing_events';

    /** Times are written with their offset, so the repeated autumn hour stores the instant the chain hashed (ADR-046 S2). */
    protected $dateFormat = 'Y-m-d H:i:sP';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'signing_request_id',
        'event',
        'user_id',
        'ip',
        'user_agent',
        'envelope_id',
        'sequence',
        'previous_hash',
        'hash',
        'payload',
        'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'event' => SigningEventType::class,
            'sequence' => 'integer',
            'payload' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Signing events are append-only (ADR-046).'));
    }

    /** @return BelongsTo<SigningRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(SigningRequest::class, 'signing_request_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
