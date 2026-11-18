<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A link issued to a signer in an envelope with several signers (ADR-046
 * amendment, gap 1). Only the SHA-256 of the 256-bit token is stored; the
 * token itself is only in the e-mail. A token is issued when the signer's
 * step opens; it never changes and can only be revoked, once (PostgreSQL
 * trigger). A one-signer link keeps its token on the request, as before.
 *
 * @property int $id
 * @property int $company_id
 * @property int $signing_request_id
 * @property string $token_hash
 * @property CarbonImmutable $issued_at
 * @property string $reason SENT, REMINDER or RESENT
 * @property ?CarbonImmutable $revoked_at
 */
class SigningLinkToken extends Model
{
    use BelongsToCompany;

    public const string SENT = 'SENT';

    public const string REMINDER = 'REMINDER';

    /** Times are written with their offset (ADR-046 S9): a reminder is due from the time the link was issued, also across the repeated autumn hour. */
    protected $dateFormat = 'Y-m-d H:i:sP';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'signing_request_id',
        'token_hash',
        'issued_at',
        'reason',
    ];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'issued_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<SigningRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(SigningRequest::class, 'signing_request_id');
    }
}
