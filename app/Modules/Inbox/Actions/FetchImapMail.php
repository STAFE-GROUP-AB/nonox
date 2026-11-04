<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Actions;

use App\Models\Company;
use App\Modules\Crm\Actions\LogInboundEmail;
use App\Modules\Crm\Support\CrmCopy;
use App\Modules\Inbox\Domain\Channel;
use App\Modules\Inbox\InboxModule;
use App\Modules\Inbox\Support\Imap\MailboxClient;
use App\Modules\Inbox\Support\Imap\MailboxSettings;
use App\Modules\Inbox\Support\Imap\MailboxUnavailable;
use App\Modules\Inbox\Support\Imap\UnsafeImapHost;
use App\Modules\Inbox\Support\InboxAddress;
use App\Modules\Inbox\Support\InboxQuotaExceeded;
use App\Modules\ModuleManager;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Log;

/**
 * Fetches new mail from the company's own IMAP mailbox (self-hosted
 * NONOX, ADR-023) and takes each message into the inbox like mail to the
 * cloud address. The last UID taken is remembered, so a message is never
 * taken twice and nothing on the mail server is changed.
 *
 * Why a fetch failed is logged on the server and never shown: the text of
 * a connection error tells what answers at an address, which would make the
 * settings a way to probe the hosting network. The settings show one of
 * the general reasons below.
 */
class FetchImapMail
{
    public const int BATCH = 50;

    /** The server's name was refused (ImapHost). */
    public const string ERROR_REFUSED = 'refused';

    /** Connecting, logging in or reading failed. */
    public const string ERROR_FAILED = 'failed';

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
                    if ($mail->isTooLarge()) {
                        Audit::record('inbox.dropped', null, ['reason' => 'too_large', 'size' => $mail->skippedSize, 'channel' => Channel::Imap->value, 'uid' => $mail->uid], $company);
                    } elseif (CrmCopy::isFor($mail->raw, $settings->username)) {
                        // Sent to the mailbox's +crm address: the CRM's e-mail log (ADR-048), not the inbox.
                        app(LogInboundEmail::class)($company, $mail->raw, Channel::Imap);
                        $fetched++;
                    } else {
                        ($this->accept)($company, $mail->raw, Channel::Imap, $settings->username, null);
                        $fetched++;
                    }

                    $mailbox->imap_last_uid = $mail->uid;
                }
            } catch (MailboxUnavailable $exception) {
                $error = $exception instanceof UnsafeImapHost ? self::ERROR_REFUSED : self::ERROR_FAILED;
                Log::warning('Inbox IMAP fetch failed', ['company_id' => $company->id, 'host' => $settings->host, 'port' => $settings->port, 'error' => $exception->getMessage(), 'cause' => $exception->getPrevious()?->getMessage()]);
            } catch (InboxQuotaExceeded) {
                // The rest is fetched on a later run.
            }

            $mailbox->forceFill(['imap_last_uid' => $mailbox->imap_last_uid, 'imap_last_fetched_at' => now(), 'imap_last_error' => $error])->save();

            return ['fetched' => $fetched, 'error' => $error];
        });
    }

    /** The general reason shown in the settings for a stored error. */
    public static function explain(string $error): string
    {
        return $error === self::ERROR_REFUSED
            ? __('NONOX ansluter inte till servern: den kan inte hittas, har en intern adress eller en port som inte används. Spara inställningarna igen för att se vad som är fel.')
            : __('NONOX kunde inte ansluta eller logga in. Kontrollera server, port, kryptering, användarnamn och lösenord.');
    }
}
