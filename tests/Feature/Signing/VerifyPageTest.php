<?php

declare(strict_types=1);

use App\Actions\Hq\UpdateInstanceSettings;
use App\Actions\Signing\SendForSigning;
use App\Actions\Signing\SignAtLink;
use App\Domain\Signing\Pdf\FakePdfEngine;
use App\Domain\Signing\Seal\SealCertificates;
use App\Domain\Signing\SigningPdfs;
use App\Domain\Signing\Verify\VerifyLink;
use App\Mail\SigningRequestMail;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\InstanceSetting;
use App\Models\SigningDocumentVersion;
use App\Models\SigningEnvelope;
use App\Models\SigningRequest;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Signing\Actions\StoreSigningDocument;
use App\Modules\Signing\Models\SigningDocument;
use App\Modules\Signing\SigningModule;
use App\Support\Audit\Audit;
use App\Support\Instance\InstanceSettings;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;
use Tests\Support\Bookkeeping;
use Tests\Support\PdfEngineFixtures;
use Tests\Support\SigningConsentFixture;

/*
 * The public Verify page /kontrollera (ADR-046 amendment, gap 6, S12): off
 * by default, a hash in and a constant-shaped answer out, nothing personal,
 * throttled, never logged, with a QR code on the certificate and the summary
 * only while it is on.
 */

function s12Png(): string
{
    $image = imagecreatetruecolor(300, 100);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
    imageline($image, 10, 50, 290, 60, (int) imagecolorallocate($image, 0, 0, 0));
    ob_start();
    imagepng($image);

    return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
}

function s12Store(User $user, Company $company): SigningDocument
{
    $path = tempnam(sys_get_temp_dir(), 's12');
    file_put_contents($path, PdfEngineFixtures::pdf(2).'%'.bin2hex(random_bytes(8)));

    try {
        return app(StoreSigningDocument::class)($user, $company, $path, 'Avtal 2026.pdf', 'Samarbetsavtal');
    } finally {
        @unlink($path);
    }
}

/** @param  list<array<string, mixed>>  $signers */
function s12Send(User $user, Company $company, array $signers, array $extra = []): SigningEnvelope
{
    return app(SendForSigning::class)->envelope($user, $company, s12Store($user, $company), ['signers' => $signers, ...$extra], minSigners: 1);
}

function s12Request(string $email): SigningRequest
{
    $url = null;

    Mail::assertQueued(SigningRequestMail::class, function (SigningRequestMail $mail) use ($email, &$url): bool {
        if ($mail->hasTo($email)) {
            $url = $mail->url;
        }

        return true;
    });

    return app(SignAtLink::class)->find(basename((string) parse_url((string) $url, PHP_URL_PATH)));
}

function s12Sign(string $email, string $name = 'Anna Andersson'): SigningRequest
{
    $links = app(SignAtLink::class);
    $request = s12Request($email);
    SigningConsentFixture::give($request);

    return $links->sign($request, ['name' => $name, 'accept' => '1', 'method' => 'DRAWN', 'signature' => s12Png(), 'document_sha256' => $request->document_sha256, ...SigningPdfs::acceptanceFields($request)], '203.0.113.7', 'Pest');
}

function s12Switch(bool $on = true, string $key = InstanceSettings::SIGNING_VERIFY_ENABLED): void
{
    InstanceSetting::query()->updateOrCreate(['key' => $key], ['value' => $on]);
    InstanceSettings::current()->forget();
}

/** @return array<string, mixed> */
function s12Check(mixed $test, string $hash, int $status = 200): array
{
    return $test->postJson('/kontrollera', ['sha256' => $hash])->assertStatus($status)->json();
}

beforeEach(function () {
    $this->travelTo(now()->addMinutes(10)->startOfMinute());
    Storage::fake('local');
    Mail::fake();
    FakePdfEngine::install();
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    app(ModuleManager::class)->enable(SigningModule::KEY, $this->company, $this->owner);
    config(['nonox.signing.timestamp.urls' => []]);
    SealCertificates::forget();
    InstanceSetting::query()->whereIn('key', [InstanceSettings::SIGNING_VERIFY_ENABLED, InstanceSettings::SIGNING_SEAL_ENABLED])->delete();
    InstanceSettings::current()->forget();
    RateLimiter::clear('signing-verify-page:127.0.0.1');
    $this->company->forceFill(['legal_name' => 'Snickeri Demo AB'])->save();
});

