<?php

declare(strict_types=1);

use App\Actions\Payables\SaveSupplier;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inbox\Actions\ClaimUnassignedMessage;
use App\Modules\Inbox\Actions\InboxReview;
use App\Modules\Inbox\Agents\DailyReportAgent;
use App\Modules\Inbox\Agents\InboxClassifierAgent;
use App\Modules\Inbox\Domain\MessageStatus;
use App\Modules\Inbox\InboxModule;
use App\Modules\Inbox\Models\AllowedSender;
use App\Modules\Inbox\Models\InboxMessage;
use App\Modules\Inbox\Models\UnassignedMessage;
use App\Modules\Inbox\Support\DangerousContent;
use App\Modules\Inbox\Support\InboxAddress;
use App\Modules\Inbox\Support\InboxQuotaExceeded;
use App\Modules\Inbox\Support\VirusScanner;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use App\Support\WorkQuota;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;
use Tests\Support\Inbox;

/*
 * Receiving (ADR-023): the Mailgun webhook's signature and replay checks,
 * routing to a company by address or, on the shared address, by the
 * sender's allowlist, and the security that runs before any reading.
 */

const SIGNING_KEY = 'test-signing-key-0123456789';

beforeEach(function () {
    Storage::fake('local');
    Http::preventStrayRequests();
    config(['services.mailgun.webhook_signing_key' => SIGNING_KEY, 'services.inbox.domain' => 'in.nonox.app', 'services.inbox.shared_local' => 'mejl']);
    $this->travelTo(CarbonImmutable::parse('2026-10-31 07:00:00'));
    [$this->owner, $this->company] = Inbox::company();
    $this->address = InboxAddress::forSlug(InboxAddress::mailbox($this->company)->slug);
    InboxClassifierAgent::fake();
    DailyReportAgent::fake();
});

/** @return array<string, string> a signed Mailgun request carrying the message */
function mailgun(string $raw, string $recipient, ?string $token = null, ?int $timestamp = null, array $extra = []): array
{
    $timestamp ??= now()->getTimestamp();
    $token ??= bin2hex(random_bytes(25));

    return [
        'timestamp' => (string) $timestamp,
        'token' => $token,
        'signature' => hash_hmac('sha256', $timestamp.$token, SIGNING_KEY),
        'recipient' => $recipient,
        'sender' => 'rapport@kassanova.example',
        'body-mime' => $raw,
        ...$extra,
    ];
}

function messagesOf(Company $company): Collection
{
    return CurrentCompany::run($company, fn () => InboxMessage::query()->get());
}

