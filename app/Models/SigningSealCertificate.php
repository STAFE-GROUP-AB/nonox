<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Signing\Seal\SealCertificates;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A company's own seal certificate (ADR-046 amendment, gap 5, package S10):
 * a PKCS#12 uploaded under Inställningar för företaget → Signering, used to
 * seal the company's finished signed documents instead of the instance's
 * self-signed seal. The PKCS#12 and its password are encrypted at rest and
 * hidden from every array or JSON form of the row; they are decrypted only
 * by the seal job. A certificate is replaced, never edited: retiring it
 * empties its key and password (PostgreSQL trigger).
 *
 * @property int $id
 * @property int $company_id
 * @property string $subject
 * @property string $issuer
 * @property string $serial
 * @property CarbonImmutable $not_before
 * @property CarbonImmutable $not_after
 * @property string $fingerprint_sha256
 * @property bool $self_signed
 * @property ?string $pkcs12 the PKCS#12 as base64 (decrypted by the cast)
 * @property ?string $password
 * @property ?int $uploaded_by
 * @property ?CarbonImmutable $retired_at
 * @property ?int $retired_by
 * @property ?int $expiry_task_id the to-do raised when it is about to expire, set once
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 */
class SigningSealCertificate extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'subject',
        'issuer',
        'serial',
        'not_before',
        'not_after',
        'fingerprint_sha256',
        'self_signed',
        'pkcs12',
        'password',
        'uploaded_by',
    ];

    /** @var list<string> never in an array, JSON, API answer or Livewire payload */
    protected $hidden = ['pkcs12', 'password'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'not_before' => 'immutable_datetime',
            'not_after' => 'immutable_datetime',
            'self_signed' => 'boolean',
            'pkcs12' => 'encrypted',
            'password' => 'encrypted',
            'retired_at' => 'immutable_datetime',
            'expiry_task_id' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $certificate): void {
            $allowed = ['retired_at', 'retired_by', 'pkcs12', 'password', 'expiry_task_id', 'updated_at', 'uploaded_by'];

            if (array_diff(array_keys($certificate->getDirty()), $allowed) !== []) {
                throw new LogicException('A seal certificate cannot be changed, only retired (ADR-046 S10).');
            }
        });
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('retired_at');
    }

    public function isExpired(?CarbonImmutable $now = null): bool
    {
        return $this->not_after->lessThanOrEqualTo($now ?? CarbonImmutable::now());
    }

    /** Whether it expires within the to-do window (ADR-046 gap 5, "Security": 60 days). */
    public function expiresSoon(?CarbonImmutable $now = null): bool
    {
        return $this->not_after->lessThanOrEqualTo(($now ?? CarbonImmutable::now())->addDays(SealCertificates::EXPIRY_WARNING_DAYS));
    }
}
