<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A link to the finished files of a signing (ADR-046 amendment, gap 5, S13),
 * used instead of attachments when the files are above the mail limit. It
 * has its own 256-bit token, valid 14 days (7 for the company's and for a confidential envelope's); only the SHA-256 is stored. It
 * gives the files its kind names and nothing else (PostgreSQL trigger: only
 * the download counter changes).
 *
 * @property int $id
 * @property int $company_id
 * @property string $envelope_id
 * @property ?int $signing_request_id
 * @property string $kind SIGNER, COPY or COMPANY
 * @property string $email
 * @property string $language
 * @property string $token_hash
 * @property CarbonImmutable $expires_at
 * @property int $downloads
 * @property ?CarbonImmutable $revoked_at
 */
class SigningDownloadLink extends Model
{
    use BelongsToCompany;

    public const string SIGNER = 'SIGNER';

    public const string COPY = 'COPY';

    public const string COMPANY = 'COMPANY';

    /** How long a link works: 14 days, enough to download. */
    public const int VALID_DAYS = 14;

    /** The company's link (every certificate) and any link of a confidential envelope work 7 days. */
    public const int SHORT_DAYS = 7;

    /** A row stays this many days after the link expired, so an old link says "expired" before it says nothing. */
    public const int PRUNE_AFTER_DAYS = 30;

    /** Times are written with their offset, as for the signing links (ADR-046 S9). */
    protected $dateFormat = 'Y-m-d H:i:sP';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'envelope_id',
        'signing_request_id',
        'kind',
        'email',
        'language',
        'token_hash',
        'expires_at',
    ];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<SigningEnvelope, $this> */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(SigningEnvelope::class, 'envelope_id');
    }

    /** @return HasMany<SigningDownloadEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(SigningDownloadEvent::class, 'download_link_id');
    }

    /** @return BelongsTo<SigningRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(SigningRequest::class, 'signing_request_id');
    }

    public static function validDays(string $kind, bool $confidential): int
    {
        return $kind === self::COMPANY || $confidential ? self::SHORT_DAYS : self::VALID_DAYS;
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
