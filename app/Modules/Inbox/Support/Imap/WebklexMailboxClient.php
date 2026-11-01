<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Support\Imap;

use App\Modules\Inbox\Actions\AcceptInboundMail;
use Throwable;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Connection\Protocols\ProtocolInterface;

/**
 * IMAP through webklex/php-imap (MIT), a pure-PHP client: PHP's imap
 * extension is no longer part of PHP 8.4 (ADR-023).
 *
 * Right before connecting the server's name is vetted again (ImapHost)
 * and the client connects to the vetted address, with the name kept for
 * the certificate check and SNI, so a name that resolves to something else
 * after it was saved cannot reach an internal service. Only TLS or
 * STARTTLS with a valid certificate is used.
 *
 * The folder is opened read-only (EXAMINE), so nothing on the server is
 * changed and no message is marked as seen; NONOX remembers the last UID it
 * took. The size of each message is asked first, and one larger than the
 * inbox takes is never downloaded.
 */
final class WebklexMailboxClient implements MailboxClient
{
    public function __construct(private readonly ImapHost $hosts) {}

    public function fetch(MailboxSettings $settings, int $afterUid, int $limit): array
    {
        if (! in_array($settings->port, ImapHost::PORTS, true)) {
            throw new UnsafeImapHost(UnsafeImapHost::PORT, $settings->host);
        }

        $address = $this->hosts->vet($settings->host);

        try {
            $client = (new ClientManager)->make([
                'host' => ImapHost::socketHost($address),
                'port' => $settings->port,
                'encryption' => $settings->encryption === 'starttls' ? 'starttls' : 'ssl',
                'validate_cert' => true,
                'ssl_options' => ['peer_name' => $settings->host, 'SNI_enabled' => true],
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

            $fetched = $this->collect($client->getConnection(), $folder->path, $afterUid, $limit);
            $client->disconnect();

            return $fetched;
        } catch (MailboxUnavailable $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new MailboxUnavailable($exception->getMessage(), previous: $exception);
        }
    }

    /**
     * Reads the messages after $afterUid from an open connection, oldest
     * first. Public for the tests, which hand it a fake connection.
     *
     * @return list<FetchedMail>
     */
    public function collect(ProtocolInterface $connection, string $folder, int $afterUid, int $limit): array
    {
        $connection->examineFolder($folder)->validatedData();
        $found = (array) $connection->search(['UID '.($afterUid + 1).':*'])->validatedData();
        $uids = array_values(array_unique(array_filter(array_map(fn (mixed $uid): int => (int) $uid, $found), fn (int $uid): bool => $uid > $afterUid)));
        sort($uids);
        $uids = array_slice($uids, 0, $limit);

        if ($uids === []) {
            return [];
        }

        $sizes = (array) $connection->sizes($uids)->validatedData();
        $max = AcceptInboundMail::maxBytes();
        $fetched = [];

        foreach ($uids as $uid) {
            $size = (int) ($sizes[$uid] ?? 0);

            if ($size > $max) {
                $fetched[] = FetchedMail::tooLarge($uid, $size);

                continue;
            }

            $header = (array) $connection->headers([$uid])->validatedData();
            $body = (array) $connection->content([$uid])->validatedData();
            $fetched[] = new FetchedMail($uid, rtrim((string) ($header[$uid] ?? ''))."\r\n\r\n".(string) ($body[$uid] ?? ''));
        }

        return $fetched;
    }
}
