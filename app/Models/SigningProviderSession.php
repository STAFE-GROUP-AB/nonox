<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Signing\Providers\SignatureLevel;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One signer's run through a signing provider (ADR-046 amendment, gap 12,
 * package S17): SERVICE (identified at the provider), CREDENTIAL (the
 * document's hash authorised at the provider), then DONE or FAILED.
 *
 * While it runs, `secrets` holds the PKCE verifier, the provider's tokens
 * and the signer's form, encrypted at rest and hidden from every array or
 * JSON form; the browser is sent to the provider with a random state whose
 * SHA-256 alone is stored. When the run ends, secrets, state and the
 * prepared file are emptied (PostgreSQL trigger: a finished run can only be
 * emptied). A DONE run keeps the public facts of the signature: the
 * certificate's common name, issuer, serial, fingerprint and expiry, the
 * level the company states for the provider, and the version it signed.
 *
 * @property int $id
 * @property int $company_id
 * @property int $signing_request_id
 * @property int $provider_id
 * @property string $step
 * @property ?string $state_hash
 * @property ?array<string, mixed> $secrets
 * @property ?string $prepared_path
 * @property string $shown_sha256
 * @property ?string $digest_sha256
 * @property ?string $certificate_name
 * @property ?string $certificate_issuer
 * @property ?string $certificate_serial
 * @property ?string $certificate_sha256
 * @property ?CarbonImmutable $certificate_not_after
 * @property SignatureLevel $claimed_level
 * @property string $provider_name
 * @property ?int $signed_version_id
 * @property ?string $failure
 * @property CarbonImmutable $expires_at
 * @property ?CarbonImmutable $completed_at
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 */
class SigningProviderSession extends Model
{
    use BelongsToCompany;

    public const string SERVICE = 'SERVICE';

    public const string CREDENTIAL = 'CREDENTIAL';

    public const string DONE = 'DONE';

    public const string FAILED = 'FAILED';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'signing_request_id',
        'provider_id',
        'step',
        'state_hash',
        'secrets',
        'prepared_path',
        'shown_sha256',
        'claimed_level',
        'provider_name',
        'expires_at',
    ];

    /** @var list<string> never in an array, JSON, API answer or Livewire payload */
    protected $hidden = ['secrets', 'state_hash', 'prepared_path'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'secrets' => 'encrypted:array',
            'claimed_level' => SignatureLevel::class,
            'certificate_not_after' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<SigningProvider, $this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(SigningProvider::class, 'provider_id');
    }

    /** @return BelongsTo<SigningRequest, $this> */
    public function signer(): BelongsTo
    {
        return $this->belongsTo(SigningRequest::class, 'signing_request_id');
    }

    public function isRunning(): bool
    {
        return in_array($this->step, [self::SERVICE, self::CREDENTIAL], true);
    }
}
