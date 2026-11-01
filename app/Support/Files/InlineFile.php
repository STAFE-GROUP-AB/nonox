<?php

declare(strict_types=1);

namespace App\Support\Files;

/**
 * Headers for a stored file served from NONOX's own origin (underlag,
 * supplier invoices, receipts, payslips, inbox attachments). The content
 * security policy puts the file in a sandbox, so even a file that is not
 * what its type says cannot run script with NONOX's cookies, while
 * frame-ancestors 'self' still lets NONOX's own pages show it in a frame.
 * A response that sets its own policy keeps it (SecurityHeaders).
 */
final class InlineFile
{
    public const string CONTENT_SECURITY_POLICY = "sandbox; default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; object-src 'self'; frame-ancestors 'self'";

    /**
     * @return array<string, string>
     */
    public static function headers(string $contentType): array
    {
        return [
            'Content-Type' => $contentType,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=0',
            'Content-Security-Policy' => self::CONTENT_SECURITY_POLICY,
        ];
    }
}
