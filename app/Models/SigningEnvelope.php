<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Signing\EnvelopeStatus;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One document out for signing (ADR-046 amendment 2026-11-18, gap 1): up to
 * ten signers (SigningRequest rows with a step 1–10; signers on the same
 * step sign in parallel, and a step opens when every signer of the step
 * before has signed) and up to ten copy recipients. A one-signer envelope is
 * what a link always was. When the last signer has signed the envelope is
 * COMPLETED and the document type's follow-up runs once; a decline by
 * anyone ends it (DECLINED); the sender can withdraw it (VOIDED). A finished
 * envelope never changes, except its follow-up time (PostgreSQL trigger).
 *
 * @property string $id
 * @property int $company_id
 * @property string $document_type
 * @property ?string $sales_document_id
 * @property ?int $agreement_id
 * @property ?string $signable_id
 * @property ?string $title
 * @property EnvelopeStatus $status
 * @property int $current_step
 * @property string $document_path
 * @property string $document_name
 * @property string $document_sha256
 * @property ?string $content_hash
 * @property ?CarbonImmutable $deadline_at
 * @property ?int $created_by
 * @property ?CarbonImmutable $sent_at
 * @property ?CarbonImmutable $completed_at
 * @property ?CarbonImmutable $declined_at
 * @property ?CarbonImmutable $voided_at
 * @property ?int $voided_by
 * @property ?CarbonImmutable $followed_up_at
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 */
class SigningEnvelope extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public const int MAX_SIGNERS = 10;

    public const int MAX_COPIES = 10;

    public const int MAX_STEP = 10;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'document_type',
        'sales_document_id',
        'agreement_id',
        'signable_id',
        'title',
        'current_step',
        'document_path',
        'document_name',
        'document_sha256',
        'content_hash',
        'deadline_at',
        'created_by',
        'sent_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'OUT',
        'current_step' => 1,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => EnvelopeStatus::class,
            'current_step' => 'integer',
            'deadline_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'declined_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
            'followed_up_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** The stored status, or Expired for an envelope out past its deadline. */
    public function effectiveStatus(?CarbonImmutable $now = null): EnvelopeStatus
    {
        if ($this->status === EnvelopeStatus::Out && $this->deadline_at !== null && $this->deadline_at->lessThanOrEqualTo($now ?? CarbonImmutable::now())) {
            return EnvelopeStatus::Expired;
        }

        return $this->status;
    }

    /** @return HasMany<SigningRequest, $this> */
    public function signers(): HasMany
    {
        return $this->hasMany(SigningRequest::class, 'envelope_id')->orderBy('step')->orderBy('id');
    }

    /** @return HasMany<SigningCopyRecipient, $this> */
    public function copies(): HasMany
    {
        return $this->hasMany(SigningCopyRecipient::class, 'envelope_id')->orderBy('id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