it('does not exist while it is switched off, the default', function () {
    expect(InstanceSettings::current()->signingVerifyEnabled())->toBeFalse();

    $this->get('/kontrollera')->assertNotFound();
    $this->postJson('/kontrollera', ['sha256' => str_repeat('a', 64)])->assertNotFound();
});

it('shows the page with a strict policy, a nameless file input and no script from anywhere else', function () {
    s12Switch();
    auth()->logout();

    $response = $this->get('/kontrollera')->assertOk()
        ->assertSee('Kontrollera ett signerat dokument')
        ->assertSee('Filen lämnar aldrig din enhet')
        ->assertSee('The file never leaves your device')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
        ->assertHeader('Referrer-Policy', 'no-referrer');
    $policy = (string) $response->headers->get('Content-Security-Policy');
    $html = $response->getContent();

    expect($policy)->toContain("script-src 'self'")->toContain("connect-src 'self'")->toContain("frame-ancestors 'none'")->toContain("script-src 'self'; style-src")
        ->and(preg_match('/<input[^>]*type="file"[^>]*>/', $html, $input))->toBe(1)
        ->and($input[0])->not->toContain(' name=')
        ->and(preg_match_all('/<script\b[^>]*>(.*?)<\/script>/s', $html, $scripts))->toBeGreaterThanOrEqual(0)
        ->and(implode('', $scripts[1]))->toBe('')
        ->and($html)->not->toContain('<form');

    preg_match_all('/<script[^>]+src="([^"]+)"/', $html, $sources);
    foreach ($sources[1] as $source) {
        expect($source)->toStartWith(url('/'));
    }
});

it('finds the original, the signed file and the sealed file, and says only what the amendment lists', function () {
    s12Switch();
    s12Switch(true, InstanceSettings::SIGNING_SEAL_ENABLED);
    $instance = tempnam(sys_get_temp_dir(), 's12p12');
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    $csr = openssl_csr_new(['commonName' => 'NONOX test'], $key, ['digest_alg' => 'sha256']);
    openssl_pkcs12_export(openssl_csr_sign($csr, null, $key, 30, ['digest_alg' => 'sha256']), $pkcs12, $key, 'pw');
    file_put_contents($instance, $pkcs12);
    config(['nonox.signing.seal.instance_pkcs12' => $instance, 'nonox.signing.seal.instance_password' => 'pw']);
    SealCertificates::forget();

    $envelope = s12Send($this->owner, $this->company, [['email' => 'anna@example.test', 'step' => 1, 'verification' => 'NONE']]);
    s12Sign('anna@example.test');
    $envelope->refresh();
    $sealed = SigningDocumentVersion::query()->where('envelope_id', $envelope->id)->where('kind', SigningDocumentVersion::SEALED)->first();
    auth()->logout();

    $original = s12Check($this, $envelope->document_sha256);
    $json = json_encode($original, JSON_UNESCAPED_UNICODE);

    expect($original['found'])->toBeTrue()
        ->and($original['heading'])->toBe('Filen är oförändrad')
        ->and($json)->toContain('Snickeri Demo AB')->toContain('Samarbetsavtal')->toContain('Originalet som skickades för signering')
        ->toContain('Signerat av alla')->toContain('1 av 1 har signerat')->toContain('Ingen kontroll utöver länken (1)')->toContain('PAdES B-B')->toContain('Har inte kontrollerats än')
        ->not->toContain('anna@example.test')->not->toContain('Anna Andersson')->not->toContain('203.0.113.7')->not->toContain('Pest')->not->toContain($envelope->id);

    @unlink($instance);

    expect($sealed)->toBeInstanceOf(SigningDocumentVersion::class)
        ->and(json_encode(s12Check($this, $sealed->sha256), JSON_UNESCAPED_UNICODE))->toContain('Den förseglade filen')->toContain('PAdES');
});

it('does not answer for an envelope that is still out, not even for the file after a signer', function () {
    s12Switch();
    $envelope = s12Send($this->owner, $this->company, [['email' => 'anna@example.test', 'step' => 1, 'verification' => 'NONE'], ['email' => 'bo@example.test', 'step' => 2, 'verification' => 'NONE']]);
    s12Sign('anna@example.test');
    $hashes = SigningDocumentVersion::query()->where('envelope_id', $envelope->id)->pluck('sha256')->all();
    auth()->logout();

    expect($envelope->refresh()->status->value)->toBe('OUT')->and($hashes)->not->toBeEmpty();

    foreach ($hashes as $hash) {
        expect(s12Check($this, $hash)['found'])->toBeFalse();
    }
});

