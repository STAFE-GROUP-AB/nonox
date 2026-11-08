<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Domain;

/**
 * How a message reached NONOX: through the cloud's receiving address
 * (Mailgun), fetched from the company's own mailbox (IMAP), or as an
 * e-invoice over the Peppol network through the installation's access
 * point (ADR-050). A Peppol message has no e-mail: its sender is the
 * supplier's Peppol participant id and its original is the UBL file.
 */
enum Channel: string
{
    case Mailgun = 'MAILGUN';
    case Imap = 'IMAP';
    case Peppol = 'PEPPOL';
    // A receipt photographed in NONOX by a signed-in person (Inkorg → Fota kvitto, ADR-072): the original is the photo itself.
    case Upload = 'UPLOAD';
}