describe('the Mailgun webhook', function () {
    it('takes a signed message into the company the address belongs to', function () {
        Inbox::allow($this->company, 'rapport@kassanova.example');

        $this->post('/webhooks/inkorg/mailgun/mime', mailgun(Inbox::fixture('z-kassanova.eml'), str_replace('@', '+kassa@', $this->address)))->assertOk();

        $message = messagesOf($this->company)->sole();
        expect($message->alias)->toBe('kassa')
            ->and($message->channel->value)->toBe('MAILGUN')
            ->and($message->auth['dmarc'])->toBe('pass')
            ->and(Storage::disk('local')->get($message->eml_path))->toBe(Inbox::fixture('z-kassanova.eml'));
    });

    it('refuses a bad signature, an old timestamp and a replayed token', function () {
        $raw = Inbox::fixture('z-kassanova.eml');
        $forged = [...mailgun($raw, $this->address), 'signature' => str_repeat('0', 64)];

        $this->post('/webhooks/inkorg/mailgun/mime', $forged)->assertStatus(406);
        $this->post('/webhooks/inkorg/mailgun/mime', mailgun($raw, $this->address, timestamp: now()->subMinutes(10)->getTimestamp()))->assertStatus(406);

        $signed = mailgun($raw, $this->address, token: 'one-token-only');
        $this->post('/webhooks/inkorg/mailgun/mime', $signed)->assertOk();
        $this->post('/webhooks/inkorg/mailgun/mime', $signed)->assertStatus(406);

        expect(messagesOf($this->company))->toHaveCount(1);
    });

    it('refuses everything while no signing key is configured', function () {
        config(['services.mailgun.webhook_signing_key' => null]);

        $this->post('/webhooks/inkorg/mailgun/mime', mailgun(Inbox::fixture('z-kassanova.eml'), $this->address))->assertStatus(406);
    });

    it('answers 200 and keeps nothing for unknown addresses, other domains and companies without the module', function () {
        $this->post('/webhooks/inkorg/mailgun/mime', mailgun(Inbox::fixture('z-kassanova.eml'), 'finns-inte-abc123@in.nonox.app'))->assertOk();
        $this->post('/webhooks/inkorg/mailgun/mime', mailgun(Inbox::fixture('z-kassanova.eml'), 'someone@example.com'))->assertOk();

        app(ModuleManager::class)->disable(InboxModule::KEY, $this->company, $this->owner, true);
        $this->post('/webhooks/inkorg/mailgun/mime', mailgun(Inbox::fixture('z-bongia.eml'), $this->address))->assertOk();

        expect(CompanyScope::bypass(fn () => InboxMessage::query()->count()))->toBe(0)
            ->and(UnassignedMessage::query()->count())->toBe(0);
    });

    it('answers 429 when the company has received too much this hour, so Mailgun tries again later', function () {
        foreach (range(1, WorkQuota::LIMITS['inbox_mail']) as $ignored) {
            RateLimiter::hit("work-quota:inbox_mail:{$this->company->id}", 3600);
        }

        $this->post('/webhooks/inkorg/mailgun/mime', mailgun(Inbox::fixture('z-kassanova.eml'), $this->address))->assertStatus(429);
        expect(messagesOf($this->company))->toHaveCount(0)
            ->and(fn () => Inbox::receive($this->company, Inbox::fixture('z-bongia.eml')))->toThrow(InboxQuotaExceeded::class);
    });

    it('reads Mailgun\'s own SPF, DKIM and spam results', function () {
        Inbox::allow($this->company, 'rapport@kassanova.example');

        $this->post('/webhooks/inkorg/mailgun/mime', mailgun(Inbox::fixture('z-kassanova.eml'), $this->address, extra: ['X-Mailgun-Sflag' => 'Yes']))->assertOk();

        $message = messagesOf($this->company)->sole();
        expect($message->status)->toBe(MessageStatus::Quarantine)
            ->and($message->hasQuarantineReason('SPAM'))->toBeTrue();
    });
});

