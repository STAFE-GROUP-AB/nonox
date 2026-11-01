<?php

declare(strict_types=1);

use App\Modules\Inbox\Support\Imap\FetchedMail;
use App\Modules\Inbox\Support\Imap\HostResolver;
use App\Modules\Inbox\Support\Imap\ImapHost;
use App\Modules\Inbox\Support\Imap\MailboxSettings;
use App\Modules\Inbox\Support\Imap\UnsafeImapHost;
use App\Modules\Inbox\Support\Imap\WebklexMailboxClient;
use Illuminate\Support\Facades\Crypt;
use Webklex\PHPIMAP\Connection\Protocols\ProtocolInterface;
use Webklex\PHPIMAP\Connection\Protocols\Response;

/*
 * The IMAP server a company enters is reached from inside the hosting
 * network, so NONOX connects only to public addresses on the IMAP ports,
 * with TLS, and never downloads a message larger than it takes
 * (security review 2026-11-01).
 */

/** A resolver that answers from a table and records what it was asked. */
function fakeResolver(array $table): HostResolver
{
    return new class($table) implements HostResolver
    {
        /** @var list<string> */
        public array $asked = [];

        public function __construct(private array $table) {}

        public function resolve(string $host): array
        {
            $this->asked[] = $host;

            return $this->table[$host] ?? [];
        }
    };
}

function imapRefusal(ImapHost $hosts, string $host): ?string
{
    try {
        $hosts->vet($host);

        return null;
    } catch (UnsafeImapHost $exception) {
        return $exception->reason;
    }
}

