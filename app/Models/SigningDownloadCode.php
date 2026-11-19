<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A six-digit code sent to a completion link's own address before the files of
 * a confidential envelope are downloaded (ADR-046 S13 amendment, 2026-11-19).
 * Only an HMAC-SHA256 keyed with the app key over the link id and the code is
 * kept. The row never changes except its attempts (upwards) and the time it
 * passed (once, PostgreSQL trigger).
 *
 * @property int $id
 * @property int $company_id
 * @property int $download_link_id
 * @property string $code_mac
 * @property CarbonImmutable $sent_at
 * @property CarbonImmutable $expires_at
 * @property int $max_attempts
 * @property int $attempts
 * @property ?CarbonImmutable $verified_at
 */
class SigningDownloadCode extends Model
{
    use BelongsToCompany;

    protected $dateFormat = 'Y-m-d H:i:sP';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'download_link_id',
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
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** Still usable: not passed, not expired, attempts left. */
    public function isOpen(?CarbonImmutable $now = null): bool
    {
        return $this->verified_at === null && $this->expires_at->greaterThan($now ?? CarbonImmutable::now()) && $this->attempts < $this->max_attempts;
    }

    /** @return BelongsTo<SigningDownloadLink, $this> */
    public function link(): BelongsTo
    {
        return $this->belongsTo(SigningDownloadLink::class, 'download_link_id');
    }
}