it('answers a declined and a withdrawn envelope, each stated', function () {
    s12Switch();
    $declined = s12Send($this->owner, $this->company, [['email' => 'anna@example.test', 'step' => 1, 'verification' => 'NONE']]);
    app(SignAtLink::class)->decline(s12Request('anna@example.test'), 'Nej');
    $voided = s12Send($this->owner, $this->company, [['email' => 'bo@example.test', 'step' => 1, 'verification' => 'NONE']]);
    app(SendForSigning::class)->void($this->owner, $this->company, $voided);
    auth()->logout();

    expect(json_encode(s12Check($this, $declined->document_sha256), JSON_UNESCAPED_UNICODE))->toContain('Avböjt, ')->not->toContain('Nej')
        ->and(json_encode(s12Check($this, $voided->document_sha256), JSON_UNESCAPED_UNICODE))->toContain('Tillbakadraget, ');
});

it('answers for an archived company and with the module off', function () {
    s12Switch();
    $envelope = s12Send($this->owner, $this->company, [['email' => 'anna@example.test', 'step' => 1, 'verification' => 'NONE']]);
    s12Sign('anna@example.test');
    app(ModuleManager::class)->disable(SigningModule::KEY, $this->company, $this->owner, true);
    $this->company->forceFill(['archived_at' => now()])->save();
    auth()->logout();

    expect(s12Check($this, $envelope->refresh()->document_sha256)['found'])->toBeTrue();
});

it('hides the title of a confidential envelope', function () {
    s12Switch();
    $envelope = s12Send($this->owner, $this->company, [['email' => 'anna@example.test', 'step' => 1, 'verification' => 'NONE']], ['confidential' => true]);
    s12Sign('anna@example.test');
    auth()->logout();

    $json = json_encode(s12Check($this, $envelope->refresh()->document_sha256), JSON_UNESCAPED_UNICODE);

    expect($json)->toContain('Konfidentiellt dokument')->not->toContain('Samarbetsavtal');
});

it('says a deviation when the nightly check found the envelope changed', function () {
    s12Switch();
    $envelope = s12Send($this->owner, $this->company, [['email' => 'anna@example.test', 'step' => 1, 'verification' => 'NONE']]);
    s12Sign('anna@example.test');
    $this->artisan('signing:verify')->assertSuccessful();
    auth()->logout();

    expect(json_encode(s12Check($this, $envelope->refresh()->document_sha256), JSON_UNESCAPED_UNICODE))->toContain('Oförändrad vid kontrollen');

    Audit::record('signing.verified', $this->company, ['ok' => false, 'problems' => [['envelope_id' => $envelope->id, 'code' => 'HASH']]], $this->company);

    expect(json_encode(s12Check($this, $envelope->document_sha256), JSON_UNESCAPED_UNICODE))->toContain('Avvikelse vid kontrollen');
});

it('answers a miss in the same shape as a hit', function () {
    s12Switch();
    $envelope = s12Send($this->owner, $this->company, [['email' => 'anna@example.test', 'step' => 1, 'verification' => 'NONE']]);
    s12Sign('anna@example.test');
    auth()->logout();

    $hit = s12Check($this, $envelope->refresh()->document_sha256);
    $miss = s12Check($this, hash('sha256', 'ingen sådan fil'));

    expect($miss['found'])->toBeFalse()
        ->and($miss['heading'])->toBe('Filen finns inte hos NONOX')
        ->and(array_keys($miss))->toBe(array_keys($hit))
        ->and($miss['lines'])->each->toHaveCount(2)
        ->and(json_encode($miss))->not->toContain('Snickeri');
});

it('accepts exactly 64 lowercase hex characters in a body of its own and nothing else', function () {
    s12Switch();
    $hash = hash('sha256', 'x');

    foreach ([strtoupper($hash), substr($hash, 1), $hash.'0', $hash."\n", 'g'.substr($hash, 1), ''] as $bad) {
        s12Check($this, $bad, 422);
    }

    $this->postJson('/kontrollera', ['sha256' => $hash, 'name' => 'x.pdf'])->assertStatus(422);
    $this->postJson('/kontrollera', ['sha256' => [$hash]])->assertStatus(422);
    $this->postJson('/kontrollera', [])->assertStatus(422);
    $this->postJson('/kontrollera', ['sha256' => $hash])->assertOk();
});

