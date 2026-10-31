<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Models;

use App\Models\SupplierInvoice;
use App\Models\User;
use App\Modules\Inbox\Domain\Channel;
use App\Modules\Inbox\Domain\DocumentType;
use App\Modules\Inbox\Domain\Interpreter;
use App\Modules\Inbox\Domain\MessageStatus;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A message received by the company's inbox. The original (.eml) is kept
 * byte for byte on the encrypted private disk, with every attachment
 * unaltered; both are linked to the vouchers the message leads to.
 *
 * The proposal is what the interpreters made of it: either vouchers
 * (kind "vouchers"), a daily report (kind "daily_report": the fields read
 * from the report and how each maps to an account) or a supplier invoice
 * (kind "supplier_invoice"). Nothing in it is booked until a person
 * approves it, or a template the person approved books it (ADR-023).
 *
 * @property string $id
 * @property int $company_id
 * @property Channel $channel
 * @property string $recipient
 * @property ?string $alias
 * @property string $from_email
 * @property ?string $from_name
 * @property ?string $subject
 * @property ?string $message_id
 * @property CarbonImmutable $received_at
 * @property string $eml_path
 * @property string $eml_sha256
 * @property int $eml_size
 * @property ?string $body_text
 * @property array<string, mixed> $auth
 * @property MessageStatus $status
 * @property list<array{code: string, message: string}> $quarantine_reasons
 * @property list<array{code: string, message: string}> $warnings
 * @property ?DocumentType $document_type
 * @property ?Interpreter $interpreted_by
 * @property ?array<string, mixed> $proposal
 * @property ?int $template_id
 * @property list<string> $voucher_ids
 * @property ?string $supplier_invoice_id
 * @property ?string $rejection_reason
 * @property ?int $released_by
 * @property ?CarbonImmutable $released_at
 * @property ?int $handled_by
 * @property ?CarbonImmutable $handled_at
 * @property ?CarbonImmutable $created_at
 * @property-read Collection<int, InboxAttachment> $attachments
 * @property-read ?InboxTemplate $template
 * @property-read ?SupplierInvoice $supplierInvoice
 * @property-read ?User $handler
 */
class InboxMessage extends Model
{
    use BelongsToCompany;
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'company_id', 'channel', 'recipient', 'alias', 'from_email', 'from_name', 'subject', 'message_id',
        'received_at', 'eml_path', 'eml_sha256', 'eml_size', 'body_text', 'auth', 'status', 'quarantine_reasons',
        'warnings', 'document_type', 'interpreted_by', 'proposal', 'template_id', 'voucher_ids', 'supplier_invoice_id',
        'rejection_reason', 'released_by', 'released_at', 'handled_by', 'handled_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'quarantine_reasons' => '[]',
        'warnings' => '[]',
        'voucher_ids' => '[]',
        'auth' => '{}',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'received_at' => 'immutable_datetime',
            'eml_size' => 'integer',
            // Mail text can hold personal data; it is encrypted at rest like the files.
            'body_text' => 'encrypted',
            'auth' => 'array',
            'status' => MessageStatus::class,
            'quarantine_reasons' => 'array',
            'warnings' => 'array',
            'document_type' => DocumentType::class,
            'interpreted_by' => Interpreter::class,
            'proposal' => 'array',
            'voucher_ids' => 'array',
            'released_at' => 'immutable_datetime',
            'handled_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<InboxAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(InboxAttachment::class)->orderBy('position');
    }

    /** @return BelongsTo<InboxTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(InboxTemplate::class);
    }

    /** @return BelongsTo<SupplierInvoice, $this> */
    public function supplierInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class);
    }

    /** @return BelongsTo<User, $this> */
    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }

    public function hasQuarantineReason(string $code): bool
    {
        return in_array($code, array_column($this->quarantine_reasons, 'code'), true);
    }

    /** Whether the sender's own authentication (SPF, DKIM, DMARC) failed or the mail was flagged as spam. */
    public function failedAuthentication(): bool
    {
        return (bool) ($this->auth['failed'] ?? false) || (bool) ($this->auth['spam'] ?? false);
    }

    public function senderLabel(): string
    {
        return filled($this->from_name) ? "{$this->from_name} <{$this->from_email}>" : $this->from_email;
    }
}
