<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Domain;

/**
 * How a message reached NONOX: through the cloud's receiving address
 * (Mailgun) or fetched from the company's own mailbox (IMAP).
 */
enum Channel: string
{
    case Mailgun = 'MAILGUN';
    case Imap = 'IMAP';
}
