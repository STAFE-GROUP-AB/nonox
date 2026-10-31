<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Support\Imap;

final readonly class FetchedMail
{
    public function __construct(public int $uid, public string $raw) {}
}
