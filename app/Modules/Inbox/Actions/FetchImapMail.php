<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Actions;

use App\Models\Company;
use App\Modules\Inbox\Domain\Channel;
use App\Modules\Inbox\InboxModule;
use App\Modules\Inbox\Support\Imap\MailboxClient;
use App\Modules\Inbox\Support\Imap\MailboxSettings;
use App\Modules\Inbox\Support\Imap\MailboxUnavailable;
use App\Modules\Inbox\Support\InboxAddress;
use App\Modules\Inbox\Support\InboxQuotaExceeded;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;

/**
 * Fetches new mail from the company's own IMAP mailbox (self-hosted
 * NONOX, ADR-023) and takes each message into the inbox like mail to the
 * cloud address. The last UID taken is remembered, so a message is never
 * taken twice and nothing on the mail server is changed.
 */
class FetchImapMail
{
    public const int BATCH = 50;

    public function __construct(
        private readonly MailboxClient $client,
        private readonly AcceptInboundMail $accept,
        private readonly ModuleManager $modules,
    ) {}

    /**
     * @return array{fetched: int, error: ?string}
     */
    public function __invoke(Company $company): array
    {
        $settings = MailboxSettings::fromModuleSettings($this->modules->settings(InboxModule::KEY, $company));

        if ($settings === null || ! $this->modules->isEnabled(InboxModule::KEY, $company)) {
            return ['fetched' => 0, 'error' => null];
        }

        return CurrentCompany::run($company, function () use ($company, $settings): array {
            $mailbox = InboxAddress::mailbox($company);
            $fetched = 0;
            $error = null;

            try {
                foreach ($this->client->fetch($settings, $mailbox->imap_last_uid, self::BATCH) as $mail) {
                    ($this->accept)($company, $mail->raw, Channel::Imap, $settings->username, null);
                    $mailbox->imap_last_uid = $mail->uid;
                    $fetched++;
                }
            } catch (MailboxUnavailable $exception) {
                $error = mb_substr($exception->getMessage(), 0, 500);
            } catch (InboxQuotaExceeded) {
                // The rest is fetched on a later run.
            }

            $mailbox->forceFill(['imap_last_uid' => $mailbox->imap_last_uid, 'imap_last_fetched_at' => now(), 'imap_last_error' => $error])->save();

            return ['fetched' => $fetched, 'error' => $error];
        });
    }
}
