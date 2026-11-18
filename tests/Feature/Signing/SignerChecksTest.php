<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveSalesDocument;
use App\Actions\Signing\SendForSigning;
use App\Actions\Signing\SignAtLink;
use App\Domain\Signing\Chain\ChainVerifier;
use App\Domain\Signing\SigningEventType;
use App\Domain\Signing\SigningPdfs;
use App\Domain\Signing\SigningStatus;
use App\Domain\Signing\Verification\CheckRefused;
use App\Domain\Signing\Verification\SignerCheck;
use App\Domain\Signing\Verification\SignerChecks;
use App\Livewire\Signing\SigningPanel;
use App\Mail\SigningCodeMail;
use App\Mail\SigningRequestMail;
use App\Models\AuditLog;
use App\Models\SalesDocument;
use App\Models\SigningEvent;
use App\Models\SigningRequest;
use App\Models\SigningVerificationCode;
use App\Notifications\SigningNotice;
use App\Support\Branding\Brand;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Testing\Fakes\MailFake;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;

/*
 * Signer checks (ADR-046 amendment 2026-11-18, gap 3, package S3): an
 * e-mail code or an access code before the document is shown. Codes are
 * stored as an HMAC only, compared in constant time, expire, are used once,
 * are throttled per signer and per IP address, and lock the signer out
 * after too many failures, with a notice to the sender. Every step is an
 * event in the envelope's hash chain.
 */

function s3Png(): string
{
    $image = imagecreatetruecolor(300, 100);
    imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
    imageline($image, 10, 50, 290, 60, imagecolorallocate($image, 0, 0, 0));
    ob_start();
    imagepng($image);

    return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
}

/** @return array<string, mixed> */
function s3SignInput(SigningRequest $request): array
{
    return ['name' => 'Anna Andersson', 'accept' => '1', 'method' => 'DRAWN', 'signature' => s3Png(), 'document_sha256' => $request->document_sha256, ...SigningPdfs::acceptanceFields($request)];
}

/**
 * Sends the quote with a check and returns the request and its token.
 *
 * @return array{request: SigningRequest, token: string}
 */
function s3Send(object $test, string $check, ?string $accessCode = null): array
{
    $sent = app(SendForSigning::class)($test->owner, $test->company, $test->quote, ['email' => 'anna@kontorshuset.test', 'verification' => $check, 'access_code' => $accessCode]);

    return ['request' => $sent['request'], 'token' => basename((string) parse_url($sent['url'], PHP_URL_PATH))];
}

/** The code in the newest code mail to the signer. */
function s3MailedCode(): string
{
    $code = null;

    Mail::assertSent(SigningCodeMail::class, function (SigningCodeMail $mail) use (&$code): bool {
        $code = $mail->code;

        return $mail->hasTo('anna@kontorshuset.test');
    });

    return (string) $code;
}

/** @return list<string> */
function s3Events(SigningRequest $request): array
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

it('keeps the link alone as the default for quotes, exactly as before', function () {
    $sent = s3Send($this, 'NONE');

    expect($sent['request']->verification)->toBe(SignerCheck::None);

    auth()->logout();
    $this->get("/signera/{$sent['token']}")->assertOk()->assertSee('Offert')->assertDontSee('Bekräfta att det är du');
    $this->get("/signera/{$sent['token']}/dokument")->assertOk();
});

it('shows nothing of the document before an e-mail code has passed, and nothing is mailed on a GET', function () {
    $sent = s3Send($this, 'EMAIL_CODE');
    auth()->logout();

    $this->get("/signera/{$sent['token']}")->assertOk()
        ->assertSee('Bekräfta att det är du')
        ->assertSee('a•••@kontorshuset.test')
        ->assertDontSee('Konsulttimme')
        ->assertDontSee($sent['request']->document_sha256);
    Mail::assertNotSent(SigningCodeMail::class);

    $this->get("/signera/{$sent['token']}/dokument")->assertRedirect("/signera/{$sent['token']}");
    $this->post("/signera/{$sent['token']}", s3SignInput($sent['request']))->assertRedirect("/signera/{$sent['token']}");
    $this->post("/signera/{$sent['token']}/avboj", ['reason' => 'Nej'])->assertRedirect("/signera/{$sent['token']}");

    expect($sent['request']->refresh()->status)->toBe(SigningStatus::Pending);
});

