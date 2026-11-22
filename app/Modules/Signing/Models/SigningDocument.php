<?php

declare(strict_types=1);

namespace App\Modules\Signing\Models;

use App\Domain\Signing\RestrictsEnvelopes;
use App\Domain\Signing\Signable;
use App\Models\Company;
use App\Models\Customer;
use App\Models\SigningEnvelope;
use App\Models\SigningRequest;
use App\Models\Supplier;
use App\Models\User;
use App\Modules\Signing\SigningModule;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use LogicException;

/**
 * A standalone document for signing (ADR-046 amendment 2026-11-18, gap 10,
 * package S7): any PDF a company uploads, with a title and, if it wishes, a
 * customer or a supplier it belongs to. The file never changes; a new file
 * is a new document, and its fingerprint is the file's SHA-256. It is a
 * Signable of type DOCUMENT, kept by its key in signable_id.
 *
 * Who may send it is signing:send. Another member's envelope of the
 * document is reminded, moved and withdrawn only with signing:manage, and a
 * confidential envelope is seen only by its sender and by signing:manage
 * (RestrictsEnvelopes).
 *
 * @property string $id
 * @property int $company_id
 * @property string $title
 * @property string $path
 * @property string $name
 * @property string $sha256
 * @property int $bytes
 * @property int $page_count
 * @property ?int $customer_id
 * @property ?int $supplier_id
 * @property ?int $uploaded_by
 * @property ?CarbonImmutable $archived_at
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 */
class SigningDocument extends Model implements RestrictsEnvelopes, Signable
{
    use BelongsToCompany;
    use HasUuids;

    /** The type on signing requests and envelopes (at most ten characters, SignableTypes). */
    public const string SIGNING_TYPE = 'DOCUMENT';

    /** @var list<string> */
    protected $fillable = ['company_id', 'title', 'path', 'name', 'sha256', 'bytes', 'page_count', 'customer_id', 'supplier_id', 'uploaded_by'];

    protected static function booted(): void
    {
        static::updating(function (self $document): void {
            if ($document->isDirty(['company_id', 'path', 'name', 'sha256', 'bytes', 'page_count'])) {
                throw new LogicException('A signing document\'s file cannot be changed; upload a new document.');
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'bytes' => 'integer',
            'page_count' => 'integer',
            'archived_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /*
     * Signing at a link through the shared signing domain (ADR-046,
     * ADR-075 §7). The document is the uploaded file, byte for byte.
     */

    public function signingType(): string
    {
        return self::SIGNING_TYPE;
    }

    public function signingKey(): string
    {
        return $this->id;
    }

    public function signingCompanyId(): int
    {
        return $this->company_id;
    }

    public function signingTitle(): string
    {
        return $this->title;
    }

    public function signingDocument(): array
    {
        $bytes = Storage::disk(SigningRequest::DISK)->get($this->path);

        if (! is_string($bytes) || hash('sha256', $bytes) !== $this->sha256) {
            throw new LogicException('The stored file of a signing document is missing or has changed.');
        }

        return ['bytes' => $bytes, 'name' => $this->name];
    }

    /** The file never changes, so the fingerprint is its SHA-256. */
    public function signingFingerprint(): string
    {
        return $this->sha256;
    }

    /**
     * Open until every signer has signed (the envelope is completed): while
     * some have signed and others not, the later signers still sign. A
     * declined, withdrawn or expired envelope leaves it open for a new one.
     */
    public function signingIsOpen(): bool
    {
        return $this->archived_at === null
            && ! SigningEnvelope::query()->where('document_type', self::SIGNING_TYPE)->where('signable_id', $this->id)->where('status', 'COMPLETED')->exists();
    }

    public function signingAcceptance(): string
    {
        // TODO(legal-verify): wording against avtalslagen (1915:218) and eIDAS art. 3.10 and 25.1.
        return __('Jag har läst dokumentet och skriver under det. Skriver jag under för ett företag eller en organisation har jag rätt att göra det.');
    }

    public function signingModule(): ?string
    {
        return SigningModule::KEY;
    }

    public function signingValidUntil(): ?CarbonImmutable
    {
        return null;
    }

    public function signingDestination(): string
    {
        return 'signing';
    }

    /** Sending, and withdrawing a link, need signing:send; the module must be on (SendForSigning checks it). */
    public function authorizeSigning(User $user, bool $revoke = false): void
    {
        $company = Company::query()->find($this->company_id);

        if (! $company instanceof Company || ! $user->hasTeamPermission($company, 'signing:send')) {
            throw new AuthorizationException;
        }
    }

    /** No check of its own before a signature (Signable). */
    public function assertSignable(): void {}

    /** Nothing in the document changes when it is signed; the envelope and the signer's evidence hold the result. */
    public function markSigned(CarbonImmutable $signedAt, string $documentSha256): void {}

    public function mayViewEnvelope(User $user, Company $company, SigningEnvelope $envelope): bool
    {
        if ($envelope->company_id !== $company->id || $company->id !== $this->company_id || ! $user->hasTeamPermission($company, 'signing:view')) {
            return false;
        }

        return ! $envelope->confidential
            || $envelope->created_by === $user->id
            || $user->hasTeamPermission($company, 'signing:manage');
    }

    public function authorizeEnvelope(User $user, SigningEnvelope $envelope): void
    {
        $company = Company::query()->find($this->company_id);

        if (! $company instanceof Company || ! $user->hasTeamPermission($company, 'signing:send')) {
            throw new AuthorizationException;
        }

        if ($envelope->created_by !== $user->id && ! $user->hasTeamPermission($company, 'signing:manage')) {
            throw new AuthorizationException;
        }
    }
}