it('throttles twenty checks a minute per IP address', function () {
    s12Switch();
    $hash = hash('sha256', 'x');

    foreach (range(1, 20) as $i) {
        $this->postJson('/kontrollera', ['sha256' => $hash])->assertOk();
    }

    $this->postJson('/kontrollera', ['sha256' => $hash])->assertStatus(429);
    $this->postJson('/kontrollera', ['sha256' => $hash], ['REMOTE_ADDR' => '198.51.100.9'])->assertOk();
});

it('keeps no trace of the hash or the visitor: no audit entry and nothing in the log', function () {
    s12Switch();
    auth()->logout();
    $before = AuditLog::query()->count();
    $hash = hash('sha256', 'hemlig');

    $this->postJson('/kontrollera', ['sha256' => $hash])->assertOk();
    $this->get('/kontrollera')->assertOk();

    expect(AuditLog::query()->count())->toBe($before)
        ->and(DB::table('audit_logs')->where('payload', 'like', "%{$hash}%")->exists())->toBeFalse();
});

it('uses a fixed number of queries whatever the number of signers', function () {
    s12Switch();
    $one = s12Send($this->owner, $this->company, [['email' => 'a1@example.test', 'step' => 1, 'verification' => 'NONE']]);
    s12Sign('a1@example.test');
    $many = s12Send($this->owner, $this->company, [['email' => 'b1@example.test', 'step' => 1, 'verification' => 'NONE'], ['email' => 'b2@example.test', 'step' => 1, 'verification' => 'NONE'], ['email' => 'b3@example.test', 'step' => 2, 'verification' => 'NONE']]);
    s12Sign('b1@example.test', 'Bo');
    s12Sign('b2@example.test', 'Cia');
    s12Sign('b3@example.test', 'Dan');
    auth()->logout();

    $count = function (string $hash): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->postJson('/kontrollera', ['sha256' => $hash])->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $miss = $count(hash('sha256', 'saknas'));
    $oneQueries = $count($one->refresh()->document_sha256);
    $manyQueries = $count($many->refresh()->document_sha256);

    expect($oneQueries)->toBe($manyQueries)
        ->and($miss)->toBeLessThan($oneQueries)
        ->and($oneQueries)->toBeLessThanOrEqual($miss + 5);
});

it('puts a QR code and the link on the certificate and the summary only while the page is on', function () {
    $envelope = s12Send($this->owner, $this->company, [['email' => 'anna@example.test', 'step' => 1, 'verification' => 'NONE'], ['email' => 'bo@example.test', 'step' => 1, 'verification' => 'NONE']]);
    $anna = s12Sign('anna@example.test');
    s12Sign('bo@example.test', 'Bo');
    $envelope->refresh();

    $text = fn (string $pdf): string => preg_replace('/\s+/u', ' ', (new Parser)->parseContent($pdf)->getText());

    expect(VerifyLink::url($envelope->document_sha256))->toBeNull()
        ->and($text(SigningPdfs::certificate($anna->refresh())))->not->toContain('/kontrollera')
        ->and($text(SigningPdfs::envelopeSummary($envelope)))->not->toContain('/kontrollera');

    s12Switch();
    $url = route('signing.verifyPage').'#sha256='.$envelope->document_sha256;

    expect(VerifyLink::url($envelope->document_sha256))->toBe($url)
        ->and(VerifyLink::url('nope'))->toBeNull()
        ->and(VerifyLink::qrDataUri($envelope->document_sha256))->toStartWith('data:image/svg+xml;base64,')
        ->and($text(SigningPdfs::certificate($anna->refresh())))->toContain('Kontrollera att filen är oförändrad')->toContain('#sha256='.substr($envelope->document_sha256, 0, 16))
        ->and($text(SigningPdfs::envelopeSummary($envelope)))->toContain('Check that the file is unchanged');
});

it('switches the page on and off in /hq', function () {
    $admin = User::factory()->create(['email' => 'hq-s12@example.test']);
    config(['nonox.hq_admins' => ['hq-s12@example.test'], 'app.admin_email' => null]);

    $changed = app(UpdateInstanceSettings::class)($admin, ['waitlist_open' => true, 'registration_open' => false, 'signing_verify_enabled' => true]);

    expect($changed)->toBe([InstanceSettings::SIGNING_VERIFY_ENABLED])
        ->and(InstanceSettings::current()->signingVerifyEnabled())->toBeTrue()
        ->and(InstanceSettings::current()->signingSealEnabled())->toBeFalse();
});
