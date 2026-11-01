<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Support\Imap;

/**
 * A message from the IMAP mailbox, or, when the server said it is larger
 * than the inbox takes, only its UID and size: it is never downloaded.
 */
final readonly class FetchedMail
{
    public function __construct(public int $uid, public string $raw, public ?int $skippedSize = null) {}

    public static function tooLarge(int $uid, int $size): self
    {
        return new self($uid, '', $size);
    }

    public function isTooLarge(): bool
    {
        return $this->skippedSize !== null;
    }
}
