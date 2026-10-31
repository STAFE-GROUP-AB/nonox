<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Support\Imap;

/**
 * Fetches raw messages from an IMAP mailbox for a self-hosted inbox. The
 * real client is WebklexMailboxClient; tests bind a fake, so they never
 * reach a mail server.
 */
interface MailboxClient
{
    /**
     * Messages with a UID above $afterUid, oldest first, at most $limit.
     *
     * @return list<FetchedMail>
     *
     * @throws MailboxUnavailable
     */
    public function fetch(MailboxSettings $settings, int $afterUid, int $limit): array;
}
