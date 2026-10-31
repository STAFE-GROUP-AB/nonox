<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Support\Imap;

use Throwable;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Message;

/**
 * IMAP through webklex/php-imap (MIT), a pure-PHP client: PHP's imap
 * extension is no longer part of PHP 8.4 (ADR-023). Messages are read
 * without being marked as seen; NONOX remembers the last UID it took.
 */
final class WebklexMailboxClient implements MailboxClient
{
    public function fetch(MailboxSettings $settings, int $afterUid, int $limit): array
    {
        try {
            $client = (new ClientManager)->make([
                'host' => $settings->host,
                'port' => $settings->port,
                'encryption' => $settings->encryption === 'none' ? false : $settings->encryption,
                'validate_cert' => true,
                'username' => $settings->username,
                'password' => $settings->password,
                'protocol' => 'imap',
                'timeout' => 30,
            ]);
            $client->connect();
            $folder = $client->getFolderByPath($settings->folder);

            if ($folder === null) {
                throw new MailboxUnavailable("The folder {$settings->folder} does not exist.");
            }

            $messages = $folder->query()->leaveUnread()->setFetchOrderAsc()->getByUidGreater($afterUid);
            $fetched = [];

            foreach ($messages as $message) {
                if (! $message instanceof Message || count($fetched) >= $limit) {
                    continue;
                }

                $uid = (int) $message->get('uid');

                if ($uid <= $afterUid) {
                    continue;
                }

                $fetched[] = new FetchedMail($uid, rtrim((string) $message->getHeader()?->raw)."\r\n\r\n".$message->getRawBody());
            }

            $client->disconnect();
            usort($fetched, fn (FetchedMail $a, FetchedMail $b): int => $a->uid <=> $b->uid);

            return $fetched;
        } catch (MailboxUnavailable $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new MailboxUnavailable($exception->getMessage(), previous: $exception);
        }
    }
}