describe('the shared address', function () {
    beforeEach(function () {
        [$this->otherOwner, $this->other] = Inbox::company();
        $this->shared = InboxAddress::shared();
    });

    it('routes to the one company where the sender signs in and is allowed', function () {
        signsInWith($this->company, 'rapport@kassanova.example');
        Inbox::allow($this->company, 'rapport@kassanova.example');

        $this->post('/webhooks/inkorg/mailgun/mime', mailgun(Inbox::fixture('z-kassanova.eml'), $this->shared))->assertOk();

        expect($this->shared)->toBe('mejl@in.nonox.app')
            ->and(messagesOf($this->company))->toHaveCount(1)
            ->and(messagesOf($this->other))->toHaveCount(0);
    });

    it('never lets an allowlist entry alone take mail from the shared address', function () {
        // The victim's member forwards from their own address; another company adds that address and the domain.
        signsInWith($this->company, 'rapport@kassanova.example');
        Inbox::allow($this->company, 'rapport@kassanova.example');
        Inbox::allow($this->other, 'rapport@kassanova.example');
        Inbox::allow($this->other, '@kassanova.example');

        $this->post('/webhooks/inkorg/mailgun/mime', mailgun(Inbox::fixture('z-kassanova.eml'), $this->shared))->assertOk();

        expect(messagesOf($this->company))->toHaveCount(1)
            ->and(messagesOf($this->other))->toHaveCount(0)
            ->and(UnassignedMessage::query()->count())->toBe(0);
    });

    it('does not route the shared address by domain', function () {
        signsInWith($this->company, 'rapport@kassanova.example');
        Inbox::allow($this->company, '@kassanova.example');

        $this->post('/webhooks/inkorg/mailgun/mime', mailgun(Inbox::fixture('z-kassanova.eml'), $this->shared))->assertOk();

        expect(messagesOf($this->company))->toHaveCount(0);
    });

    it('drops mail from a sender on no allowlist without a trace for the sender', function () {
        $this->post('/webhooks/inkorg/mailgun/mime', mailgun(Inbox::fixture('z-kassanova.eml'), $this->shared))->assertOk();

        expect(CompanyScope::bypass(fn () => InboxMessage::query()->count()))->toBe(0)
            ->and(UnassignedMessage::query()->count())->toBe(0);
    });

    it('holds mail from someone who is a member of several companies until a member claims it, and never assigns it by itself', function () {
        $user = signsInWith($this->company, 'rapport@kassanova.example');
        $this->other->users()->attach($user, ['role' => 'bookkeeper']);
        Inbox::allow($this->company, 'rapport@kassanova.example');
        Inbox::allow($this->other, 'rapport@kassanova.example');

        $this->post('/webhooks/inkorg/mailgun/mime', mailgun(Inbox::fixture('z-kassanova.eml'), $this->shared))->assertOk();

        $held = UnassignedMessage::query()->sole();
        expect($held->candidate_company_ids)->toEqualCanonicalizing([$this->company->id, $this->other->id])
            ->and(messagesOf($this->company))->toHaveCount(0)
            ->and(messagesOf($this->other))->toHaveCount(0)
            ->and(UnassignedMessage::waitingFor($this->company)->count())->toBe(1);

        // Someone outside the candidates cannot claim it, nor learn that it exists.
        [$strangerOwner, $stranger] = Inbox::company();
        expect(fn () => app(ClaimUnassignedMessage::class)($strangerOwner, $stranger, $held->id))->toThrow(ValidationException::class)
            ->and(UnassignedMessage::waitingFor($stranger)->count())->toBe(0);

        // A member of a candidate without the right to handle the inbox cannot either.
        $auditor = Bookkeeping::member($this->other, 'auditor');
        expect(fn () => app(ClaimUnassignedMessage::class)($auditor, $this->other, $held->id))->toThrow(AuthorizationException::class);

        $claimed = app(ClaimUnassignedMessage::class)($this->otherOwner, $this->other, $held->id);

        expect($claimed->company_id)->toBe($this->other->id)
            ->and($claimed->status)->toBe(MessageStatus::NeedsReview)
            ->and($claimed->proposal['kind'])->toBe('daily_report')
            ->and(messagesOf($this->other))->toHaveCount(1)
            ->and($held->fresh()->claimed_company_id)->toBe($this->other->id)
            ->and(UnassignedMessage::waitingFor($this->company)->count())->toBe(0)
            ->and(fn () => app(ClaimUnassignedMessage::class)($this->owner, $this->company, $held->id))->toThrow(ValidationException::class);
    });
});

