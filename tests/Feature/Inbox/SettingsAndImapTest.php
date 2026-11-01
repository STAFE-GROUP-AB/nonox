<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\Inbox\Actions\FetchImapMail;
use App\Modules\Inbox\Agents\DailyReportAgent;
use App\Modules\Inbox\Domain\Channel;
use App\Modules\Inbox\Domain\MessageStatus;
use App\Modules\Inbox\InboxModule;
use App\Modules\Inbox\Livewire\InboxSettingsForm;
use App\Modules\Inbox\Models\InboxMessage;
use App\Modules\Inbox\Support\Imap\FetchedMail;
use App\Modules\Inbox\Support\Imap\HostResolver;
use App\Modules\Inbox\Support\Imap\MailboxClient;
use App\Modules\Inbox\Support\Imap\MailboxSettings;
use App\Modules\Inbox\Support\Imap\MailboxUnavailable;
use App\Modules\Inbox\Support\InboxAddress;
use App\Modules\Inbox\Support\InboxChecks;
use App\Modules\Livewire\CompanyModulesTab;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Inbox;

/*
 * The module under Moduler, its settings (address, allowlist, templates,
 * IMAP) and fetching from an IMAP mailbox for self-hosted NONOX.
 */

beforeEach(function () {
    Storage::fake('local');
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-10-31 07:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    DailyReportAgent::fake();
    // No test depends on DNS: names resolve from this table.
    app()->instance(HostResolver::class, new class implements HostResolver
    {
        public function resolve(string $host): array
        {
            return [
                'imap.exempel.example' => ['93.184.216.34'],
                'intern.exempel.example' => ['10.0.0.8'],
                'redis.exempel.example' => ['93.184.216.34', '127.0.0.1'],
            ][$host] ?? [];
        }
    });
});

/** A mailbox that hands out the given messages and records what it was asked. */
function fakeMailbox(array $messages, bool $fails = false): object
{
    $fake = new class($messages, $fails) implements MailboxClient
    {
        /** @var list<array{settings: MailboxSettings, after: int}> */
        public array $calls = [];

        public function __construct(private array $messages, private bool $fails) {}

        public function fetch(MailboxSettings $settings, int $afterUid, int $limit): array
        {
            $this->calls[] = ['settings' => $settings, 'after' => $afterUid];

            if ($this->fails) {
                throw new MailboxUnavailable('Inloggningen misslyckades.');
            }

            return array_values(array_filter($this->messages, fn (FetchedMail $mail): bool => $mail->uid > $afterUid));
        }
    };

    app()->instance(MailboxClient::class, $fake);

    return $fake;
}

it('is a module that can be turned on and off under Moduler, with its settings shown while it is on', function () {
    Livewire::test(CompanyModulesTab::class, ['company' => $this->company])
        ->assertSee('Inkorg')
        ->call('ask', InboxModule::KEY, 'enable')
        ->call('confirm')
        ->assertDispatched('toast', message: 'Modulen Inkorg är påslagen.');

    $modules = app(ModuleManager::class);
    expect($modules->isEnabled(InboxModule::KEY, $this->company))->toBeTrue();

    $this->get(route('inbox.index'))->assertOk()->assertSee(InboxAddress::forSlug(InboxAddress::mailbox($this->company)->slug));
    $this->get(route('teams.show', ['company' => $this->company->id, 'flik' => 'moduler']))->assertOk()->assertSee('Inkorg');

    Livewire::test(CompanyModulesTab::class, ['company' => $this->company])
        ->call('ask', InboxModule::KEY, 'disable')
        ->assertSee('Adressen slutar ta emot e-post')
        ->set('typedName', 'Inkorg')
        ->call('confirm')
        ->assertDispatched('toast', message: 'Modulen Inkorg är avstängd. Inga uppgifter har tagits bort.');

    expect($modules->isEnabled(InboxModule::KEY, $this->company))->toBeFalse();
    $this->get('/inkorg')->assertNotFound();
});

