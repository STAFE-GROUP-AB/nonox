<?php

declare(strict_types=1);

namespace App\Domain\Signing;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * A document that can be sent for signing with a link (ADR-046, ADR-075
 * §7): a quote (core), an agreement (CRM), and later a work order
 * (ADR-074). The signing domain only talks to this interface: it renders
 * the PDF once, keeps its SHA-256 and the document's fingerprint, and on
 * signing checks that the document still says what it said when it was
 * sent. Each type is registered in SignableTypes with how a signing
 * request finds it again.
 */
interface Signable
{
    /** The type stored on the signing request, at most ten characters: QUOTE, AGREEMENT, … */
    public function signingType(): string;

    /** The document's own key as a string (a uuid or an id). */
    public function signingKey(): string;

    public function signingCompanyId(): int;

    /** "Offert 12", or an agreement's title. */
    public function signingTitle(): string;

    /**
     * The exact PDF that is shown and signed, and its file name.
     *
     * @return array{bytes: string, name: string}
     */
    public function signingDocument(): array;

    /** A SHA-256 over what the document says; a document changed after sending cannot be signed. */
    public function signingFingerprint(): string;

    /** Whether the document can still be signed: an open quote, an agreement not signed yet. */
    public function signingIsOpen(): bool;

    /** The confirmation the signer ticks, exactly as shown and stored. */
    public function signingAcceptance(): string;

    /** The module that must be on for the document's links to work, or null for a core document. */
    public function signingModule(): ?string;

    /** The last day a link may be valid (a quote's last valid day), or null. */
    public function signingValidUntil(): ?CarbonImmutable;

    /** Where the company's notice about the signature opens (a CompanyOverview destination). */
    public function signingDestination(): string;

    /**
     * May the user send it for signing (or revoke a link)? Revoking is
     * allowed after the document has closed; sending needs it open.
     *
     * @throws AuthorizationException
     */
    public function authorizeSigning(User $user, bool $revoke = false): void;

    /** Called inside the signing transaction, with the document locked, once it is signed. */
    public function markSigned(CarbonImmutable $signedAt, string $documentSha256): void;
}