it('mails a six-digit code once, stores only its HMAC, and lets the signer sign after it', function () {
    $sent = s3Send($this, 'EMAIL_CODE');
    $request = $sent['request'];
    auth()->logout();

    $this->post("/signera/{$sent['token']}/kod")->assertRedirect("/signera/{$sent['token']}");
    // A double click within the minute reuses the code: one mail only.
    $this->post("/signera/{$sent['token']}/kod")->assertSessionHas('signing-code');
    Mail::assertSent(SigningCodeMail::class, 1);

    $code = s3MailedCode();
    CurrentCompany::set($this->company);
    $row = SigningVerificationCode::query()->where('signing_request_id', $request->id)->sole();
    expect($code)->toMatch('/^\d{6}$/')
        ->and($row->code_mac)->toBe(hash_hmac('sha256', "{$request->id}|{$code}", (string) config('app.key')))
        ->and($row->code_mac)->not->toBe(hash('sha256', "{$request->id}|{$code}"))
        ->and($row->toArray())->not->toHaveKey('code_mac');

    $wrong = $code === '000000' ? '111111' : '000000';
    $this->post("/signera/{$sent['token']}/kontroll", ['code' => $wrong])->assertSessionHasErrors(['code' => 'Koden stämmer inte. Försök kvar: 4.']);
    $this->post("/signera/{$sent['token']}/kontroll", ['code' => substr($code, 0, 3).' '.substr($code, 3)])->assertRedirect("/signera/{$sent['token']}");

    $this->get("/signera/{$sent['token']}")->assertOk()->assertSee('Konsulttimme');
    $this->get("/signera/{$sent['token']}/dokument")->assertOk();
    $this->post("/signera/{$sent['token']}", s3SignInput($request->refresh()))->assertOk();

    CurrentCompany::set($this->company);
    $request->refresh();
    expect($request->status)->toBe(SigningStatus::Signed)
        ->and($request->verified_at)->not->toBeNull()
        ->and($request->verification_failures)->toBe(1)
        ->and(s3Events($request))->toBe(['SENT', 'CODE_SENT', 'VERIFY_FAILED', 'VERIFIED', 'OPENED', 'SIGNED', 'COMPLETED']);

    $failed = SigningEvent::query()->where('signing_request_id', $request->id)->where('event', 'VERIFY_FAILED')->sole();
    expect($failed->payload)->toEqual(['channel' => 'LINK', 'method' => 'EMAIL_CODE', 'code_id' => $row->id, 'failures' => 1])
        ->and($failed->ip)->toBeNull()
        ->and(app(ChainVerifier::class)->verify($this->company)['problems'])->toBe([]);
});

it('uses a code once, expires it after ten minutes, and allows three codes an hour', function () {
    $sent = s3Send($this, 'EMAIL_CODE');
    $request = $sent['request'];
    $checks = app(SignerChecks::class);

    expect($checks->sendCode($request))->toBe(SignerChecks::SENT);
    $code = s3MailedCode();
    $checks->verify($request, $code);

    // Used once: the same code does not pass again.
    expect(fn () => $checks->verify($request, $code))->toThrow(CheckRefused::class, CheckRefused::EXPIRED);

    $this->travel(61)->seconds();
    expect($checks->sendCode($request))->toBe(SignerChecks::SENT);
    $this->travel(11)->minutes();
    expect(fn () => $checks->verify($request, s3MailedCode()))->toThrow(CheckRefused::class, CheckRefused::EXPIRED);

    expect($checks->sendCode($request))->toBe(SignerChecks::SENT);
    $this->travel(61)->seconds();
    expect(fn () => $checks->sendCode($request))->toThrow(CheckRefused::class, CheckRefused::TOO_MANY_CODES);

    $this->travel(1)->hours();
    expect($checks->sendCode($request))->toBe(SignerChecks::SENT);
});

