<?php

declare(strict_types=1);

namespace App\Modules\Connect\Http\Resources;

use App\Models\SigningCopyRecipient;
use App\Models\SigningEnvelope;
use App\Models\SigningField;
use App\Models\SigningRequest;
use App\Modules\Signing\Models\SigningDocument;
use App\Modules\Signing\Models\SigningTemplate;
use App\Modules\Signing\Models\SigningTemplateRole;
use DateTimeInterface;

/**
 * Signing over the API (ADR-046 S19): the shapes of documents, envelopes,
 * recipients, fields and templates. What is never returned: a link or a
 * token, an access code or its hash, a signer's IP address, browser or drawn
 * signature, a decline reason, the values a signer filled in, the legal hold
 * and the retention bookkeeping. The signed files are fetched from their own
 * routes, which apply the same visibility as the envelope.
 */
final class SigningPresenter
{
    /**
     * The envelope with its recipients and copy recipients; both must be
     * loaded (the list loads them for the whole page in two queries).
     *
     * @return array<string, mixed>
     */
    public static function envelope(SigningEnvelope $envelope): array
    {
        return [
            'id' => $envelope->id,
            'document_type' => $envelope->document_type,
            'quote_id' => $envelope->sales_document_id,
            'agreement_id' => $envelope->agreement_id,
            'document_id' => $envelope->document_type === SigningDocument::SIGNING_TYPE ? $envelope->signable_id : null,
            'signable_id' => $envelope->signable_id,
            'template_id' => $envelope->template_id,
            'status' => $envelope->effectiveStatus()->value,
            'current_step' => $envelope->current_step,
            'confidential' => $envelope->confidential,
            'auto_reminders' => $envelope->auto_reminders,
            'reminder_interval_days' => $envelope->reminder_interval_days,
            'deadline_at' => self::time($envelope->deadline_at),
            'sent_at' => self::time($envelope->sent_at),
            'completed_at' => self::time($envelope->completed_at),
            'declined_at' => self::time($envelope->declined_at),
            'voided_at' => self::time($envelope->voided_at),
            'expired_at' => self::time($envelope->expired_at),
            'document_name' => $envelope->document_name,
            'document_sha256' => $envelope->document_sha256,
            'created_by' => $envelope->created_by,
            'recipients' => $envelope->signers->map(fn (SigningRequest $signer): array => self::recipient($signer))->values()->all(),
            'copy_recipients' => $envelope->copies->map(fn (SigningCopyRecipient $copy): array => [
                'id' => $copy->id,
                'email' => $copy->email,
                'name' => $copy->name,
                'sent_at' => self::time($copy->sent_copy_at),
            ])->values()->all(),
            'created_at' => self::time($envelope->created_at),
            'updated_at' => self::time($envelope->updated_at),
        ];
    }

    /** @return array<string, mixed> */
    public static function recipient(SigningRequest $signer): array
    {
        return [
            'id' => $signer->id,
            'envelope_id' => $signer->envelope_id,
            'step' => $signer->step,
            'status' => $signer->effectiveStatus()->value,
            'email' => $signer->email,
            'language' => $signer->language,
            'verification' => $signer->verification->value,
            'locked' => $signer->locked_at !== null,
            'opened_at' => self::time($signer->opened_at),
            'signed_at' => self::time($signer->signed_at),
            'signer_name' => $signer->signer_name,
            'signer_title' => $signer->signer_title,
            'signature_method' => $signer->signature_method,
            'signed_document_sha256' => $signer->signed_document_sha256,
            'declined_at' => self::time($signer->declined_at),
            'revoked_at' => self::time($signer->revoked_at),
            'reminder_count' => $signer->reminder_count,
            'last_reminded_at' => self::time($signer->last_reminded_at),
            'has_certificate' => $signer->signed_document_path !== null,
        ];
    }

    /** @return array<string, mixed> */
    public static function field(SigningField $field): array
    {
        return [
            'id' => $field->id,
            'recipient_id' => $field->signing_request_id,
            'type' => $field->type->value,
            'page' => $field->page,
            'x' => $field->x,
            'y' => $field->y,
            'width' => $field->width,
            'height' => $field->height,
            'page_width' => $field->page_width,
            'page_height' => $field->page_height,
            'label' => $field->label,
            'required' => $field->required,
            'options' => $field->options,
            'group_key' => $field->group_key,
            'rules' => $field->rules,
        ];
    }

    /** @return array<string, mixed> */
    public static function document(SigningDocument $document): array
    {
        return [
            'id' => $document->id,
            'title' => $document->title,
            'file_name' => $document->name,
            'sha256' => $document->sha256,
            'bytes' => $document->bytes,
            'page_count' => $document->page_count,
            'customer_id' => $document->customer_id,
            'supplier_id' => $document->supplier_id,
            'uploaded_by' => $document->uploaded_by,
            'archived_at' => self::time($document->archived_at),
            'created_at' => self::time($document->created_at),
            'updated_at' => self::time($document->updated_at),
        ];
    }

    /**
     * A template; with $detail its roles and (for a document with a file) its
     * fields, which the caller has loaded.
     *
     * @return array<string, mixed>
     */
    public static function template(SigningTemplate $template, bool $detail = false): array
    {
        $data = [
            'id' => $template->id,
            'name' => $template->name,
            'document_type' => $template->document_type,
            'file_name' => $template->file_name,
            'sha256' => $template->sha256,
            'bytes' => $template->bytes,
            'page_count' => $template->page_count,
            'deadline_days' => $template->deadline_days,
            'auto_reminders' => $template->auto_reminders,
            'reminder_days' => $template->reminder_days,
            'language' => $template->language,
            'confidential' => $template->confidential,
            'archived_at' => self::time($template->archived_at),
            'created_at' => self::time($template->created_at),
            'updated_at' => self::time($template->updated_at),
        ];

        if (! $detail) {
            return $data;
        }

        return [
            ...$data,
            'copy_recipients' => array_map(fn (array $copy): array => ['email' => (string) $copy['email'], 'name' => $copy['name'] ?? null, 'language' => (string) $copy['language']], (array) ($template->copies ?? [])),
            'roles' => $template->roles->map(fn (SigningTemplateRole $role): array => [
                'key' => $role->key,
                'label' => $role->label,
                'step' => $role->step,
                'verification' => $role->verification,
                'internal' => $role->internal,
            ])->values()->all(),
            'fields' => $template->fields->map(fn (SigningField $field): array => [
                ...self::field($field),
                'role_key' => $field->role_key,
            ])->values()->all(),
        ];
    }

    private static function time(?DateTimeInterface $time): ?string
    {
        return $time?->format(DATE_ATOM);
    }
}
