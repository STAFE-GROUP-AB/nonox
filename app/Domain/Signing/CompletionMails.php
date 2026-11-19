<?php

declare(strict_types=1);

namespace App\Domain\Signing;

use App\Mail\SignedDocumentMail;
use App\Mail\SigningEnvelopeMail;
use App\Models\SigningDownloadLink;

/**
 * The completion mail a download link belongs to (ADR-046 S13), rebuilt from
 * the link row so that "Skicka ny länk" sends the same mail the recipient
 * first got, with the new token.
 */
final class CompletionMails
{
    public static function forLink(SigningDownloadLink $link, string $token): SignedDocumentMail|SigningEnvelopeMail
    {
        if ($link->kind === SigningDownloadLink::SIGNER && $link->signing_request_id !== null) {
            return new SignedDocumentMail($link->signing_request_id, $link->company_id, true, $link->language, $token);
        }

        // A single signer's company copy is the signer's mail without the recipient's own thanks.
        if ($link->kind === SigningDownloadLink::COMPANY && $link->signing_request_id !== null) {
            return new SignedDocumentMail($link->signing_request_id, $link->company_id, false, $link->language, $token);
        }

        return new SigningEnvelopeMail(
            $link->envelope_id,
            $link->company_id,
            $link->kind === SigningDownloadLink::COMPANY ? SigningEnvelopeMail::COMPANY : SigningEnvelopeMail::COPY,
            toSigner: $link->kind === SigningDownloadLink::COPY && $link->signing_request_id !== null,
            language: $link->language,
            downloadToken: $token,
        );
    }
}