it('stores the access code hashed only, never mails it, and refuses one shorter than six characters', function () {
    expect(fn () => s3Send($this, 'ACCESS_CODE', 'abc12'))->toThrow(ValidationException::class);

    $sent = s3Send($this, 'ACCESS_CODE', 'blå-hund-42');
    $request = $sent['request']->refresh();

    expect(Hash::check('blå-hund-42', (string) $request->access_code_hash))->toBeTrue()
        ->and($request->toArray())->not->toHaveKey('access_code_hash')
        ->and(json_encode(AuditLog::query()->pluck('payload')->all()))->not->toContain('blå-hund-42');

    Mail::assertQueued(SigningRequestMail::class, fn (SigningRequestMail $mail): bool => ! str_contains($mail->render(), 'blå-hund-42') && str_contains($mail->render(), 'åtkomstkod'));
    Mail::assertNotSent(SigningCodeMail::class);

    auth()->logout();
    $this->get("/signera/{$sent['token']}")->assertSee('Koden skickas aldrig med e-post.');
    $this->post("/signera/{$sent['token']}/kod")->assertSessionHasErrors('code');
    Mail::assertNotSent(SigningCodeMail::class);
    $this->post("/signera/{$sent['token']}/kontroll", ['code' => 'blå-hund-42'])->assertRedirect("/signera/{$sent['token']}");
    $this->get("/signera/{$sent['token']}")->assertSee('Konsulttimme');
});

it('locks the signer out after five wrong access codes, tells the sender, and lets the sender unlock', function () {
    Notification::fake();
    $sent = s3Send($this, 'ACCESS_CODE', 'rätt-kod-123');
    $request = $sent['request'];
    $checks = app(SignerChecks::class);

    foreach (range(1, 4) as $try) {
        expect(fn () => $checks->verify($request, 'fel-kod-000'))->toThrow(CheckRefused::class, CheckRefused::WRONG);
    }

    expect(fn () => $checks->verify($request, 'fel-kod-000'))->toThrow(CheckRefused::class, CheckRefused::LOCKED);
    // Locked: even the right code is refused now.
    expect(fn () => $checks->verify($request, 'rätt-kod-123'))->toThrow(CheckRefused::class, CheckRefused::LOCKED);

    $request->refresh();
    expect($request->locked_at)->not->toBeNull()
        ->and($request->verification_failures)->toBe(5)
        ->and(s3Events($request))->toBe(['SENT', 'VERIFY_FAILED', 'VERIFY_FAILED', 'VERIFY_FAILED', 'VERIFY_FAILED', 'VERIFY_FAILED', 'LOCKED'])
        ->and(AuditLog::query()->where('action', 'signing.locked')->sole()->payload)->toEqual(['signing_request_id' => $request->id, 'method' => 'ACCESS_CODE', 'failures' => 5]);
    Notification::assertSentTo($this->owner, SigningNotice::class, fn (SigningNotice $notice): bool => str_contains($notice->sentence, 'låstes ute'));

    auth()->logout();
    $this->get("/signera/{$sent['token']}")->assertSee('Länken är låst efter för många felaktiga försök');
    $this->actingAs($this->owner);

    CurrentCompany::set($this->company);
    Livewire::test(SigningPanel::class, ['quoteId' => $this->quote->id])
        ->assertSee('låst')
        ->assertSee('Lås upp')
        ->call('unlock', $request->id)
        ->assertDispatched('toast', message: 'Länken är upplåst. Undertecknaren kan försöka igen.');

    $request->refresh();
    expect($request->locked_at)->toBeNull()->and($request->verification_failures)->toBe(0);
    $checks->verify($request, 'rätt-kod-123');

    expect(array_slice(s3Events($request), -2))->toBe(['UNLOCKED', 'VERIFIED'])
        ->and(app(ChainVerifier::class)->verify($this->company)['problems'])->toBe([]);
});

it('locks an e-mail code signer after ten failures and spends a code after five', function () {
    $sent = s3Send($this, 'EMAIL_CODE');
    $request = $sent['request'];
    $checks = app(SignerChecks::class);
    $checks->sendCode($request);
    $wrong = s3MailedCode() === '000000' ? '111111' : '000000';

    foreach (range(1, 4) as $try) {
        expect(fn () => $checks->verify($request, $wrong))->toThrow(CheckRefused::class, CheckRefused::WRONG);
    }

    expect(fn () => $checks->verify($request, $wrong))->toThrow(CheckRefused::class, CheckRefused::EXPIRED);

    $this->travel(61)->seconds();
    $checks->sendCode($request);

    foreach (range(1, 4) as $try) {
        expect(fn () => $checks->verify($request, $wrong))->toThrow(CheckRefused::class, CheckRefused::WRONG);
    }

    expect(fn () => $checks->verify($request, $wrong))->toThrow(CheckRefused::class, CheckRefused::LOCKED)
        ->and($request->refresh()->locked_at)->not->toBeNull()
        ->and(fn () => $checks->sendCode($request))->toThrow(CheckRefused::class, CheckRefused::LOCKED);
});

