<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Support;

/**
 * What NONOX needs from a raw message: who sent it, its text and its
 * attachments with their bytes exactly as sent (decoded from the transfer
 * encoding only), and the headers the security checks read.
 */
final readonly class ParsedMail
{
    /**
     * @param  list<array{filename: string, mime: string, contents: string}>  $attachments
     * @param  array<string, list<string>>  $headers  lower-case name => every value, topmost first
     */
    public function __construct(
        public string $fromEmail,
        public ?string $fromName,
        public ?string $subject,
        public ?string $messageId,
        public string $text,
        public array $attachments,
        public array $headers,
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    /** @return list<string> */
    public function headerAll(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }

    public function fromDomain(): string
    {
        $at = strrpos($this->fromEmail, '@');

        return $at === false ? '' : substr($this->fromEmail, $at + 1);
    }
}