it('gives each company an address that is hard to guess, and only the owner can replace it', function () {
    app(ModuleManager::class)->enable(InboxModule::KEY, $this->company, $this->owner);
    $slug = InboxAddress::mailbox($this->company)->slug;

    expect($slug)->toMatch('/^[a-z0-9-]+-[a-z0-9]{8}$/')
        ->and(InboxAddress::parse("{$slug}+faktura@in.nonox.app"))->toBe([$slug, 'faktura'])
        ->and(InboxAddress::parse("{$slug}+okand@in.nonox.app"))->toBe([$slug, null])
        ->and(InboxAddress::parse("{$slug}@annan.example"))->toBeNull();

    $admin = Bookkeeping::member($this->company, 'admin');
    $this->actingAs($admin);
    Livewire::test(InboxSettingsForm::class, ['company' => $this->company])->assertDontSee('Byt till en ny adress');

    $this->actingAs($this->owner);
    Livewire::test(InboxSettingsForm::class, ['company' => $this->company])
        ->call('regenerate')
        ->assertSee('Den gamla adressen slutar ta emot e-post direkt.')
        ->call('regenerate')
        ->assertDispatched('toast');

    expect(InboxAddress::mailbox($this->company)->slug)->not->toBe($slug)
        ->and(AuditLog::query()->where('action', 'inbox.address_changed')->exists())->toBeTrue();
});

it('keeps the allowlist with exact addresses and whole domains', function () {
    app(ModuleManager::class)->enable(InboxModule::KEY, $this->company, $this->owner);

    Livewire::test(InboxSettingsForm::class, ['company' => $this->company])
        ->set('newPattern', 'Kassa@Kassasystem.example')
        ->call('addSender')
        ->assertDispatched('toast', message: 'kassa@kassasystem.example får nu skicka till inkorgen.')
        ->set('newPattern', 'grossisten.example')
        ->call('addSender')
        ->set('newPattern', 'inte en adress')
        ->call('addSender')
        ->assertHasErrors('pattern')
        ->assertSee('@grossisten.example');

    expect(InboxChecks::isAllowed('faktura@grossisten.example'))->toBeTrue()
        ->and(InboxChecks::isAllowed('kassa@kassasystem.example'))->toBeTrue()
        ->and(InboxChecks::isAllowed('annan@kassasystem.example'))->toBeFalse();
});

it('fetches new mail from the company\'s IMAP mailbox once, with the password stored encrypted and masked in the audit log', function () {
    app(ModuleManager::class)->enable(InboxModule::KEY, $this->company, $this->owner);
    Inbox::allow($this->company, 'rapport@kassanova.example');
    $mailbox = fakeMailbox([new FetchedMail(41, Inbox::fixture('z-kassanova.eml')), new FetchedMail(42, Inbox::fixture('z-bongia.eml'))]);

    Livewire::test(InboxSettingsForm::class, ['company' => $this->company])
        ->set('imapEnabled', true)
        ->set('imapHost', 'imap.exempel.example')
        ->set('imapPort', '993')
        ->set('imapUsername', 'underlag@exempel.example')
        ->set('imapPassword', 'hemligt-losenord')
        ->call('saveImap')
        ->assertHasNoErrors()
        ->assertDispatched('toast');

    $settings = app(ModuleManager::class)->settings(InboxModule::KEY, $this->company);
    $audit = AuditLog::query()->where('action', 'module.settings_updated')->latest('id')->firstOrFail();

    expect($settings['imap_secret'])->not->toBe('hemligt-losenord')
        ->and(Crypt::decryptString($settings['imap_secret']))->toBe('hemligt-losenord')
        ->and(json_encode($audit->payload))->not->toContain('hemligt')
        ->and($audit->payload['after']['imap_secret'])->toBe('[ändrad]');

    $this->artisan('inbox:fetch', ['company' => $this->company->id])->assertSuccessful();
    $this->artisan('inbox:fetch')->assertSuccessful();

    $messages = InboxMessage::query()->orderBy('received_at')->get();
    expect($messages)->toHaveCount(2)
        ->and($messages->pluck('channel')->unique()->all())->toBe([Channel::Imap])
        ->and($messages->firstWhere('from_email', 'rapport@kassanova.example')->status)->toBe(MessageStatus::NeedsReview)
        ->and($messages->firstWhere('from_email', 'export@bongia.example')->status)->toBe(MessageStatus::Quarantine)
        ->and(InboxAddress::mailbox($this->company)->imap_last_uid)->toBe(42)
        ->and($mailbox->calls[0]['settings']->password)->toBe('hemligt-losenord')
        ->and($mailbox->calls[1]['after'])->toBe(42);
});

