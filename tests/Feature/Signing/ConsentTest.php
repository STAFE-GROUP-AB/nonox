<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveSalesDocument;
use App\Actions\Signing\RecordSigningConsent;
use App\Actions\Signing\SendForSigning;
use App\Actions\Signing\SignAtLink;
use App\Domain\Signing\Chain\CanonicalJson;
use App\Domain\Signing\Chain\ChainVerifier;
use App\Domain\Signing\Chain\EventHash;
use App\Domain\Signing\SigningConsent;
use App\Domain\Signing\SigningEventType;
use App\Domain\Signing\SigningPdfs;
use App\Domain\Signing\SigningStatus;
use App\Models\AuditLog;
use App\Models\SalesDocument;
use App\Models\SigningEvent;
use App\Models\SigningRequest;
use App\Notifications\SigningNotice;
use App\Support\Branding\Brand;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;

/*
 * Consent to electronic records (ADR-046 amendment 2026-11-18, gap 7,
 * package S4): a short, versioned text each signer accepts once, after any
 * check and before the document is shown. The version, the text's SHA-256
 * and the time are stored on the signer row (fixed by a trigger), chained as
 * CONSENTED, part of the evidence digest and printed on the certificate. A
 * signer who refuses is told to contact the sender, and the sender is told.
 */

/** @return array{request: SigningRequest, token: string} */
function s4Send(object $test, string $check = 'NONE', ?string $accessCode = null): array
{
    $sent = app(SendForSigning::class)($test->owner, $test->company, $test->quote, ['email' => 'anna@kontorshuset.test', 'verification' => $check, 'access_code' => $accessCode]);

    return ['request' => $sent['request'], 'token' => basename((string) parse_url($sent['url'], PHP_URL_PATH))];
}

/** @return array<string, string> */
function s4ConsentInput(?string $locale = null): array
{
    return ['consent' => '1', 'consent_version' => SigningConsent::VERSION, 'consent_text_sha256' => SigningConsent::sha256($locale)];
}

/** @return array<string, mixed> */
function s4SignInput(SigningRequest $request): array
{
    return ['name' => 'Anna Andersson', 'accept' => '1', 'method' => 'TYPED', 'typed_signature' => 'Anna Andersson', 'document_sha256' => $request->document_sha256, ...SigningPdfs::acceptanceFields($request)];
}

/** @return list<string> */
function s4Events(SigningRequest $request): array
{
    return SigningEvent::query()->where('signing_request_id', $request->id)->orderBy('id')->pluck('event')->map(fn ($event): string => $event instanceof SigningEventType ? $event->value : (string) $event)->all();
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    Storage::fake('local');
    Mail::fake();
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    Crm::enable($this->company, $this->owner);
    $this->customer = Crm::customer($this->company, $this->owner, 'Kontorshuset AB', ['email' => 'inkop@kontorshuset.test']);
    $this->quote = app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::QUOTE, [
        'customer_id' => $this->customer->id,
        'document_date' => '2026-06-15',
        'until_date' => '2026-07-15',
        'rows' => [['description' => 'Konsulttimme', 'quantity' => '10', 'price' => '1000', 'vat_rate' => 2500]],
    ]);
});

it('asks for the consent before the document is shown, and records no opening until it is given', function () {
    $sent = s4Send($this);
    auth()->logout();

    $this->get("/signera/{$sent['token']}")->assertOk()
        ->assertSee('Samtycke till elektroniska handlingar')
        ->assertSee('Jag godkänner att handlingen skrivs under elektroniskt.')
        ->assertSee('Jag vill inte skriva under elektroniskt')
        ->assertDontSee('Konsulttimme')
        ->assertDontSee($sent['request']->document_sha256);
    $this->get("/signera/{$sent['token']}/dokument")->assertRedirect("/signera/{$sent['token']}");

    CurrentCompany::set($this->company);
    expect(s4Events($sent['request']))->toBe(['SENT'])
        ->and($sent['request']->refresh()->opened_at)->toBeNull();
});

