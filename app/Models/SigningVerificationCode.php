<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A six-digit code sent to a signer's e-mail before the document is shown
 * (ADR-046 amendment 2026-11-18, gap 3, package S3), or to an ÄTA customer
 * on site (ADR-074 O4). Only an HMAC-SHA256 keyed with the app key over the
 * request id and the code is kept: a plain SHA-256 of six digits is reversed
 * by brute force from a copy of the database. The row never changes except
 * its attempts (upwards) and the time it passed (once, PostgreSQL trigger).
 *
 * @property int $id
 * @property int $company_id
 * @property int $signing_request_id
 * @property string $channel LINK or ON_SITE
 * @property string $code_mac
 * @property CarbonImmutable $sent_at
 * @property CarbonImmutable $expires_at
 * @property int $max_attempts
 * @property int $attempts
 * @property ?CarbonImmutable $verified_at
 */
class SigningVerificationCode extends Model
{
    use BelongsToCompany;

    public const string LINK = 'LINK';

    public const string ON_SITE = 'ON_SITE';

    /** Times are written with their offset, like the rest of the signing evidence (ADR-046 S2). */
    protected $dateFormat = 'Y-m-d H:i:sP';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'signing_request_id',
        'channel',
        'code_mac',
        'sent_at',
        'expires_at',
        'max_attempts',
    ];

    /** @var list<string> */
    protected $hidden = ['code_mac'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sent_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** Still usable: not passed, not expired, attempts left. */
    public function isOpen(?CarbonImmutable $now = null): bool
    {
        return $this->verified_at === null && $this->expires_at->greaterThan($now ?? CarbonImmutable::now()) && $this->attempts < $this->max_attempts;
    }

    /** @return BelongsTo<SigningRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(SigningRequest::class, 'signing_request_id');
    }
}