describe('vetting the IMAP host', function () {
    it('connects to the address a public name resolves to', function () {
        $hosts = new ImapHost(fakeResolver(['imap.exempel.se' => ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946']]));

        expect($hosts->vet('imap.exempel.se'))->toBe('93.184.216.34')
            ->and($hosts->vet('IMAP.Exempel.SE.'))->toBe('93.184.216.34')
            ->and($hosts->vet('8.8.8.8'))->toBe('8.8.8.8');
    });

    it('refuses names that resolve to loopback, private, link-local, shared, reserved or multicast IPv4', function (string $address) {
        $hosts = new ImapHost(fakeResolver(['intern.exempel.se' => [$address]]));

        expect(imapRefusal($hosts, 'intern.exempel.se'))->toBe(UnsafeImapHost::INTERNAL);
    })->with(['127.0.0.1', '127.8.9.10', '0.0.0.0', '10.0.0.5', '172.16.3.4', '172.31.255.255', '192.168.1.1', '169.254.169.254', '100.64.0.1', '192.0.0.8', '192.0.2.10', '198.18.0.1', '198.51.100.1', '203.0.113.9', '224.0.0.251', '239.255.255.250', '240.0.0.1', '255.255.255.255']);

    it('refuses IPv6 loopback, unique-local, link-local, multicast and addresses that carry an IPv4 address', function (string $address) {
        $hosts = new ImapHost(fakeResolver(['intern.exempel.se' => [$address]]));

        expect(imapRefusal($hosts, 'intern.exempel.se'))->toBe(UnsafeImapHost::INTERNAL)
            ->and(ImapHost::isPublic($address))->toBeFalse();
    })->with(['::1', '::', 'fc00::1', 'fd12:3456:789a::1', 'fe80::1', 'ff02::1', 'ff0e::1', '::ffff:127.0.0.1', '::ffff:8.8.8.8', '::127.0.0.1', '64:ff9b::7f00:1', '64:ff9b:1::a00:1', '2002:7f00:1::1', '2002:808:808::1', '2001:db8::1', '2001::1', '100::1']);

    it('accepts public IPv6', function () {
        expect(ImapHost::isPublic('2606:4700:4700::1111'))->toBeTrue()
            ->and(ImapHost::isPublic('2a00:1450:4010:c05::6c'))->toBeTrue();
    });

    it('refuses a name when any of its addresses is internal, so a mixed answer cannot slip through', function () {
        $hosts = new ImapHost(fakeResolver(['blandad.exempel.se' => ['93.184.216.34', '10.0.0.1']]));

        expect(imapRefusal($hosts, 'blandad.exempel.se'))->toBe(UnsafeImapHost::INTERNAL);
    });

    it('refuses numbers in disguise without asking the resolver', function (string $host) {
        $resolver = fakeResolver([]);

        expect(imapRefusal(new ImapHost($resolver), $host))->toBe(UnsafeImapHost::INVALID)
            ->and(ImapHost::isWellFormed($host))->toBeFalse()
            ->and($resolver->asked)->toBe([]);
    })->with(['2130706433', '0177.0.0.1', '0x7f.0.0.1', '0x7f000001', '127.1', '127.0.0.01', '017700000001', '10.1', 'imap.exempel.123', 'imap_server.se', 'imap..exempel.se', '-imap.exempel.se', 'imap exempel.se', '[::1]', '::1', '']);

    it('refuses the loopback IPv4 written plainly, and localhost', function () {
        $hosts = new ImapHost(fakeResolver(['localhost' => ['127.0.0.1', '::1']]));

        expect(imapRefusal($hosts, '127.0.0.1'))->toBe(UnsafeImapHost::INTERNAL)
            ->and(imapRefusal($hosts, 'localhost'))->toBe(UnsafeImapHost::INTERNAL);
    });

    it('refuses a name that does not resolve', function () {
        expect(imapRefusal(new ImapHost(fakeResolver([])), 'finns-inte.exempel.se'))->toBe(UnsafeImapHost::UNRESOLVABLE);
    });

    it('lets a self-hoster allow a server on their own network', function () {
        config(['services.inbox.imap_allow_private' => true]);
        $hosts = new ImapHost(fakeResolver(['mail.lan' => ['192.168.1.20']]));

        expect($hosts->vet('mail.lan'))->toBe('192.168.1.20')
            // A disguised number is still not a server name.
            ->and(imapRefusal($hosts, '2130706433'))->toBe(UnsafeImapHost::INVALID);
    });

    it('writes an IPv6 address in brackets for the socket', function () {
        expect(ImapHost::socketHost('2606:4700::1111'))->toBe('[2606:4700::1111]')
            ->and(ImapHost::socketHost('93.184.216.34'))->toBe('93.184.216.34');
    });
});

function imapSettings(string $host = 'imap.exempel.se', int $port = 993, string $encryption = 'ssl'): MailboxSettings
{
    return new MailboxSettings($host, $port, $encryption, 'underlag@exempel.se', 'hemligt', 'INBOX');
}

describe('the IMAP client', function () {
    it('vets the host again right before connecting and never connects to an internal address', function () {
        $client = new WebklexMailboxClient(new ImapHost(fakeResolver(['imap.exempel.se' => ['10.0.0.8']])));

        expect(fn () => $client->fetch(imapSettings(), 0, 10))->toThrow(UnsafeImapHost::class);
    });

    it('refuses other ports than 143 and 993', function () {
        $client = new WebklexMailboxClient(new ImapHost(fakeResolver(['imap.exempel.se' => ['93.184.216.34']])));

        try {
            $client->fetch(imapSettings(port: 6379), 0, 10);
            $reason = null;
        } catch (UnsafeImapHost $exception) {
            $reason = $exception->reason;
        }

        expect($reason)->toBe(UnsafeImapHost::PORT);
    });

    it('reads settings saved before the hardening with TLS', function () {
        $saved = ['imap_enabled' => true, 'imap_host' => 'imap.exempel.se', 'imap_username' => 'u', 'imap_secret' => Crypt::encryptString('p')];

        expect(MailboxSettings::fromModuleSettings([...$saved, 'imap_encryption' => 'none'])?->encryption)->toBe('ssl')
            ->and(MailboxSettings::fromModuleSettings([...$saved, 'imap_encryption' => 'tls'])?->encryption)->toBe('ssl')
            ->and(MailboxSettings::fromModuleSettings([...$saved, 'imap_encryption' => 'starttls'])?->encryption)->toBe('starttls');
    });

    it('asks for the sizes first and never downloads a message larger than the inbox takes', function () {
        config(['services.inbox.max_message_kb' => 1]);
        $result = fn (mixed $data): Response => (new Response(1))->setResult($data);
        $connection = Mockery::mock(ProtocolInterface::class);
        $connection->shouldReceive('examineFolder')->once()->with('INBOX')->andReturn($result(['exists' => 4, 'uidnext' => 13]));
        // UID n:* also answers with the last message when n is past it, so 7 must be filtered out.
        $connection->shouldReceive('search')->once()->with(['UID 8:*'])->andReturn($result(['9', '7', '10', '12']));
        $connection->shouldReceive('sizes')->once()->with([9, 10])->andReturn($result(['9' => '600', '10' => '5000000']));
        $connection->shouldReceive('headers')->once()->with([9])->andReturn($result(['9' => "From: a@exempel.se\r\nSubject: Z\r\n\r\n"]));
        $connection->shouldReceive('content')->once()->with([9])->andReturn($result(['9' => 'Text']));
        $connection->shouldNotReceive('headers')->with([10]);
        $connection->shouldNotReceive('content')->with([10]);

        $fetched = (new WebklexMailboxClient(new ImapHost(fakeResolver([]))))->collect($connection, 'INBOX', 7, 2);

        expect($fetched)->toHaveCount(2)
            ->and($fetched[0])->toEqual(new FetchedMail(9, "From: a@exempel.se\r\nSubject: Z\r\n\r\nText"))
            ->and($fetched[1]->uid)->toBe(10)
            ->and($fetched[1]->isTooLarge())->toBeTrue()
            ->and($fetched[1]->skippedSize)->toBe(5_000_000)
            ->and($fetched[1]->raw)->toBe('');
    });
});