it('stores the version, the text hash and the time once, chains CONSENTED, then shows the document', function () {
    $sent = s4Send($this);
    auth()->logout();

    $this->post("/signera/{$sent['token']}/samtycke", s4ConsentInput())->assertRedirect("/signera/{$sent['token']}");
    $this->post("/signera/{$sent['token']}/samtycke", s4ConsentInput())->assertRedirect("/signera/{$sent['token']}");
    $this->get("/signera/{$sent['token']}")->assertOk()->assertSee('Konsulttimme')->assertDontSee('Jag vill inte skriva under elektroniskt');
    $this->get("/signera/{$sent['token']}/dokument")->assertOk();

    CurrentCompany::set($this->company);
    $request = $sent['request']->refresh();
    $event = SigningEvent::query()->where('signing_request_id', $request->id)->where('event', 'CONSENTED')->sole();

    expect($request->consent_version)->toBe(SigningConsent::VERSION)
        ->and($request->consent_text_sha256)->toBe(hash('sha256', SigningConsent::canonical('sv')))
        ->and($request->consent_at?->toDateTimeString())->toBe('2026-06-15 10:00:00')
        ->and(s4Events($request))->toBe(['SENT', 'CONSENTED', 'OPENED'])
        ->and($event->payload)->toEqual(['text_sha256' => $request->consent_text_sha256, 'version' => SigningConsent::VERSION])
        ->and($event->envelope_id)->toBe($request->envelope_id)
        ->and($event->hash)->toBe(EventHash::ofEvent($event));

    $audit = AuditLog::query()->where('action', 'signing.consented')->sole();
    expect($audit->payload)->toEqual(['signing_request_id' => $request->id, 'envelope_id' => $request->envelope_id, 'version' => SigningConsent::VERSION])
        ->and(json_encode($audit->payload))->not->toContain('anna@');
});

it('refuses a consent without the box, with an old version or with a text that was never shown', function () {
    $sent = s4Send($this);
    auth()->logout();

    $this->post("/signera/{$sent['token']}/samtycke", [...s4ConsentInput(), 'consent' => null])->assertSessionHasErrors('consent');
    $this->post("/signera/{$sent['token']}/samtycke", [...s4ConsentInput(), 'consent_version' => '0.9'])->assertSessionHasErrors(['consent' => 'Texten om elektroniska handlingar har ändrats sedan sidan öppnades. Ladda om sidan och läs den igen.']);
    $this->post("/signera/{$sent['token']}/samtycke", [...s4ConsentInput(), 'consent_text_sha256' => hash('sha256', 'något annat')])->assertSessionHasErrors('consent');

    CurrentCompany::set($this->company);
    expect($sent['request']->refresh()->consent_at)->toBeNull()->and(s4Events($sent['request']))->toBe(['SENT']);
});

it('takes the hash of the text in the language the page was shown in, and only that one', function () {
    $sent = s4Send($this);

    // A Swedish page: the English text's hash was not what the signer saw.
    expect(fn () => app(RecordSigningConsent::class)->give($sent['request'], s4ConsentInput('en')))
        ->toThrow(ValidationException::class, 'Texten om elektroniska handlingar har ändrats');

    App::setLocale('en');
    app(RecordSigningConsent::class)->give($sent['request'], s4ConsentInput('en'));
    App::setLocale('sv');

    expect($sent['request']->refresh()->consent_text_sha256)->toBe(hash('sha256', SigningConsent::canonical('en')))
        ->and(SigningConsent::sha256('en'))->not->toBe(SigningConsent::sha256('sv'));
});

it('sends an accepted signer who presses refuse in another tab back to the document, and tells nobody', function () {
    Notification::fake();
    $sent = s4Send($this);
    auth()->logout();

    $this->post("/signera/{$sent['token']}/samtycke", s4ConsentInput())->assertRedirect("/signera/{$sent['token']}");
    $this->post("/signera/{$sent['token']}/samtycke/avboj")->assertRedirect("/signera/{$sent['token']}");

    CurrentCompany::set($this->company);
    Notification::assertNothingSent();
    expect(s4Events($sent['request']))->toBe(['SENT', 'CONSENTED']);
});

it('says who is responsible for the answer, and that a test company mails no copy', function () {
    $sent = s4Send($this);
    auth()->logout();

    $this->get("/signera/{$sent['token']}")->assertOk()
        ->assertSee('Personuppgiftsansvarig är '.($this->company->legal_name ?: $this->company->name))
        ->assertSee('artikel 6.1 f')
        ->assertDontSee('Testföretag skickar inga mejl');

    $this->company->forceFill(['is_test_company' => true])->save();
    $this->get("/signera/{$sent['token']}")->assertOk()->assertSee('Testföretag skickar inga mejl, så ingen kopia skickas med e-post.');
});

it('never signs without the consent, whatever path calls the action', function () {
    $sent = s4Send($this);

    expect(fn () => app(SignAtLink::class)->sign($sent['request'], s4SignInput($sent['request']), '127.0.0.1', 'Test'))
        ->toThrow(ValidationException::class, 'Godkänn först att handlingen skrivs under elektroniskt.');
    expect($sent['request']->refresh()->status)->toBe(SigningStatus::Pending);
});