it('binds a passed check to the browser session and the token for thirty minutes', function () {
    $sent = s3Send($this, 'ACCESS_CODE', 'rätt-kod-123');
    auth()->logout();

    $this->post("/signera/{$sent['token']}/kontroll", ['code' => 'rätt-kod-123']);
    $this->get("/signera/{$sent['token']}")->assertSee('Konsulttimme');

    $this->travel(31)->minutes();
    $this->get("/signera/{$sent['token']}")->assertSee('Bekräfta att det är du')->assertDontSee('Konsulttimme');

    $this->post("/signera/{$sent['token']}/kontroll", ['code' => 'rätt-kod-123']);
    $this->flushSession();
    $this->get("/signera/{$sent['token']}")->assertSee('Bekräfta att det är du');
});

it('throttles the check per signer link and per IP address', function () {
    $sent = s3Send($this, 'ACCESS_CODE', 'rätt-kod-123');
    auth()->logout();
    RateLimiter::clear('signing-verify:signer:'.hash('sha256', $sent['token']));

    foreach (range(1, 10) as $try) {
        $this->post("/signera/{$sent['token']}/kontroll", ['code' => 'x'])->assertRedirect();
    }

    $this->post("/signera/{$sent['token']}/kontroll", ['code' => 'rätt-kod-123'])->assertStatus(429);
});

it('keeps the check columns and code rows fixed by trigger', function () {
    $sent = s3Send($this, 'EMAIL_CODE');
    $request = $sent['request'];
    app(SignerChecks::class)->sendCode($request);
    $code = s3MailedCode();
    $row = SigningVerificationCode::query()->sole();

    expect(fn () => DB::transaction(fn () => DB::table('crm_signing_requests')->where('id', $request->id)->update(['verification' => 'NONE'])))->toThrow(QueryException::class, 'cannot be changed after sending')
        ->and(fn () => DB::transaction(fn () => DB::table('signing_verification_codes')->where('id', $row->id)->update(['code_mac' => str_repeat('a', 64)])))->toThrow(QueryException::class, 'cannot be changed')
        ->and(fn () => DB::transaction(fn () => DB::table('signing_verification_codes')->where('id', $row->id)->update(['expires_at' => now()->addDay()])))->toThrow(QueryException::class, 'cannot be changed');

    app(SignerChecks::class)->verify($request, $code);
    expect(fn () => DB::transaction(fn () => DB::table('signing_verification_codes')->where('id', $row->id)->update(['verified_at' => null])))->toThrow(QueryException::class, 'used signing code');

    $links = app(SignAtLink::class);
    $links->sign($links->find($sent['token']), s3SignInput($request->refresh()), '203.0.113.7', 'Pest');

    expect(fn () => DB::transaction(fn () => DB::table('crm_signing_requests')->where('id', $request->id)->update(['verified_at' => now()->addDay()])))->toThrow(QueryException::class, 'cannot be changed');
});

it('puts the check on the signing certificate as a simple electronic signature, never more', function () {
    $sent = s3Send($this, 'ACCESS_CODE', 'rätt-kod-123');
    app(SignerChecks::class)->verify($sent['request'], 'rätt-kod-123');
    $links = app(SignAtLink::class);
    $signed = $links->sign($links->find($sent['token']), s3SignInput($sent['request']->refresh()), '203.0.113.7', 'Pest');

    expect($signed->signed_document_path)->not->toBeNull();

    $html = view('signing.certificate', ['request' => $signed->load('events'), 'company' => $this->company, 'brand' => Brand::for($this->company), 'title' => 'Offert'])->render();

    expect($html)->toContain('Kontroll av undertecknaren')
        ->toContain('Åtkomstkod från avsändaren')
        ->toContain('Undertecknaren klarade kontrollen')
        ->toContain('fortfarande en enkel elektronisk underskrift')
        ->not->toContain('rätt-kod-123')
        ->not->toContain('avancerad');
});

