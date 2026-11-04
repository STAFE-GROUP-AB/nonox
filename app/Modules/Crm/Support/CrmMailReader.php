<?php

declare(strict_types=1);

namespace App\Modules\Crm\Support;

use Carbon\CarbonImmutable;
use Throwable;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\MailMimeParser;

/**
 * Reads a message sent to the company's +crm address (ADR-048) with the
 * MIME parser the inbox already uses (zbateson/mail-mime-parser, ADR-023).
 * HTML is never rendered: only its text is read.
 *
 * A forwarded message is recognised two ways: an attached message/rfc822
 * (Outlook's "forward as attachment", Apple Mail), or an inline forward
 * whose header block follows one of the usual separators in Swedish or
 * English ("---------- Forwarded message ---------", "Vidarebefordrat
 * meddelande", "Begin forwarded message:", "-----Ursprungligt
 * meddelande-----", Outlook's line of underscores followed by "Från:").
 */
final class CrmMailReader
{
    /** Separators that start an inline forward's header block. */
    private const string FORWARD_MARKER = '/^\s*(?:-{2,}\s*(?:forwarded message|vidarebefordrat meddelande|original message|ursprungligt meddelande|videresendt melding)\s*-{2,}|begin forwarded message:|vidarebefordrat brev:|vidarebefordrat meddelande:|_{10,})\s*$/imu';

    /** The header lines of an inline forward, Swedish and English. */
    private const array HEADER_NAMES = [
        'from' => ['from', 'från', 'fra'],
        'to' => ['to', 'till', 'til'],
        'cc' => ['cc', 'kopia', 'kopia till'],
        'subject' => ['subject', 'ämne', 'emne', 'rubrik'],
        'date' => ['date', 'datum', 'sent', 'skickat', 'skickades', 'dato'],
    ];

    public function read(string $raw): LoggedMail
    {
        $message = (new MailMimeParser)->parse($raw, false);
        $mail = $this->fromMessage($message);

        foreach ($message->getAllAttachmentParts() as $part) {
            if (strtolower($part->getContentType('')) !== 'message/rfc822') {
                continue;
            }

            $contents = $part->getContentStream()?->getContents() ?? $part->getBinaryContentStream()?->getContents();

            if (is_string($contents) && $contents !== '') {
                $original = $this->fromMessage((new MailMimeParser)->parse($contents, false));

                return $this->with($mail, $original);
            }
        }

        $inline = $this->inlineForward($mail->text);

        return $inline === null ? $mail : $this->with($mail, $inline);
    }

    /** Plain text for the timeline: no quoted lines, no signature, whitespace collapsed, at most $length characters. */
    public static function excerpt(string $text, int $length = 500): string
    {
        $lines = [];

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (rtrim($line) === '--') {
                break;
            }

            if (str_starts_with(ltrim($line), '>')) {
                continue;
            }

            $lines[] = $line;
        }

        $clean = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', implode("\n", $lines));
        $clean = trim((string) preg_replace('/\s+/u', ' ', $clean));

