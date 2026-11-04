<?php

declare(strict_types=1);

namespace App\Modules\Crm\Calendar\Providers;

use Carbon\CarbonImmutable;

/**
 * Two-way mail sync with Google and Microsoft: the interface only, not
 * built (ADR-049, "Mail sync"). Stage 5 logs mail by BCC to the company's
 * inbox address; this is the later step where NONOX reads the user's own
 * mailbox, for CRM people only.
 *
 * The design it fixes:
 * - The same per-user connection as the calendar (CalendarConnection), with
 *   the mail scopes added by a new consent (Gmail: gmail.metadata or
 *   gmail.readonly, which Google calls "restricted" and which need a yearly
 *   security assessment; Graph: Mail.Read). A user connects mail on purpose;
 *   a calendar connection never reads mail.
 * - Only messages to or from an address that is a CRM person or account in
 *   the company are fetched, and only headers until the user opens one;
 *   everything else is never stored. People marked Kontakta inte and
 *   addresses on the suppression list are skipped, as in stage 5.
 * - Sending goes through the user's own mailbox (Gmail send, Graph sendMail)
 *   so the message is in the user's Sent folder and replies reach the user.
 * - Cursor per connection, as for the calendar (Gmail historyId, Graph
 *   messages delta).
 */
interface MailSyncProvider
{
    /** @return list<string> the extra scopes mail needs */
    public function mailScopes(): array;

    /**
     * Headers of messages to or from these addresses since the cursor.
     *
     * @param  list<string>  $addresses
     * @return array{messages: list<array{id: string, from: string, to: list<string>, subject: string, sent_at: CarbonImmutable}>, cursor: ?string}
     */
    public function messages(string $accessToken, array $addresses, ?string $cursor): array;

    /** One message's plain-text body, fetched only when a user opens it. */
    public function body(string $accessToken, string $messageId): string;

    /**
     * Sends from the user's own mailbox; returns the message id.
     *
     * @param  list<string>  $to
     */
    public function send(string $accessToken, array $to, string $subject, string $text): string;
}