it('checks each signer of an envelope with the check the sender chose for them', function () {
    $envelope = app(SendForSigning::class)->envelope($this->owner, $this->company, $this->quote, ['signers' => [
        ['email' => 'anna@kontorshuset.test', 'step' => 1, 'verification' => 'EMAIL_CODE'],
        ['email' => 'bo@kontorshuset.test', 'step' => 1, 'verification' => 'NONE'],
        ['email' => 'cia@kontorshuset.test', 'step' => 2, 'verification' => 'ACCESS_CODE', 'access_code' => 'cias-kod-77'],
    ]]);

    $signers = SigningRequest::query()->where('envelope_id', $envelope->id)->orderBy('id')->get();
    expect($signers->pluck('verification')->map->value->all())->toBe(['EMAIL_CODE', 'NONE', 'ACCESS_CODE'])
        ->and(Hash::check('cias-kod-77', (string) $signers[2]->access_code_hash))->toBeTrue();

    expect(fn () => app(SendForSigning::class)->envelope($this->owner, $this->company, $this->quote, ['signers' => [
        ['email' => 'anna@kontorshuset.test', 'step' => 1, 'verification' => 'ACCESS_CODE', 'access_code' => 'kort'],
        ['email' => 'bo@kontorshuset.test', 'step' => 2],
    ]]))->toThrow(ValidationException::class);

    expect(fn () => app(SendForSigning::class)($this->owner, $this->company, $this->quote, ['email' => 'anna@kontorshuset.test', 'verification' => 'PROVIDER']))->toThrow(ValidationException::class);
});

it('sends with a check from the panel and clears the access code after sending', function () {
    Livewire::test(SigningPanel::class, ['quoteId' => $this->quote->id])
        ->assertSee('Kontroll av undertecknaren')
        ->set('email', 'anna@kontorshuset.test')
        ->set('verification', 'ACCESS_CODE')
        ->assertSee('Åtkomstkod')
        ->set('accessCode', 'panel-kod-9')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSet('accessCode', '')
        ->assertSee('Kontroll: Åtkomstkod som du lämnar själv');

    expect(SigningRequest::query()->sole()->verification)->toBe(SignerCheck::AccessCode);
});

it('lists signers with their checks in a fixed number of queries', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(SigningPanel::class, ['quoteId' => $this->quote->id]);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };
    $send = fn (int $signers) => app(SendForSigning::class)->envelope($this->owner, $this->company, $this->quote, ['signers' => array_map(fn (int $i): array => ['email' => "signer{$i}-{$signers}@kontorshuset.test", 'step' => $i, 'verification' => $i % 2 === 0 ? 'EMAIL_CODE' : 'ACCESS_CODE', 'access_code' => 'kod-123456'], range(1, $signers))]);

    $send(2);
    $count();
    $short = $count();
    $send(10);

    expect($count())->toBe($short);
});

it('never unlocks another company\'s signer', function () {
    $sent = s3Send($this, 'ACCESS_CODE', 'rätt-kod-123');
    [$other, $otherCompany] = Bookkeeping::company();

    expect(fn () => app(SignerChecks::class)->unlock($other, $otherCompany, $sent['request']))->toThrow(HttpException::class);
});

it('refuses weak access codes: short digit-only codes, common codes and codes past bcrypt\'s 72 bytes', function () {
    foreach (['1234567', '12345678', 'PassWord1', 'Sommar 2026', str_repeat('å', 37)] as $weak) {
        expect(fn () => s3Send($this, 'ACCESS_CODE', $weak))->toThrow(ValidationException::class);
    }

    // Eight digits that are not on the list, or letters mixed in, are allowed.
    expect(s3Send($this, 'ACCESS_CODE', '58209371')['request']->verification)->toBe(SignerCheck::AccessCode)
        ->and(s3Send($this, 'ACCESS_CODE', 'blå-hund-42')['request']->verification)->toBe(SignerCheck::AccessCode);

    // A typed code longer than 72 bytes is refused before bcrypt, never counted as a try.
    $sent = s3Send($this, 'ACCESS_CODE', 'rätt-kod-123');
    expect(fn () => app(SignerChecks::class)->verify($sent['request'], str_repeat('å', 40)))->toThrow(ValidationException::class)
        ->and($sent['request']->refresh()->verification_failures)->toBe(0);
});

