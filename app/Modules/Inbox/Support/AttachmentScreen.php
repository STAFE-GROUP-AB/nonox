<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Support;

/**
 * The checks every file the inbox keeps goes through before it may be
 * read or served: dangerous types are always blocked (DangerousContent),
 * and with a scanner configured the contents are scanned. A scanner that
 * cannot be reached blocks the file. Used for mail attachments and for
 * files found inside them, such as the PDF embedded in a Peppol invoice.
 */
final readonly class AttachmentScreen
{
    public function __construct(private VirusScanner $scanner) {}

    /** Null when the file may be kept and shown, otherwise why not, in Swedish. */
    public function reason(string $filename, string $contents): ?string
    {
        $blocked = DangerousContent::reason($filename, $contents);

        if ($blocked !== null || ! $this->scanner->enabled()) {
            return $blocked;
        }

        try {
            return $this->scanner->scan($contents);
        } catch (VirusScanFailed) {
            return __('Viruskontrollen kunde inte göras. Bilagan släpps inte igenom förrän den har kontrollerats.');
        }
    }
}