describe('security before reading', function () {
    it('quarantines a sender who is not on the allowlist and reads nothing until the sender is allowed', function () {
        $message = Inbox::fresh(Inbox::receive($this->company, Inbox::fixture('z-kassanova.eml')));

        expect($message->status)->toBe(MessageStatus::Quarantine)
            ->and($message->hasQuarantineReason('NOT_ALLOWLISTED'))->toBeTrue()
            ->and($message->proposal)->toBeNull()
            ->and($message->document_type)->toBeNull();

        $released = app(InboxReview::class)->allowSender($this->owner, $message);
        $message = Inbox::fresh($message);

        expect($released)->toBe(1)
            ->and($message->status)->toBe(MessageStatus::NeedsReview)
            ->and($message->proposal['kind'])->toBe('daily_report')
            ->and(CurrentCompany::run($this->company, fn () => AllowedSender::query()->pluck('pattern')->all()))->toBe(['rapport@kassanova.example']);
    });

    it('quarantines a message from an allowlisted sender whose DMARC fails, and never books it automatically', function () {
        Inbox::allow($this->company, 'rapport@kassanova.example');
        $raw = Inbox::mail('rapport@kassanova.example', 'Z-rapport', 'Rapport', [['kassanova-z.csv', Inbox::fixture('kassanova-z.csv'), 'text/csv']], Inbox::DMARC_FAIL);

        $message = Inbox::fresh(Inbox::receive($this->company, $raw));

        expect($message->status)->toBe(MessageStatus::Quarantine)
            ->and($message->hasQuarantineReason('AUTH_FAILED'))->toBeTrue()
            ->and($message->hasQuarantineReason('NOT_ALLOWLISTED'))->toBeFalse()
            ->and($message->quarantine_reasons[0]['message'])->toContain('DMARC')
            ->and($message->failedAuthentication())->toBeTrue();

        app(InboxReview::class)->releaseAfterReview($this->owner, $message);
        $message = Inbox::fresh($message);

        expect($message->status)->toBe(MessageStatus::NeedsReview)
            ->and(array_column($message->warnings, 'code'))->toContain('RELEASED_AUTH_FAILED');
    });

    it('quarantines a message that neither SPF nor DKIM vouches for', function () {
        Inbox::allow($this->company, 'rapport@kassanova.example');
        $raw = Inbox::mail('rapport@kassanova.example', 'Z-rapport', 'Rapport', [], 'mx.in.nonox.example; spf=softfail smtp.mailfrom=%1$s; dkim=none');

        expect(Inbox::fresh(Inbox::receive($this->company, $raw))->hasQuarantineReason('AUTH_FAILED'))->toBeTrue();
    });

    it('blocks a program in an attachment, keeps it as evidence and never serves it', function () {
        Inbox::allow($this->company, 'rapport@kassanova.example');
        $raw = Inbox::mail('rapport@kassanova.example', 'Faktura', 'Se bilagan', [['faktura.pdf.exe', "MZ\x90\x00program", 'application/octet-stream']]);

        $message = Inbox::fresh(Inbox::receive($this->company, $raw));
        $attachment = $message->attachments->sole();

        expect($message->status)->toBe(MessageStatus::Quarantine)
            ->and($message->hasQuarantineReason('BLOCKED_ATTACHMENT'))->toBeTrue()
            ->and($attachment->isBlocked())->toBeTrue();

        $this->actingAs($this->owner)
            ->get(route('inbox.attachment', ['message' => $message->id, 'attachment' => $attachment->id]))
            ->assertNotFound();
    });

    it('blocks Office files with macros and archives holding programs', function () {
        $zip = function (array $files): string {
            $path = tempnam(sys_get_temp_dir(), 'nonox-test-zip');
            $archive = new ZipArchive;
            $archive->open($path, ZipArchive::OVERWRITE);

            foreach ($files as $name => $contents) {
                $archive->addFromString($name, $contents);
            }

            $archive->close();
            $contents = (string) file_get_contents($path);
            unlink($path);

            return $contents;
        };

        expect(DangerousContent::reason('rapport.xlsx', $zip(['xl/vbaProject.bin' => 'x', 'xl/workbook.xml' => '<x/>'])))->not->toBeNull()
            ->and(DangerousContent::reason('underlag.zip', $zip(['kvitto.js' => 'alert(1)'])))->not->toBeNull()
            ->and(DangerousContent::reason('kassaboken.xlsx', Inbox::fixture('kassaboken.xlsx')))->toBeNull()
            ->and(DangerousContent::reason('faktura.pdf', Inbox::fixture('faktura.pdf')))->toBeNull()
            ->and(DangerousContent::reason('sida.html', '<html><script>x</script></html>'))->not->toBeNull();
    });

    it('scans every attachment when a virus scanner is configured', function () {
        app()->instance(VirusScanner::class, new class implements VirusScanner
        {
            public function enabled(): bool
            {
                return true;
            }

            public function scan(string $contents): ?string
            {
                return str_contains($contents, 'EICAR') ? 'Viruskontrollen hittade Eicar-Test-Signature.' : null;
            }
        });
        Inbox::allow($this->company, 'rapport@kassanova.example');

        $message = Inbox::fresh(Inbox::receive($this->company, Inbox::mail('rapport@kassanova.example', 'Faktura', 'Bilaga', [['faktura.pdf', '%PDF-1.4 EICAR', 'application/pdf']])));

        expect($message->status)->toBe(MessageStatus::Quarantine)
            ->and($message->attachments->sole()->blocked_reason)->toContain('Eicar');
    });

    it('warns about a sender domain that looks like a known supplier\'s', function () {
        Inbox::allow($this->company, '@kassanova.example');
        Inbox::allow($this->company, '@kassanova.se');
        app(SaveSupplier::class)($this->owner, $this->company, ['name' => 'Grossisten Exempel AB', 'email' => 'faktura@grossisten.example', 'payment_terms_days' => 30]);

        $typo = Inbox::fresh(Inbox::receive($this->company, Inbox::mail('faktura@gross1sten.example', 'Faktura', 'Ny bankgiro!')));
        $otherTld = Inbox::fresh(Inbox::receive($this->company, Inbox::mail('rapport@kassanova.com', 'Rapport', 'Hej')));
        $suffixed = Inbox::fresh(Inbox::receive($this->company, Inbox::mail('rapport@kassanova.example.co', 'Rapport', 'Hej igen')));
        $same = Inbox::fresh(Inbox::receive($this->company, Inbox::mail('rapport@kassanova.example', 'Rapport', 'Hej')));

        expect(array_column($typo->warnings, 'code'))->toContain('LOOKALIKE_DOMAIN')
            ->and($typo->warnings[0]['message'])->toContain('grossisten.example')
            ->and(array_column($otherTld->warnings, 'code'))->toContain('LOOKALIKE_DOMAIN')
            ->and(array_column($suffixed->warnings, 'code'))->toContain('LOOKALIKE_DOMAIN')
            ->and(array_column($same->warnings, 'code'))->not->toContain('LOOKALIKE_DOMAIN');
    });

    it('drops a message larger than the limit', function () {
        config(['services.inbox.max_message_kb' => 1]);

        expect(Inbox::receive($this->company, Inbox::fixture('peppol.eml')))->toBeNull()
            ->and(messagesOf($this->company))->toHaveCount(0);
    });

    it('lets members of other companies see nothing of the company\'s inbox', function () {
        Inbox::allow($this->company, 'rapport@kassanova.example');
        $message = Inbox::receive($this->company, Inbox::fixture('z-kassanova.eml'));
        [$otherOwner] = Inbox::company();

        $this->actingAs($otherOwner)
            ->get(route('inbox.original', ['message' => $message?->id]))
            ->assertNotFound();
    });
});

it('never lets a user without the inbox permission reach the inbox', function () {
    $user = User::factory()->create();
    $this->company->users()->attach($user, ['role' => 'approver']);
    $user->switchCompany($this->company);

    $this->actingAs($user->fresh())->get(route('inbox.index'))->assertForbidden();
});

function signsInWith(Company $company, string $email): User
{
    $user = User::factory()->create(['email' => $email]);
    $company->users()->attach($user, ['role' => 'bookkeeper']);

    return $user;
}
