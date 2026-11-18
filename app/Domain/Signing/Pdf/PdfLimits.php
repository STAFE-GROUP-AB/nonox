<?php

declare(strict_types=1);

namespace App\Domain\Signing\Pdf;

/**
 * The limits on a PDF the engine accepts (owner decision 22 in ADR-046's
 * amendment 2026-11-18): 20 MB and 100 pages. They are product decisions, not
 * settings, so they are constants. Both PHP and the helper enforce them.
 */
final class PdfLimits
{
    public const int MAX_BYTES = 20 * 1024 * 1024;

    public const int MAX_PAGES = 100;

    /**
     * A finished file is the original plus stamped fields, the certificate
     * pages and the seal, so a file the engine wrote may exceed MAX_BYTES by
     * this much when it is read again (seal, timestamp, validate).
     */
    public const int FINISHED_FILE_SLACK_BYTES = 10 * 1024 * 1024;

    /** Pixels per rendered page image (about 2 000 × 2 000), whatever scale is asked for. */
    public const int MAX_PIXELS_PER_PAGE = 4_000_000;

    /** A stamped image (a signature or initials) may be at most this large. */
    public const int MAX_STAMP_IMAGE_BYTES = 5 * 1024 * 1024;

    /** At most this many stamps in one call. */
    public const int MAX_STAMPS = 500;
}