        return mb_strlen($clean) > $length ? rtrim(mb_substr($clean, 0, $length - 1)).'…' : $clean;
    }

    private function with(LoggedMail $outer, LoggedMail $original): LoggedMail
    {
        return new LoggedMail($outer->from, $outer->fromName, $outer->to, $outer->cc, $outer->subject, $outer->date, $outer->text, $outer->attachmentNames, $outer->messageId, $original);
    }

    private function fromMessage(IMessage $message): LoggedMail
    {
        $from = $message->getHeader('From');
        $email = '';
        $name = null;

        if ($from instanceof AddressHeader && $from->getAddresses() !== []) {
            $email = mb_strtolower(trim((string) $from->getEmail()));
            $name = $from->getPersonName();
        }

        $text = (string) $message->getTextContent();

        if (trim($text) === '' && $message->getHtmlContent() !== null) {
            $text = html_entity_decode(strip_tags((string) preg_replace('/<(br|\/p|\/div|\/tr)[^>]*>/i', "\n", (string) $message->getHtmlContent())), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $names = [];

        foreach ($message->getAllAttachmentParts() as $part) {
            if (strtolower($part->getContentType('')) === 'message/rfc822') {
                continue;
            }

            $names[] = mb_substr((string) ($part->getFilename() ?? ''), 0, 255);
        }

        $date = null;

        try {
            $header = $message->getHeaderValue('Date');
            $date = $header === null ? null : CarbonImmutable::parse($header);
        } catch (Throwable) {
            $date = null;
        }

        return new LoggedMail(
            from: $email,
            fromName: $name === null || trim($name) === '' ? null : mb_substr(trim($name), 0, 255),
            to: $this->addresses($message, 'To'),
            cc: $this->addresses($message, 'Cc'),
            subject: mb_substr(trim((string) $message->getSubject()), 0, 255),
            date: $date,
            text: mb_substr(trim(str_replace("\r\n", "\n", $text)), 0, 200_000),
            attachmentNames: array_values(array_filter($names)),
            messageId: $message->getMessageId(),
        );
    }

    /** @return list<string> */
    private function addresses(IMessage $message, string $name): array
    {
        $header = $message->getHeader($name);

        if (! $header instanceof AddressHeader) {
            return [];
        }

        $addresses = [];

        foreach ($header->getAddresses() as $address) {
            $email = mb_strtolower(trim($address->getEmail()));

            if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $addresses[] = $email;
            }
        }

        return array_values(array_unique($addresses));
    }

    /** The original in an inline forward, from the header block after the separator. */
    private function inlineForward(string $text): ?LoggedMail
    {
        if (preg_match(self::FORWARD_MARKER, $text, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $rest = substr($text, $match[0][1] + strlen($match[0][0]));
        $lines = preg_split('/\R/u', ltrim($rest, "\r\n")) ?: [];
        $fields = [];
        $consumed = 0;

        foreach ($lines as $index => $line) {
            $line = trim((string) preg_replace('/^[*>\s]+|\*+/u', '', $line));

            if ($line === '' && $fields !== []) {
                $consumed = $index + 1;

                break;
            }

            if (preg_match('/^([\p{L} ]{2,20}):\s*(.*)$/u', $line, $header) !== 1) {
                if ($fields !== []) {
                    $consumed = $index;

                    break;
                }

                continue;
            }

            $label = mb_strtolower(trim($header[1]));

            foreach (self::HEADER_NAMES as $field => $names) {
                if (in_array($label, $names, true) && ! isset($fields[$field])) {
                    $fields[$field] = trim($header[2]);
                }
            }

            $consumed = $index + 1;
        }

        $from = self::emails($fields['from'] ?? '');

        if ($from === []) {
            return null;
        }

        $date = null;

        try {
            $date = isset($fields['date']) && $fields['date'] !== '' ? CarbonImmutable::parse($fields['date']) : null;
        } catch (Throwable) {
            $date = null;
        }

        $name = trim((string) preg_replace('/<[^>]*>|\[mailto:[^\]]*\]|"/u', '', $fields['from'] ?? ''));

        return new LoggedMail(
            from: $from[0],
            fromName: $name === '' || $name === $from[0] ? null : mb_substr($name, 0, 255),
            to: self::emails($fields['to'] ?? ''),
            cc: self::emails($fields['cc'] ?? ''),
            subject: mb_substr($fields['subject'] ?? '', 0, 255),
            date: $date,
            text: trim(implode("\n", array_slice($lines, $consumed))),
            attachmentNames: [],
            messageId: null,
        );
    }

    /** @return list<string> */
    private static function emails(string $value): array
    {
        preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', $value, $matches);

        return array_values(array_unique(array_map(fn (string $email): string => mb_strtolower($email), $matches[0])));
    }
}
