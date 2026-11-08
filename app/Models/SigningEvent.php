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
 * @property int $id
 * @property int $company_id
 * @property int $signing_request_id
 * @property SigningEventType $event
 * @property ?int $user_id
 * @property ?string $ip
 * @property ?string $user_agent
 * @property CarbonImmutable $created_at
 */
class SigningEvent extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $table = 'crm_signing_events';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'signing_request_id',
        'event',
        'user_id',
        'ip',
        'user_agent',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'event' => SigningEventType::class,
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
