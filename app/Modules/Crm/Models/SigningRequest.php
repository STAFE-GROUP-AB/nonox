<?php

declare(strict_types=1);

namespace App\Modules\Crm\Models;

use App\Models\SalesDocument;
use App\Models\User;
use App\Modules\Crm\Domain\SigningStatus;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One link sent for signing (ADR-046): a quote or an agreement, the exact
 * PDF that is shown (document_path, document_sha256), who it went to, and,
 * once the signer has acted, the evidence of a simple electronic signature
 * (eIDAS art. 3(10)): name, title, the drawn or typed signature, time, IP
 * address and browser. Only the SHA-256 of the token is stored. A signed or
 * declined request never changes (PostgreSQL trigger).
 *
 * @property int $id
 * @property int $company_id
 * @property string $document_type
 * @property ?string $sales_document_id
 * @property ?int $agreement_id
 * @property ?int $deal_id
 * @property string $token_hash
 * @property string $email
 * @property SigningStatus $status
 * @property CarbonImmutable $expires_at
 * @property string $document_path
 * @property string $document_name
 * @property string $document_sha256
 * @property ?string $content_hash
 * @property bool $create_invoice
 * @property ?CarbonImmutable $opened_at
 * @property ?string $signer_name
 * @property ?string $signer_title
 * @property ?string $signature_method
 * @property ?string $signature_image base64 PNG, encrypted at rest
 * @property ?CarbonImmutable $signed_at
 * @property ?string $signer_ip
 * @property ?string $signer_user_agent
 * @property ?string $signed_document_path
 * @property ?string $signed_document_sha256
 * @property ?CarbonImmutable $declined_at
 * @property ?string $decline_reason
 * @property ?CarbonImmutable $revoked_at
 * @property ?string $order_id
 * @property ?string $invoice_id
 * @property ?CarbonImmutable $followed_up_at
 * @property ?int $sent_by
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 */
class SigningRequest extends Model
{
    use BelongsToCompany;

    public const string QUOTE = 'QUOTE';

    public const string AGREEMENT = 'AGREEMENT';

    public const string DRAWN = 'DRAWN';

    public const string TYPED = 'TYPED';

    /** The private disk the documents are kept on. */
    public const string DISK = 'local';

    protected $table = 'crm_signing_requests';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'document_type',
        'sales_document_id',
        'agreement_id',
        'deal_id',
        'token_hash',
        'email',
        'expires_at',
        'document_path',
        'document_name',
        'document_sha256',
        'content_hash',
        'create_invoice',
        'sent_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'PENDING',
    ];

    /** @var list<string> */
    protected $hidden = ['token_hash', 'signature_image'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => SigningStatus::class,
            'expires_at' => 'immutable_datetime',
            'opened_at' => 'immutable_datetime',
            'signed_at' => 'immutable_datetime',
            'declined_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'followed_up_at' => 'immutable_datetime',
            'create_invoice' => 'boolean',
            'signature_image' => 'encrypted',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function isQuote(): bool
    {
        return $this->document_type === self::QUOTE;
    }

    /** The stored status, or Expired for a pending link past its expiry. */
    public function effectiveStatus(?CarbonImmutable $now = null): SigningStatus
    {
        if ($this->status === SigningStatus::Pending && $this->expires_at->lessThanOrEqualTo($now ?? CarbonImmutable::now())) {
            return SigningStatus::Expired;
        }

        return $this->status;
    }

    public function isUsable(?CarbonImmutable $now = null): bool
    {
        return $this->effectiveStatus($now) === SigningStatus::Pending;
    }

    /** @return BelongsTo<SalesDocument, $this> */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(SalesDocument::class, 'sales_document_id');
    }

    /** @return BelongsTo<Agreement, $this> */
    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }

    /** @return BelongsTo<Deal, $this> */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    /** @return HasMany<SigningEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(SigningEvent::class)->orderBy('id');
    }
}