it('asks for the check first: no consent can be given or refused without it', function () {
    $sent = s4Send($this, 'ACCESS_CODE', 'rätt-kod-123');
    auth()->logout();

    $this->get("/signera/{$sent['token']}")->assertOk()->assertSee('Bekräfta att det är du')->assertDontSee('Samtycke till elektroniska handlingar');
    $this->post("/signera/{$sent['token']}/samtycke", s4ConsentInput())->assertSessionHasErrors('code');
    $this->post("/signera/{$sent['token']}/samtycke/avboj")->assertSessionHasErrors('code');

    $this->post("/signera/{$sent['token']}/kontroll", ['code' => 'rätt-kod-123'])->assertRedirect("/signera/{$sent['token']}");
    $this->get("/signera/{$sent['token']}")->assertOk()->assertSee('Samtycke till elektroniska handlingar');

    CurrentCompany::set($this->company);
    expect($sent['request']->refresh()->consent_at)->toBeNull()->and(s4Events($sent['request']))->toBe(['SENT', 'VERIFIED']);
});

it('still serves the PDF to a signer who signed before the consent step existed', function () {
    $sent = s4Send($this);
    // A row signed before S4: no consent columns (the trigger allows signing from PENDING without them).
    DB::table('crm_signing_requests')->where('id', $sent['request']->id)->update(['status' => 'SIGNED', 'signed_at' => now(), 'signer_name' => 'Anna Andersson']);
    auth()->logout();

    $this->get("/signera/{$sent['token']}/dokument")->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

it('tells a signer who refuses to contact the sender, and the sender once; the link stays open', function () {
    Notification::fake();
    $sent = s4Send($this);
    auth()->logout();

    $this->post("/signera/{$sent['token']}/samtycke/avboj")->assertOk()
        ->assertSee('Kontakta avsändaren om du vill skriva under på annat sätt.')
        ->assertDontSee('Konsulttimme');
    $this->post("/signera/{$sent['token']}/samtycke/avboj")->assertOk();

    CurrentCompany::set($this->company);
    Notification::assertSentToTimes($this->owner, SigningNotice::class, 1);
    Notification::assertSentTo($this->owner, SigningNotice::class, fn (SigningNotice $notice): bool => str_contains($notice->sentence, 'vill inte skriva under elektroniskt') && ! str_contains($notice->sentence, 'anna@'));

    $request = $sent['request']->refresh();
    expect($request->status)->toBe(SigningStatus::Pending)
        ->and($request->consent_at)->toBeNull()
        ->and(s4Events($request))->toBe(['SENT', 'CONSENT_REFUSED'])
        ->and(SigningEvent::query()->where('event', 'CONSENT_REFUSED')->sole()->payload)->toBe(['version' => SigningConsent::VERSION])
        ->and(AuditLog::query()->where('action', 'signing.consent_refused')->count())->toBe(1);

    // Changing one's mind: the link still asks, and a consent then goes through.
    auth()->logout();
    $this->get("/signera/{$sent['token']}")->assertOk()->assertSee('Jag godkänner att handlingen skrivs under elektroniskt.');
    $this->post("/signera/{$sent['token']}/samtycke", s4ConsentInput())->assertRedirect("/signera/{$sent['token']}");
    $this->get("/signera/{$sent['token']}")->assertOk()->assertSee('Konsulttimme');
    $this->post("/signera/{$sent['token']}", s4SignInput($request))->assertOk();

    CurrentCompany::set($this->company);
    expect($request->refresh()->status)->toBe(SigningStatus::Signed)
        ->and(s4Events($request))->toBe(['SENT', 'CONSENT_REFUSED', 'CONSENTED', 'OPENED', 'SIGNED', 'COMPLETED']);
});

it('puts the consent in the evidence digest, on the certificate, and keeps signing:verify clean', function () {
    $sent = s4Send($this);
    auth()->logout();

    $this->post("/signera/{$sent['token']}/samtycke", s4ConsentInput())->assertRedirect();
    $this->get("/signera/{$sent['token']}")->assertOk();
    $this->post("/signera/{$sent['token']}", s4SignInput($sent['request']))->assertOk();

    CurrentCompany::set($this->company);
    $signed = $sent['request']->refresh();
    $digest = SigningEvent::query()->where('signing_request_id', $signed->id)->where('event', 'SIGNED')->sole()->payload['evidence'];

    expect($digest)->toBe(EventHash::evidenceOf($signed))
        ->and(app(ChainVerifier::class)->verify($this->company)['problems'])->toBe([]);

    // Another consent on the row would give another digest: the chain covers it.
    $other = (clone $signed)->forceFill(['consent_text_sha256' => str_repeat('0', 64)]);
    expect(EventHash::evidenceOf($other))->not->toBe($digest);

    $html = view('signing.certificate', ['request' => $signed->load('events'), 'company' => $this->company, 'brand' => Brand::for($this->company), 'title' => 'Offert'])->render();
    expect($html)->toContain('Samtycke till elektroniska handlingar')
        ->toContain('Consent to electronic records')
        ->toContain('Version '.SigningConsent::VERSION)
        ->toContain($signed->consent_text_sha256);
});

it('keeps the digest of a row without consent exactly as before S4', function () {
    $sent = s4Send($this);
    $request = $sent['request']->refresh();

    $before = hash('sha256', CanonicalJson::encode([
        'acceptance_text' => $request->acceptance_text,
        'content_hash' => $request->content_hash,
        'decline_reason' => $request->decline_reason,
        'declined_at' => CanonicalJson::seconds($request->declined_at),
        'document_sha256' => $request->document_sha256,
        'email' => $request->email,
        'signature_image' => $request->signature_image,
        'signature_method' => $request->signature_method,
        'signed_at' => CanonicalJson::seconds($request->signed_at),
        'signer_ip' => $request->signer_ip,
        'signer_name' => $request->signer_name,
        'signer_title' => $request->signer_title,
        'signer_user_agent' => $request->signer_user_agent,
        'status' => $request->status->value,
    ]));

    expect(EventHash::evidenceOf($request))->toBe($before);
});

it('reports a consent that differs from its CONSENTED event', function () {
    $sent = s4Send($this);
    app(RecordSigningConsent::class)->give($sent['request'], s4ConsentInput());

    expect(app(ChainVerifier::class)->verify($this->company)['problems'])->toBe([]);

    // A row with a consent but no event: set on a second signer row behind the app's back (the trigger allows it while pending).
    $second = app(SendForSigning::class)($this->owner, $this->company, $this->quote, ['email' => 'bo@kontorshuset.test'])['request'];
    DB::table('crm_signing_requests')->where('id', $second->id)->update(['consent_version' => '1.0', 'consent_text_sha256' => str_repeat('a', 64), 'consent_at' => now()]);

    expect(collect(app(ChainVerifier::class)->verify($this->company)['problems'])->where('code', 'CONSENT')->pluck('signing_request_id')->all())->toBe([$second->id]);
});

it('keeps the consent fixed once given, and refuses one on a signer who has signed or declined', function () {
    $sent = s4Send($this);
    app(RecordSigningConsent::class)->give($sent['request'], s4ConsentInput());

    // Each attempt in its own savepoint, so a refused one leaves the test's transaction usable.
    $update = fn (int $id, array $values) => fn () => DB::transaction(fn () => DB::table('crm_signing_requests')->where('id', $id)->update($values));

    expect($update($sent['request']->id, ['consent_version' => '2.0']))->toThrow(QueryException::class, 'cannot be changed (ADR-046 S4)')
        ->and($update($sent['request']->id, ['consent_version' => null, 'consent_text_sha256' => null, 'consent_at' => null]))->toThrow(QueryException::class, 'cannot be changed (ADR-046 S4)')
        ->and($update($sent['request']->id, ['consent_at' => now()->addHour()]))->toThrow(QueryException::class, 'cannot be changed (ADR-046 S4)');

    $declined = app(SendForSigning::class)($this->owner, $this->company, $this->quote, ['email' => 'bo@kontorshuset.test'])['request'];
    app(SignAtLink::class)->decline($declined, null);

    expect($update($declined->id, ['consent_version' => '1.0', 'consent_text_sha256' => str_repeat('a', 64), 'consent_at' => now()]))
        ->toThrow(QueryException::class, 'only while the signer is pending (ADR-046 S4)');
});

it('keeps the text in docs/juridik word for word, with the same version and date', function () {
    $markdown = (string) file_get_contents(base_path('docs/juridik/elektroniska-handlingar.md'));

    expect($markdown)->toContain('Version '.SigningConsent::VERSION.' · gäller från '.SigningConsent::EFFECTIVE)
        ->toContain('| '.SigningConsent::VERSION.' | '.SigningConsent::EFFECTIVE.' |')
        ->toContain('TODO(legal-verify)')
        ->toContain('En jurist ska granska den innan den används');

    foreach (['sv', 'en'] as $locale) {
        $text = SigningConsent::text($locale);

        foreach ($text['paragraphs'] as $paragraph) {
            expect($markdown)->toContain($paragraph);
        }

        expect($markdown)->toContain('**'.$text['statement'].'**');
    }
});
