<?php

declare(strict_types=1);

namespace App\Domain\Signing\Chain;

use App\Models\SigningEvent;
use App\Models\SigningRequest;
use BackedEnum;
use DateTimeInterface;

/**
 * The hash of one link in an envelope's chain (ADR-046 amendment, gap 4):
 * hash = sha256(previous_hash || canonical_json(event)), the pattern of the
 * voucher chain (LedgerHash). The event's JSON holds every stored column
 * except the id and the two hashes: company, envelope, sequence, signing
 * request, event type, user, IP address, browser, time and payload. The
 * first event's previous_hash is the document's SHA-256, so the chain is
 * bound to the document that was sent.
 */
final class EventHash
{
    /** @param  array<string, mixed>  $fields  from fields() */
    public static function of(array $fields, string $previousHash): string
    {
        return hash('sha256', $previousHash.CanonicalJson::encode($fields));
    }

    public static function ofEvent(SigningEvent $event): string
    {
        return self::of(self::fieldsOf($event), (string) $event->previous_hash);
    }

    /** @return array<string, mixed> */
    public static function fieldsOf(SigningEvent $event): array
    {
        return self::fields(
            companyId: (int) $event->company_id,
            envelopeId: (string) $event->envelope_id,
            sequence: (int) $event->sequence,
            requestId: (int) $event->signing_request_id,
            event: $event->event,
            userId: $event->user_id === null ? null : (int) $event->user_id,
            ip: $event->ip,
            userAgent: $event->user_agent,
            createdAt: $event->created_at,
            payload: $event->payload,
        );
    }

    /**
     * @param  ?array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function fields(
        int $companyId,
        string $envelopeId,
        int $sequence,
        int $requestId,
        mixed $event,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
        DateTimeInterface|string $createdAt,
        ?array $payload,
    ): array {
        return [
            'company_id' => $companyId,
            'created_at' => CanonicalJson::time($createdAt),
            'envelope_id' => $envelopeId,
            'event' => $event instanceof BackedEnum ? $event->value : (string) $event,
            'ip' => $ip,
            'payload' => $payload === [] ? null : $payload,
            'sequence' => $sequence,
            'signing_request_id' => $requestId,
            'user_agent' => $userAgent,
            'user_id' => $userId,
        ];
    }

    /**
     * The digest of a signer's act as the signing request stores it (signed
     * or declined): every evidence column, the drawn signature included. It
     * is put in the SIGNED or DECLINED event's payload, so the chain also
     * covers the request row; the values themselves stay on the row.
     */
    public static function evidenceOf(SigningRequest $request): string
    {
        return hash('sha256', CanonicalJson::encode([
            'acceptance_text' => $request->acceptance_text,
            'content_hash' => $request->content_hash,
            'decline_reason' => $request->decline_reason,
            'declined_at' => CanonicalJson::seconds($request->declined_at),
            'document_sha256' => $request->document_sha256,
            'email' => $request->email,
            'signature_image' => $request->signature_image,
            'signature_method' => $request->signature_method,
            'signed_at' => CanonicalJson::seconds($request->signed_at),
            'signer_ip' => $request->signer_ip,
            'signer_name' => $request->signer_name,
            'signer_title' => $request->signer_title,
            'signer_user_agent' => $request->signer_user_agent,
            'status' => $request->status->value,
        ]));
    }
}
