<?php

declare(strict_types=1);

namespace App\Modules\Crm\Support;

use Carbon\CarbonImmutable;

/**
 * What the CRM reads from a message sent to the +crm address (ADR-048):
 * the parties, the subject, the date, the plain text and the names of the
 * attachments. For a forwarded message, $forwarded is the original as far
 * as it could be read (an attached message/rfc822, or the header block of
 * an inline forward).
 */
final readonly class LoggedMail
{
    /**
     * @param  list<string>  $to  lower-case addresses
     * @param  list<string>  $cc  lower-case addresses
     * @param  list<string>  $attachmentNames
     */
    public function __construct(
        public string $from,
        public ?string $fromName,
        public array $to,
        public array $cc,
        public string $subject,
        public ?CarbonImmutable $date,
        public string $text,
        public array $attachmentNames,
        public ?string $messageId,
        public ?self $forwarded = null,
    ) {}

    /** @return list<string> every address on the message, lower case, once each */
    public function addresses(): array
    {
        return array_values(array_unique(array_filter([$this->from, ...$this->to, ...$this->cc])));
    }
}