it('suggests a random ten-character access code in the panel and sends with it', function () {
    $panel = Livewire::test(SigningPanel::class, ['quoteId' => $this->quote->id])
        ->set('email', 'anna@kontorshuset.test')
        ->set('verification', 'ACCESS_CODE')
        ->assertSee('Föreslå kod')
        ->assertSee('personnummer')
        ->call('suggestCode');

    $code = $panel->get('accessCode');
    expect($code)->toMatch('/^[a-km-zA-HJ-NP-Z2-9]{10}$/')
        ->and($panel->call('suggestCode')->get('accessCode'))->not->toBe($code);

    $panel->call('send')->assertHasNoErrors()->assertSet('accessCode', '');
    expect(SigningRequest::query()->sole()->verification)->toBe(SignerCheck::AccessCode);

    $several = Livewire::test(SigningPanel::class, ['quoteId' => $this->quote->id])->call('severalSigners')
        ->set('signers.0.verification', 'ACCESS_CODE')
        ->call('suggestSignerCode', 0);
    expect($several->get('signers.0.access_code'))->toMatch('/^[a-km-zA-HJ-NP-Z2-9]{10}$/');
});

it('records OPENED only after a passed check', function () {
    $sent = s3Send($this, 'ACCESS_CODE', 'rätt-kod-123');
    auth()->logout();

    $this->get("/signera/{$sent['token']}")->assertSee('Bekräfta att det är du');
    CurrentCompany::set($this->company);
    expect(s3Events($sent['request']))->toBe(['SENT'])
        ->and($sent['request']->refresh()->opened_at)->toBeNull();

    $this->post("/signera/{$sent['token']}/kontroll", ['code' => 'rätt-kod-123']);
    $this->get("/signera/{$sent['token']}")->assertSee('Konsulttimme');
    CurrentCompany::set($this->company);
    expect(s3Events($sent['request']))->toBe(['SENT', 'VERIFIED', 'OPENED']);
});

it('spends a code whose mail failed, so the next click sends a new one', function () {
    $sent = s3Send($this, 'EMAIL_CODE');
    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('smtp down'));

    expect(fn () => app(SignerChecks::class)->sendCode($sent['request']))->toThrow(CheckRefused::class, CheckRefused::NOT_SENT);

    $row = SigningVerificationCode::query()->sole();
    expect($row->attempts)->toBe($row->max_attempts)->and($row->isOpen())->toBeFalse();

    // The facade holds the throwing mock now; a fresh fake replaces it.
    Mail::swap(new MailFake(new MailManager(app())));
    expect(app(SignerChecks::class)->sendCode($sent['request']))->toBe(SignerChecks::SENT);
    Mail::assertSent(SigningCodeMail::class, 1);
});

it('answers NOT_SENT when the send lock cannot be had', function () {
    $sent = s3Send($this, 'EMAIL_CODE');
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')->andThrow(new LockTimeoutException);
    Cache::shouldReceive('lock')->andReturn($lock);

    expect(fn () => app(SignerChecks::class)->sendCode($sent['request']))->toThrow(CheckRefused::class, CheckRefused::NOT_SENT);
    Mail::assertNotSent(SigningCodeMail::class);
});

it('tells the signer of a test company that no code can be sent, and mails nothing', function () {
    $this->company->forceFill(['is_test_company' => true])->save();
    $sent = s3Send($this, 'EMAIL_CODE');
    auth()->logout();

    $this->get("/signera/{$sent['token']}")->assertOk()
        ->assertSee('Det här är ett testföretag i NONOX')
        ->assertDontSee('Skicka kod')
        ->assertDontSee('Konsulttimme');
    $this->post("/signera/{$sent['token']}/kod")->assertSessionHasErrors('code');

    CurrentCompany::set($this->company);
    expect(SigningVerificationCode::query()->count())->toBe(0);
    Mail::assertNotSent(SigningCodeMail::class);
});