it('reads the authentication results of the company\'s own mail server from IMAP', function () {
    app(ModuleManager::class)->enable(InboxModule::KEY, $this->company, $this->owner);
    Inbox::allow($this->company, 'rapport@kassanova.example');
    app(ModuleManager::class)->updateSettings(InboxModule::KEY, $this->company, $this->owner, ['imap_enabled' => true, 'imap_host' => 'imap.exempel.example', 'imap_username' => 'u', 'imap_secret' => Crypt::encryptString('p')]);
    fakeMailbox([new FetchedMail(1, Inbox::mail('rapport@kassanova.example', 'Z', 'Text', [], Inbox::DMARC_FAIL))]);

    $this->artisan('inbox:fetch', ['company' => $this->company->id])->assertSuccessful();

    expect(InboxMessage::query()->sole()->hasQuarantineReason('AUTH_FAILED'))->toBeTrue();
});

it('shows a general reason when a fetch failed and keeps the details in the server log', function () {
    Log::spy();
    app(ModuleManager::class)->enable(InboxModule::KEY, $this->company, $this->owner);
    app(ModuleManager::class)->updateSettings(InboxModule::KEY, $this->company, $this->owner, ['imap_enabled' => true, 'imap_host' => 'imap.exempel.example', 'imap_username' => 'u', 'imap_secret' => Crypt::encryptString('p')]);
    fakeMailbox([], fails: true);

    $this->artisan('inbox:fetch')->assertSuccessful();

    // The text of a connection error tells what answers at an address, so it is never shown.
    expect(InboxAddress::mailbox($this->company)->imap_last_error)->toBe(FetchImapMail::ERROR_FAILED);
    Livewire::test(InboxSettingsForm::class, ['company' => $this->company])
        ->assertSee('Senaste hämtningen misslyckades.')
        ->assertSee('NONOX kunde inte ansluta eller logga in.')
        ->assertDontSee('Inloggningen misslyckades.');
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $context['error'] === 'Inloggningen misslyckades.' && $context['company_id'] === $this->company->id)->once();
});

it('does not show an error stored before the general reasons', function () {
    app(ModuleManager::class)->enable(InboxModule::KEY, $this->company, $this->owner);
    app(ModuleManager::class)->updateSettings(InboxModule::KEY, $this->company, $this->owner, ['imap_enabled' => true, 'imap_host' => 'imap.exempel.example', 'imap_username' => 'u', 'imap_secret' => Crypt::encryptString('p')]);
    InboxAddress::mailbox($this->company)->forceFill(['imap_last_error' => 'Connection refused (10.0.0.8:6379)'])->save();

    Livewire::test(InboxSettingsForm::class, ['company' => $this->company])
        ->assertSee('NONOX kunde inte ansluta eller logga in.')
        ->assertDontSee('10.0.0.8:6379');
});

