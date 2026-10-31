<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Support;

use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\MailMimeParser;

/**
 * Parses raw MIME (RFC 5322 with MIME multiparts and transfer encodings)
 * with zbateson/mail-mime-parser (BSD-2-Clause, ADR-023). The raw bytes are
 * stored separately and never changed; this only reads them.
 */
final class MailParser
{
    /** At most this many attachments are taken from one message. */
    public const int MAX_ATTACHMENTS = 25;

    public function parse(string $raw): ParsedMail
    {
        $message = (new MailMimeParser)->parse($raw, false);

        $from = $message->getHeader('From');
        $email = '';
        $name = null;

        if ($from instanceof AddressHeader && $from->getAddresses() !== []) {
            $email = (string) $from->getEmail();
            $name = $from->getPersonName();
        }

        $headers = [];

        foreach ($message->getAllHeaders() as $header) {
            $headers[strtolower($header->getName())][] = trim((string) preg_replace('/\s+/', ' ', $header->getRawValue()));
        }

        $text = (string) $message->getTextContent();

        if (trim($text) === '' && $message->getHtmlContent() !== null) {
            // HTML is never rendered: only its text is kept.
            $text = html_entity_decode(strip_tags((string) preg_replace('/<(br|\/p|\/div|\/tr)[^>]*>/i', "\n", (string) $message->getHtmlContent())), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $attachments = [];

        foreach (array_slice($message->getAllAttachmentParts(), 0, self::MAX_ATTACHMENTS) as $part) {
            $stream = $part->getBinaryContentStream();

            if ($stream === null) {
                continue;
            }

            $attachments[] = [
                'filename' => (string) ($part->getFilename() ?? ''),
                'mime' => strtolower($part->getContentType('application/octet-stream')),
                'contents' => $stream->getContents(),
            ];
        }

        $subject = $message->getSubject();

        return new ParsedMail(
            fromEmail: mb_strtolower(trim($email)),
            fromName: $name === null || trim($name) === '' ? null : mb_substr(trim($name), 0, 255),
            subject: $subject === null ? null : mb_substr(trim($subject), 0, 998),
            messageId: $message->getMessageId(),
            text: mb_substr(trim(str_replace("\r\n", "\n", $text)), 0, 200_000),
            attachments: $attachments,
            headers: $headers,
        );
    }
}