it('accepts only a public IMAP server on port 143 or 993 with encryption', function (array $input, string $field, string $message) {
    app(ModuleManager::class)->enable(InboxModule::KEY, $this->company, $this->owner);

    Livewire::test(InboxSettingsForm::class, ['company' => $this->company])
        ->set('imapEnabled', true)
        ->set('imapHost', $input['host'] ?? 'imap.exempel.example')
        ->set('imapPort', $input['port'] ?? '993')
        ->set('imapEncryption', $input['encryption'] ?? 'ssl')
        ->set('imapUsername', 'underlag@exempel.example')
        ->set('imapPassword', 'hemligt-losenord')
        ->call('saveImap')
        ->assertHasErrors($field)
        ->assertSee($message);

    expect(app(ModuleManager::class)->settings(InboxModule::KEY, $this->company)['imap_enabled'] ?? false)->toBeFalse();
})->with([
    'a private address' => [['host' => 'intern.exempel.example'], 'imap_host', 'Servern har en intern adress.'],
    'a name with a loopback address among its addresses' => [['host' => 'redis.exempel.example'], 'imap_host', 'Servern har en intern adress.'],
    'the loopback address' => [['host' => '127.0.0.1'], 'imap_host', 'Servern har en intern adress.'],
    'the loopback address as one number' => [['host' => '2130706433'], 'imap_host', 'Skriv serverns namn'],
    'the loopback address in octal' => [['host' => '0177.0.0.1'], 'imap_host', 'Skriv serverns namn'],
    'a name that does not resolve' => [['host' => 'finns-inte.exempel.example'], 'imap_host', 'Servern kunde inte hittas.'],
    'another port' => [['port' => '6379'], 'imap_port', 'NONOX ansluter bara till port 993 (TLS) eller 143 (STARTTLS).'],
    'no encryption' => [['encryption' => 'none'], 'imap_encryption', 'E-post hämtas aldrig okrypterat.'],
]);

it('lets a self-hoster fetch from a server on their own network when the installation allows it', function () {
    config(['services.inbox.imap_allow_private' => true]);
    app(ModuleManager::class)->enable(InboxModule::KEY, $this->company, $this->owner);

    Livewire::test(InboxSettingsForm::class, ['company' => $this->company])
        ->set('imapEnabled', true)
        ->set('imapHost', 'intern.exempel.example')
        ->set('imapPort', '143')
        ->set('imapEncryption', 'starttls')
        ->set('imapUsername', 'underlag@exempel.example')
        ->set('imapPassword', 'hemligt-losenord')
        ->call('saveImap')
        ->assertHasNoErrors();

    expect(MailboxSettings::fromModuleSettings(app(ModuleManager::class)->settings(InboxModule::KEY, $this->company)))
        ->host->toBe('intern.exempel.example')
        ->port->toBe(143)
        ->encryption->toBe('starttls');
});

it('skips a message the server says is too large without taking it, and moves past it', function () {
    app(ModuleManager::class)->enable(InboxModule::KEY, $this->company, $this->owner);
    Inbox::allow($this->company, 'rapport@kassanova.example');
    app(ModuleManager::class)->updateSettings(InboxModule::KEY, $this->company, $this->owner, ['imap_enabled' => true, 'imap_host' => 'imap.exempel.example', 'imap_username' => 'u', 'imap_secret' => Crypt::encryptString('p')]);
    fakeMailbox([FetchedMail::tooLarge(7, 90_000_000), new FetchedMail(8, Inbox::fixture('z-kassanova.eml'))]);

    $this->artisan('inbox:fetch', ['company' => $this->company->id])->assertSuccessful();

    $dropped = AuditLog::query()->where('action', 'inbox.dropped')->sole();
    expect(InboxMessage::query()->count())->toBe(1)
        ->and(InboxAddress::mailbox($this->company)->imap_last_uid)->toBe(8)
        ->and($dropped->payload)->toMatchArray(['reason' => 'too_large', 'size' => 90_000_000, 'channel' => Channel::Imap->value, 'uid' => 7]);
});
