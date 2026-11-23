<?php

declare(strict_types=1);

use App\Actions\Hq\UpdateInstanceSettings;
use App\Actions\Signing\ManageSealCertificates;
use App\Actions\Signing\SendForSigning;
use App\Actions\Signing\SignAtLink;
use App\Domain\Signing\Chain\ChainVerifier;
use App\Domain\Signing\CompletionFiles;
use App\Domain\Signing\Fields\SigningFields;
use App\Domain\Signing\Pdf\FakePdfEngine;
use App\Domain\Signing\Pdf\PdfEngineException;
use App\Domain\Signing\Pdf\PdfEngineFailure;
use App\Domain\Signing\Pdf\SealLevel;
use App\Domain\Signing\Seal\EnvelopeSealer;
use App\Domain\Signing\Seal\SealCertificates;
use App\Domain\Signing\Seal\SealStatus;
use App\Domain\Signing\SigningPdfs;
use App\Jobs\SealSigningEnvelope;
use App\Livewire\Company\SigningSealSettings;
use App\Mail\SignedDocumentMail;
use App\Mail\SigningEnvelopeMail;
use App\Mail\SigningRequestMail;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\InstanceSetting;
use App\Models\SigningDocumentVersion;
use App\Models\SigningEnvelope;
use App\Models\SigningRequest;
use App\Models\SigningSealCertificate;
use App\Models\Task;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Signing\Actions\StoreSigningDocument;
use App\Modules\Signing\Livewire\EnvelopePage;
use App\Modules\Signing\Models\SigningDocument;
use App\Modules\Signing\SigningModule;
use App\Notifications\SigningNotice;
use App\Support\Branding\Brand;
use App\Support\Instance\InstanceSettings;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\PdfEngineFixtures;
use Tests\Support\SigningConsentFixture;

/*
 * The PAdES seal (ADR-046 amendment 2026-11-18, gap 5, package S10): off by
 * default, the finished file with the summary page appended and sealed
 * through the PDF engine (the fake one here; Python is never needed), B-B
 * without and B-T with a timestamp server, a day of retries when no server
 * answers, the completion mails waiting for the seal, a company's own PKCS#12
 * (encrypted, never shown), the instance's self-signed seal, the expiry to-do
 * and the settings page. Every certificate is made at runtime.
 */

/** @return array{pkcs12: string, password: string} */
function s10Pkcs12(int $days = 365, int $bits = 2048, string $name = 'Snickeri Demo AB försegling'): array
{
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => $bits]);
    $csr = openssl_csr_new(['commonName' => $name, 'organizationName' => 'Snickeri Demo AB'], $key, ['digest_alg' => 'sha256']);
    $certificate = openssl_csr_sign($csr, null, $key, $days, ['digest_alg' => 'sha256']);
    $password = bin2hex(random_bytes(8));
    openssl_pkcs12_export($certificate, $pkcs12, $key, $password);

    return ['pkcs12' => (string) $pkcs12, 'password' => $password];
}

function s10Png(): string
{
    $image = imagecreatetruecolor(300, 100);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
    imageline($image, 10, 50, 290, 60, (int) imagecolorallocate($image, 0, 0, 0));
    ob_start();
    imagepng($image);

    return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
}

function s10Store(User $user, Company $company): SigningDocument
{
    $path = tempnam(sys_get_temp_dir(), 's10');
    file_put_contents($path, PdfEngineFixtures::pdf(2));

    try {
        return app(StoreSigningDocument::class)($user, $company, $path, 'Avtal 2026.pdf', 'Samarbetsavtal');
    } finally {
        @unlink($path);
    }
}

/** @param  list<array<string, mixed>>  $signers */
function s10Send(User $user, Company $company, array $signers, array $extra = []): SigningEnvelope
{
    return app(SendForSigning::class)->envelope($user, $company, s10Store($user, $company), ['signers' => $signers, ...$extra], minSigners: 1);
}

function s10Sign(string $email, string $name = 'Anna Andersson'): SigningRequest
{
    $url = null;

    Mail::assertQueued(SigningRequestMail::class, function (SigningRequestMail $mail) use ($email, &$url): bool {
        if ($mail->hasTo($email)) {
            $url = $mail->url;
        }

        return true;
    });

    $links = app(SignAtLink::class);
    $request = $links->find(basename((string) parse_url((string) $url, PHP_URL_PATH)));
    SigningConsentFixture::give($request);

    return $links->sign($request, ['name' => $name, 'accept' => '1', 'method' => 'DRAWN', 'signature' => s10Png(), 'document_sha256' => $request->document_sha256, ...SigningPdfs::acceptanceFields($request)], '203.0.113.7', 'Pest');
}

/** @return list<SignedDocumentMail|SigningEnvelopeMail> */
function s10Mails(string $email): array
{
    $found = [];

    foreach ([SignedDocumentMail::class, SigningEnvelopeMail::class] as $class) {
        foreach (Mail::queued($class) as $mail) {
            if ($mail->hasTo($email)) {
                $found[] = $mail;
            }
        }
    }

    return $found;
}

function s10SwitchOn(bool $on = true): void
{
    InstanceSetting::query()->updateOrCreate(['key' => InstanceSettings::SIGNING_SEAL_ENABLED], ['value' => $on]);
    InstanceSettings::current()->forget();
}

beforeEach(function () {
    // The certificates are made by OpenSSL with the real clock, so the test clock starts just after it.
    $this->travelTo(CarbonImmutable::now()->addMinutes(10)->startOfMinute());
    Storage::fake('local');
    Mail::fake();
    $this->engine = FakePdfEngine::install();
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    app(ModuleManager::class)->enable(SigningModule::KEY, $this->company, $this->owner);
    $this->company->forceFill(['email' => 'info@foretaget.test'])->save();

    // The instance seal: made at runtime, never committed.
    $this->sealDir = sys_get_temp_dir().'/nonox-s10-'.bin2hex(random_bytes(6));
    mkdir($this->sealDir, 0700);
    $instance = s10Pkcs12(name: 'NONOX test – försegling');
    file_put_contents($this->sealDir.'/instance.p12', $instance['pkcs12']);
    config(['nonox.signing.seal.instance_pkcs12' => $this->sealDir.'/instance.p12', 'nonox.signing.seal.instance_password' => $instance['password'], 'nonox.signing.timestamp.urls' => []]);
    SealCertificates::forget();
    InstanceSetting::query()->where('key', InstanceSettings::SIGNING_SEAL_ENABLED)->delete();
    InstanceSettings::current()->forget();
});

afterEach(function () {
    foreach (glob($this->sealDir.'/{,.}*', GLOB_BRACE) ?: [] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }

    @rmdir($this->sealDir);
    SealCertificates::forget();
});

it('is off by default: a completed envelope is not sealed and its mails go as before', function () {
    expect(InstanceSettings::current()->signingSealEnabled())->toBeFalse();

    $envelope = s10Send($this->owner, $this->company, [['email' => 'anna@example.test', 'step' => 1, 'verification' => 'NONE']]);
    s10Sign('anna@example.test');

    $envelope->refresh();
    expect($envelope->seal_status)->toBeNull()
        ->and($envelope->sealed_path)->toBeNull()
        ->and($this->engine->operations())->not->toContain('seal')
        ->and(s10Mails('anna@example.test'))->toHaveCount(1)
        ->and(s10Mails('info@foretaget.test'))->toHaveCount(1);
});

it('seals the finished file at PAdES B-B with the instance seal, keeps the signed bytes first, and mails the sealed file once', function () {
    s10SwitchOn();
    $envelope = s10Send($this->owner, $this->company, [['email' => 'anna@example.test', 'step' => 1, 'verification' => 'NONE']]);
    s10Sign('anna@example.test');

    $envelope->refresh();
    $disk = Storage::disk(SigningRequest::DISK);
    $sealed = (string) $disk->get((string) $envelope->sealed_path);

    expect($envelope->seal_status)->toBe(SealStatus::Sealed)
        ->and($envelope->seal_level)->toBe(SealLevel::B_B)
        ->and($envelope->seal_certificate_id)->toBeNull()
        ->and($envelope->seal_certificate_fingerprint)->toBe(SealCertificates::instance()?->info->fingerprintSha256)
        ->and($envelope->last_timestamp_at)->toBeNull()
        ->and($envelope->sealed_sha256)->toBe(hash('sha256', $sealed))
        ->and(str_starts_with($sealed, (string) $disk->get($envelope->document_path)))->toBeTrue()
        ->and($this->engine->operations())->toContain('append')->toContain('seal')
        ->and($this->engine->sealed)->toBe([['level' => 'B_B', 'timestampUrls' => [], 'fieldName' => EnvelopeSealer::FIELD_NAME]]);

    $version = SigningDocumentVersion::query()->where('envelope_id', $envelope->id)->where('kind', SigningDocumentVersion::SEALED)->sole();
    expect($version->path)->toBe($envelope->sealed_path)->and($version->sha256)->toBe($envelope->sealed_sha256);

    // Everyone gets the sealed file, once: the signer's own copy and the company's.
    $signer = SigningRequest::query()->where('envelope_id', $envelope->id)->sole();
    expect(s10Mails('anna@example.test'))->toHaveCount(1)
        ->and(s10Mails('info@foretaget.test'))->toHaveCount(1)
        ->and(CompletionFiles::forSigner($signer)[0]['path'])->toBe($envelope->sealed_path)
        ->and(SigningFields::finalVersion($envelope))->toBe($envelope->sealed_path)
        ->and(SigningFields::signedVersion($envelope))->toBe($envelope->document_path);

    // The audit entry holds ids and enums only, never the key or the password.
    $audit = AuditLog::query()->where('action', 'signing.sealed')->sole();
    expect($audit->payload)->toMatchArray(['envelope_id' => $envelope->id, 'version_id' => $version->id, 'level' => 'B_B', 'certificate' => 'INSTANCE', 'without_timestamp' => false])
        ->and(json_encode(AuditLog::query()->get()))->not->toContain((string) config('nonox.signing.seal.instance_password'));

    // The nightly chain check reads the sealed file like every other version.
    $report = app(ChainVerifier::class)->verify($this->company);
    expect($report['ok'])->toBeTrue()
        ->and($report['problems'])->toBe([]);

    // A sealed file changed on disk afterwards is found.
    $disk->put((string) $envelope->sealed_path, $sealed.'x');
    expect(collect(app(ChainVerifier::class)->verify($this->company)['problems'])->pluck('code')->all())->toContain('FILE_CHANGED');
});

it('seals at B-T with a configured timestamp server', function () {
    config(['nonox.signing.timestamp.urls' => ['https://tsa.example.test/tsr']]);
    s10SwitchOn();
    $envelope = s10Send($this->owner, $this->company, [['email' => 'anna@example.test', 'step' => 1, 'verification' => 'NONE']]);
    s10Sign('anna@example.test');

    $envelope->refresh();
    expect($envelope->seal_level)->toBe(SealLevel::B_T)
        ->and($envelope->last_timestamp_at?->toDateTimeString())->toBe(CarbonImmutable::now()->toDateTimeString())
        ->and($this->engine->sealed[0]['timestampUrls'])->toBe(['https://tsa.example.test/tsr']);
});

it('waits for a timestamp server for an hour by default, mails nothing meanwhile, then seals at B-B and tells the sender', function () {
    Notification::fake();
    config(['nonox.signing.timestamp.urls' => ['https://tsa.example.test/tsr']]);
    s10SwitchOn();
    $this->engine->failTimestampedSeal = new PdfEngineException(PdfEngineFailure::TimestampUnavailable);
    $envelope = s10Send($this->owner, $this->company, [['email' => 'anna@example.test', 'step' => 1, 'verification' => 'NONE']], ['copies' => [['email' => 'revisor@example.test']]]);
    s10Sign('anna@example.test');

    $envelope->refresh();
    expect($envelope->seal_status)->toBe(SealStatus::Pending)
        ->and(s10Mails('anna@example.test'))->toBe([])
        ->and(s10Mails('revisor@example.test'))->toBe([])
        ->and(s10Mails('info@foretaget.test'))->toBe([]);

    $sealer = app(EnvelopeSealer::class);
    $this->travel(50)->minutes();
    expect($sealer->attempt($envelope->id))->toBe(EnvelopeSealer::RETRY);

    $this->travel(15)->minutes();
    expect($sealer->attempt($envelope->id))->toBe(EnvelopeSealer::SEALED);

    $envelope->refresh();
    expect($envelope->seal_level)->toBe(SealLevel::B_B)
        ->and(s10Mails('anna@example.test'))->toHaveCount(1)
        ->and(s10Mails('revisor@example.test'))->toHaveCount(1)
        ->and(s10Mails('info@foretaget.test'))->toHaveCount(1)
        ->and(AuditLog::query()->where('action', 'signing.sealed')->sole()->payload['without_timestamp'])->toBeTrue();

    Notification::assertSentTo($this->owner, SigningNotice::class, fn (SigningNotice $notice): bool => str_contains($notice->sentence, 'utan tidsstämpel'));

    // Once only: a later attempt does nothing.
    expect($sealer->attempt($envelope->id))->toBe(EnvelopeSealer::NOTHING)
        ->and(s10Mails('anna@example.test'))->toHaveCount(1);
});

it('gives a permanent engine failure up: FAILED, the mails go with the unsealed file, the sender is told', function () {
    Notification::fake();
    s10SwitchOn();
    $envelope = s10Send($this->owner, $this->company, [['email' => 'anna@example.test', 'step' => 1, 'verification' => 'NONE']]);
    // The next engine call is the seal's (an envelope without fields stamps nothing).
    $this->engine->failNext = new PdfEngineException(PdfEngineFailure::BadCredentials);
    s10Sign('anna@example.test');

    $envelope->refresh();
    $signer = SigningRequest::query()->where('envelope_id', $envelope->id)->sole();
    expect($envelope->seal_status)->toBe(SealStatus::Failed)
        ->and($envelope->seal_failure)->toBe('bad_credentials')
        ->and($envelope->sealed_path)->toBeNull()
        ->and(s10Mails('anna@example.test'))->toHaveCount(1)
        ->and(s10Mails('info@foretaget.test'))->toHaveCount(1)
        ->and(CompletionFiles::forSigner($signer)[0]['path'])->toBe($envelope->document_path)
        ->and(AuditLog::query()->where('action', 'signing.seal_failed')->sole()->payload)->toEqualCanonicalizing(['envelope_id' => $envelope->id, 'failure' => 'bad_credentials']);

    Notification::assertSentTo($this->owner, SigningNotice::class, fn (SigningNotice $notice): bool => str_contains($notice->sentence, 'kunde inte förseglas'));
});

it('gives the seal up when the job dies, so the envelope never hangs', function () {
    s10SwitchOn();
    Queue::fake();
    $envelope = s10Send($this->owner, $this->company, [['email' => 'anna@example.test', 'step' => 1, 'verification' => 'NONE']]);
    s10Sign('anna@example.test');
    Queue::assertPushed(SealSigningEnvelope::class, fn (SealSigningEnvelope $job): bool => $job->envelopeId === $envelope->id && $job->companyId === $this->company->id);

    expect($envelope->refresh()->seal_status)->toBe(SealStatus::Pending)->and(s10Mails('anna@example.test'))->toBe([]);

    (new SealSigningEnvelope($this->company->id, $envelope->id))->failed();

    expect($envelope->refresh()->seal_status)->toBe(SealStatus::Failed)
        ->and($envelope->seal_failure)->toBe(EnvelopeSealer::JOB_FAILED)
        ->and(s10Mails('anna@example.test'))->toHaveCount(1);
});

it('queues a seal again that a worker lost, and the job releases itself while it waits', function () {
    s10SwitchOn();
    Queue::fake();
    $envelope = s10Send($this->owner, $this->company, [['email' => 'anna@example.test', 'step' => 1, 'verification' => 'NONE']]);
    s10Sign('anna@example.test');
    Queue::assertPushed(SealSigningEnvelope::class, 1);

    $this->artisan('signing:seal-check')->assertSuccessful();
    Queue::assertPushed(SealSigningEnvelope::class, 1);

    // While the first job holds its unique lock (waiting for a timestamp), the check stacks no second chain.
    $this->travel(16)->minutes();
    $this->artisan('signing:seal-check')->assertSuccessful();
    Queue::assertPushed(SealSigningEnvelope::class, 1);

    // A job that was lost for good (its lock gone) is queued again.
    (new UniqueLock(app(Cache::class)))->release(new SealSigningEnvelope($this->company->id, $envelope->id));
    $this->artisan('signing:seal-check')->assertSuccessful();
    Queue::assertPushed(SealSigningEnvelope::class, 2);

    $job = new SealSigningEnvelope($this->company->id, $envelope->id);
    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe($envelope->id)
        ->and($job->backoff())->toBe([60, 300, 900, 1800, 3600])
        ->and($job->uniqueFor())->toBe(4 * 3600);

    expect(SealSigningEnvelope::delay(1))->toBe(60)->and(SealSigningEnvelope::delay(3))->toBe(240)->and(SealSigningEnvelope::delay(30))->toBe(3600);
});

it('does not seal without the engine or without any certificate', function () {
    s10SwitchOn();
    $first = s10Send($this->owner, $this->company, [['email' => 'anna@example.test', 'step' => 1, 'verification' => 'NONE']]);
    $this->engine->installed = false;
    s10Sign('anna@example.test');
    expect($first->refresh()->seal_status)->toBeNull();

    $this->engine->installed = true;
    config(['nonox.signing.seal.instance_password' => '']);
    SealCertificates::forget();
    $second = s10Send($this->owner, $this->company, [['email' => 'bo@example.test', 'step' => 1, 'verification' => 'NONE']]);
    s10Sign('bo@example.test', 'Bo Berg');
    expect($second->refresh()->seal_status)->toBeNull();
});

it('does not seal when the installation requires the sandbox and the engine is not sandboxed, and gives a pending seal up at run time', function () {
    s10SwitchOn();
    config(['nonox.signing.pdf_engine.require_sandbox_for_seal' => true, 'nonox.signing.pdf_engine.sandbox' => 'none']);
    $first = s10Send($this->owner, $this->company, [['email' => 'anna@example.test', 'step' => 1, 'verification' => 'NONE']]);
    s10Sign('anna@example.test');
    expect($first->refresh()->seal_status)->toBeNull()
        ->and($this->engine->operations())->not->toContain('seal');

    // Configured for the sandbox, but the engine does not report that it runs in it: the job gives up.
    config(['nonox.signing.pdf_engine.sandbox' => 'bwrap']);
    $second = s10Send($this->owner, $this->company, [['email' => 'bo@example.test', 'step' => 1, 'verification' => 'NONE']]);
    $this->engine->failNext = null;
    s10Sign('bo@example.test', 'Bo Berg');

    expect($second->refresh()->seal_status)->toBe(SealStatus::Failed)
        ->and($second->seal_failure)->toBe(EnvelopeSealer::SANDBOX_REQUIRED)
        ->and($this->engine->operations())->not->toContain('seal');
});

it('mails the sealed file to every signer and copy recipient of an envelope with several signers, once, after the seal', function () {
    s10SwitchOn();
    $envelope = s10Send($this->owner, $this->company, [
        ['email' => 'anna@example.test', 'step' => 1, 'verification' => 'NONE'],
        ['email' => 'bo@example.test', 'step' => 2, 'verification' => 'NONE'],
    ], ['copies' => [['email' => 'revisor@example.test']]]);
    s10Sign('anna@example.test');
    s10Sign('bo@example.test', 'Bo Berg');

    $envelope->refresh();
    expect($envelope->seal_status)->toBe(SealStatus::Sealed);

    $copy = collect(s10Mails('revisor@example.test'))->sole();
    expect($copy)->toBeInstanceOf(SigningEnvelopeMail::class)
        ->and(CompletionFiles::forEnvelope($envelope, 'COPY')[0]['path'])->toBe($envelope->sealed_path);

    // Each signer: their own copy at signing, and the sealed envelope copy after the seal.
    expect(collect(s10Mails('bo@example.test'))->filter(fn ($mail): bool => $mail instanceof SigningEnvelopeMail))->toHaveCount(1)
        ->and(collect(s10Mails('anna@example.test'))->filter(fn ($mail): bool => $mail instanceof SigningEnvelopeMail))->toHaveCount(1)
        ->and(collect(s10Mails('info@foretaget.test'))->filter(fn ($mail): bool => $mail instanceof SigningEnvelopeMail && $mail->kind === SigningEnvelopeMail::COMPANY))->toHaveCount(1);
});

it('appends the summary page with the seal and no addresses, never a level', function () {
    s10SwitchOn();
    $envelope = s10Send($this->owner, $this->company, [['email' => 'anna@example.test', 'step' => 1, 'verification' => 'NONE']]);
    s10Sign('anna@example.test');

    $instance = SealCertificates::instance();
    $html = view('signing.summary', [
        'envelope' => $envelope->refresh(),
        'signers' => SigningRequest::query()->where('envelope_id', $envelope->id)->get(),
        'company' => $this->company,
        'brand' => Brand::for($this->company),
        'title' => (string) $envelope->title,
        'seal' => ['name' => $instance->info->commonName, 'issuer' => $instance->info->issuer, 'fingerprint' => $instance->info->fingerprintSha256, 'selfSigned' => true, 'source' => 'INSTANCE'],
    ])->render();

    expect($html)->toContain('Försegling')->toContain('Den är inte undertecknarnas underskrift')->toContain('okänd giltighet')
        ->toContain('Namn i certifikatet')->toContain('Självsignerat certifikat – namnet är inte kontrollerat')
        ->toContain('med vilket certifikat den förseglades')->not->toContain('vem som förseglade')
        ->toContain('Den som driver installationen intygar inte dokumentets innehåll')
        ->toContain($instance->info->fingerprintSha256)
        ->not->toContain('anna@example.test')->not->toContain('203.0.113.7')->not->toContain('B-B')->not->toContain('B-T');
});

it('keeps an envelope\'s seal moving forward only', function () {
    s10SwitchOn();
    $envelope = s10Send($this->owner, $this->company, [['email' => 'anna@example.test', 'step' => 1, 'verification' => 'NONE']]);
    s10Sign('anna@example.test');

    foreach ([
        ['seal_status' => 'PENDING'],
        ['sealed_path' => 'other.pdf'],
        ['sealed_sha256' => str_repeat('b', 64)],
        ['seal_level' => 'B_LTA'],
        ['seal_status' => null],
    ] as $change) {
        expect(fn () => DB::transaction(fn () => DB::table('signing_envelopes')->where('id', $envelope->id)->update($change)))->toThrow(QueryException::class);
    }

    // An envelope that is not completed is never marked for sealing.
    $open = s10Send($this->owner, $this->company, [['email' => 'bo@example.test', 'step' => 1, 'verification' => 'NONE']]);
    expect(fn () => DB::transaction(fn () => DB::table('signing_envelopes')->where('id', $open->id)->update(['seal_status' => 'PENDING'])))->toThrow(QueryException::class);
});

it('uploads a company PKCS#12, stores it encrypted and hidden, and seals with it', function () {
    s10SwitchOn();
    $own = s10Pkcs12();

    $certificate = app(ManageSealCertificates::class)->upload($this->owner, $this->company, $own['pkcs12'], $own['password']);

    $raw = (array) DB::table('signing_seal_certificates')->where('id', $certificate->id)->first();
    expect($certificate->subject)->toContain('CN=Snickeri Demo AB försegling')
        ->and($certificate->not_after->diffInDays(CarbonImmutable::now()))->toBeGreaterThan(-366)
        ->and($certificate->toArray())->not->toHaveKey('pkcs12')->not->toHaveKey('password')
        ->and(json_encode($certificate))->not->toContain($own['password'])
        ->and((string) $raw['pkcs12'])->not->toContain(base64_encode($own['pkcs12']))
        ->and((string) $raw['password'])->not->toContain($own['password'])
        ->and(base64_decode((string) $certificate->pkcs12))->toBe($own['pkcs12']);

    $audit = AuditLog::query()->where('action', 'signing.seal_certificate_uploaded')->sole();
    expect(array_keys($audit->payload))->toEqualCanonicalizing(['seal_certificate_id', 'replaced_id', 'not_after', 'self_signed'])
        ->and(json_encode($audit->payload))->not->toContain('Snickeri');

    $envelope = s10Send($this->owner, $this->company, [['email' => 'anna@example.test', 'step' => 1, 'verification' => 'NONE']]);
    s10Sign('anna@example.test');

    expect($envelope->refresh()->seal_certificate_id)->toBe($certificate->id)
        ->and($envelope->seal_certificate_fingerprint)->toBe($certificate->fingerprint_sha256);
});

it('refuses a wrong password, a weak key, an expired certificate and a file that is no PKCS#12', function () {
    $manage = app(ManageSealCertificates::class);
    $good = s10Pkcs12();

    expect(fn () => $manage->upload($this->owner, $this->company, $good['pkcs12'], 'fel'))->toThrow(ValidationException::class, 'Kontrollera lösenordet')
        ->and(fn () => $manage->upload($this->owner, $this->company, 'inte en fil', 'x'))->toThrow(ValidationException::class)
        ->and(fn () => $manage->upload($this->owner, $this->company, str_repeat('x', 70000), 'x'))->toThrow(ValidationException::class, '64 kB');

    $weak = s10Pkcs12(bits: 1024);
    expect(fn () => $manage->upload($this->owner, $this->company, $weak['pkcs12'], $weak['password']))->toThrow(ValidationException::class, 'för svag');

    $short = s10Pkcs12(days: 1);
    $this->travel(3)->days();
    expect(fn () => $manage->upload($this->owner, $this->company, $short['pkcs12'], $short['password']))->toThrow(ValidationException::class, 'gått ut');

    expect(SigningSealCertificate::query()->count())->toBe(0);
});

it('lets only signing:settings upload or retire a certificate', function () {
    $own = s10Pkcs12();
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');

    expect(fn () => app(ManageSealCertificates::class)->upload($bookkeeper, $this->company, $own['pkcs12'], $own['password']))->toThrow(AuthorizationException::class);

    $certificate = app(ManageSealCertificates::class)->upload($this->owner, $this->company, $own['pkcs12'], $own['password']);
    expect(fn () => app(ManageSealCertificates::class)->retire($bookkeeper, $this->company, $certificate->id))->toThrow(AuthorizationException::class);

    $this->actingAs($bookkeeper);
    Livewire::test(SigningSealSettings::class, ['company' => $this->company])->assertForbidden();
});

it('replaces a certificate by retiring the old one, whose key is emptied, and never edits one', function () {
    $manage = app(ManageSealCertificates::class);
    $first = s10Pkcs12();
    $second = s10Pkcs12(name: 'Ny försegling');

    $old = $manage->upload($this->owner, $this->company, $first['pkcs12'], $first['password']);
    $new = $manage->upload($this->owner, $this->company, $second['pkcs12'], $second['password']);

    $old->refresh();
    expect($old->retired_at)->not->toBeNull()
        ->and($old->pkcs12)->toBeNull()
        ->and($old->password)->toBeNull()
        ->and($new->retired_at)->toBeNull()
        ->and(SigningSealCertificate::query()->active()->sole()->id)->toBe($new->id);

    expect(fn () => DB::transaction(fn () => DB::table('signing_seal_certificates')->where('id', $new->id)->update(['subject' => 'CN=Annan'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('signing_seal_certificates')->where('id', $old->id)->update(['retired_at' => null])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('signing_seal_certificates')->where('id', $new->id)->update(['pkcs12' => 'annan'])))->toThrow(QueryException::class)
        ->and(fn () => $new->forceFill(['subject' => 'CN=Annan'])->save())->toThrow(LogicException::class);

    $manage->retire($this->owner, $this->company, $new->id);
    expect(SigningSealCertificate::query()->active()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'signing.seal_certificate_retired')->count())->toBe(2);
});

it('falls back to the instance seal once the company certificate has expired', function () {
    s10SwitchOn();
    $short = s10Pkcs12(days: 2);
    app(ManageSealCertificates::class)->upload($this->owner, $this->company, $short['pkcs12'], $short['password']);

    $this->travel(3)->days();
    expect(SealCertificates::forCompany($this->company->id)?->source)->toBe('INSTANCE');
});

it('raises one to-do 60 days before a company certificate expires, for its uploader', function () {
    $soon = s10Pkcs12(days: 90);
    $certificate = app(ManageSealCertificates::class)->upload($this->owner, $this->company, $soon['pkcs12'], $soon['password']);

    expect(app(ManageSealCertificates::class)->raiseExpiryTasks())->toBe(0);

    $this->travel(31)->days();
    expect(app(ManageSealCertificates::class)->raiseExpiryTasks())->toBe(1)
        ->and(app(ManageSealCertificates::class)->raiseExpiryTasks())->toBe(0);

    $task = Task::query()->sole();
    expect($task->assignee_id)->toBe($this->owner->id)
        ->and($task->title)->toContain('Förseglingscertifikatet går ut '.$certificate->not_after->setTimezone('Europe/Stockholm')->format('Y-m-d'))
        ->and($certificate->refresh()->expiry_task_id)->toBe($task->id);
});

it('makes the instance seal with the command: a 0600 file outside the web root, never replaced without --force', function () {
    $path = $this->sealDir.'/made.p12';
    config(['nonox.signing.seal.instance_pkcs12' => $path, 'nonox.signing.seal.instance_password' => 'ett-langt-losenord-for-test']);

    $this->artisan('signing:seal-certificate', ['--organisation' => 'Driftbolaget AB', '--days' => 400])->assertSuccessful()->expectsOutputToContain('Fingeravtryck');

    expect(fileperms($path) & 0777)->toBe(0600)
        ->and(SealCertificates::instance()?->info->selfSigned)->toBeTrue()
        ->and(SealCertificates::instance()?->info->subject)->toContain('O=Driftbolaget AB');

    $this->artisan('signing:seal-certificate')->assertFailed();
    $this->artisan('signing:seal-certificate', ['--force' => true])->assertSuccessful();

    config(['nonox.signing.seal.instance_pkcs12' => public_path('nonox-s10-test.p12')]);
    $this->artisan('signing:seal-certificate')->assertFailed();
    expect(file_exists(public_path('nonox-s10-test.p12')))->toBeFalse();
});

it('prints a generated password once when none is configured', function () {
    config(['nonox.signing.seal.instance_pkcs12' => $this->sealDir.'/generated.p12', 'nonox.signing.seal.instance_password' => '']);

    $this->artisan('signing:seal-certificate')->assertSuccessful()->expectsOutputToContain('NONOX_SIGNING_SEAL_PASSWORD=');
});

it('switches sealing on and off in /hq', function () {
    $admin = User::factory()->create(['email' => 'hq-s10@example.test']);
    config(['nonox.hq_admins' => ['hq-s10@example.test'], 'app.admin_email' => null]);

    $changed = app(UpdateInstanceSettings::class)($admin, ['waitlist_open' => true, 'registration_open' => false, 'signing_seal_enabled' => true]);

    expect($changed)->toBe([InstanceSettings::SIGNING_SEAL_ENABLED])
        ->and(InstanceSettings::current()->signingSealEnabled())->toBeTrue();
});

it('shows the settings page with the certificate in use and uploads through it without keeping the file', function () {
    s10SwitchOn();
    $own = s10Pkcs12();

    $component = Livewire::test(SigningSealSettings::class, ['company' => $this->company])
        ->assertSee('Försegling av signerade dokument')
        ->assertSee('Installationens certifikat')
        ->assertSee('Ladda upp företagets eget certifikat');

    $component->set('certificate', UploadedFile::fake()->createWithContent('forsegling.p12', $own['pkcs12']))
        ->set('password', $own['password'])
        ->call('upload')
        ->assertHasNoErrors()
        ->assertSet('password', '')
        ->assertSet('certificate', null)
        ->assertSee('Företagets eget certifikat')
        ->assertSee('Snickeri Demo AB försegling');

    $component->set('certificate', UploadedFile::fake()->createWithContent('fel.p12', $own['pkcs12']))
        ->set('password', 'fel')
        ->call('upload')
        ->assertHasErrors(['certificate'])
        ->assertSet('password', '');

    expect(SigningSealCertificate::query()->count())->toBe(1);
});

it('shows the seal on the envelope page', function () {
    s10SwitchOn();
    $envelope = s10Send($this->owner, $this->company, [['email' => 'anna@example.test', 'step' => 1, 'verification' => 'NONE']]);
    s10Sign('anna@example.test');

    Livewire::test(EnvelopePage::class, ['envelopeId' => $envelope->id])
        ->assertSee('Försegling')
        ->assertSee('PAdES B-B')
        ->assertSee('Förseglingen har ingen tidsstämpel.');
});

it('decides at completion without decrypting a key, and a key that cannot be decrypted never breaks a signature', function () {
    s10SwitchOn();
    config(['nonox.signing.seal.instance_password' => '']);
    SealCertificates::forget();
    DB::table('signing_seal_certificates')->insert([
        'company_id' => $this->company->id, 'subject' => 'CN=Trasig', 'issuer' => 'CN=Trasig', 'serial' => '01',
        'not_before' => now()->subDay(), 'not_after' => now()->addYear(), 'fingerprint_sha256' => str_repeat('c', 64),
        'self_signed' => true, 'pkcs12' => 'inte-krypterat', 'password' => 'inte-krypterat', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $envelope = s10Send($this->owner, $this->company, [['email' => 'anna@example.test', 'step' => 1, 'verification' => 'NONE']]);
    $signed = s10Sign('anna@example.test');

    $envelope->refresh();
    expect($signed->refresh()->status->value)->toBe('SIGNED')
        ->and($envelope->followed_up_at)->not->toBeNull()
        ->and($envelope->seal_status)->toBe(SealStatus::Failed)
        ->and($envelope->seal_failure)->toBe(EnvelopeSealer::CERTIFICATE_UNREADABLE)
        ->and(s10Mails('anna@example.test'))->toHaveCount(1)
        ->and(s10Mails('info@foretaget.test'))->toHaveCount(1);
});

it('never lets the seal\'s dispatch throw into the signature', function () {
    s10SwitchOn();
    $envelope = s10Send($this->owner, $this->company, [['email' => 'anna@example.test', 'step' => 1, 'verification' => 'NONE']]);
    config(['queue.default' => 'saknas-s10']);

    $signed = s10Sign('anna@example.test');

    expect($signed->refresh()->status->value)->toBe('SIGNED')
        ->and($envelope->refresh()->seal_status)->toBe(SealStatus::Pending);
});

it('refuses an instance seal path that is relative, has "..", or resolves into public/', function () {
    foreach (['public/seal/x.p12', base_path('storage/../public/x.p12'), public_path('seal/x.p12')] as $path) {
        config(['nonox.signing.seal.instance_pkcs12' => $path]);

        expect(SealCertificates::instancePath())->toBe('');
        $this->artisan('signing:seal-certificate')->assertFailed();
    }

    expect(file_exists(public_path('x.p12')))->toBeFalse()->and(is_dir(public_path('seal')))->toBeFalse();
});

it('notices a replaced instance seal file without being told', function () {
    $path = $this->sealDir.'/instance.p12';
    $before = SealCertificates::instance()?->info->fingerprintSha256;

    $other = s10Pkcs12(name: 'Ny installationsförsegling');
    file_put_contents($path, $other['pkcs12']);
    touch($path, time() + 5);
    config(['nonox.signing.seal.instance_password' => $other['password']]);

    expect(SealCertificates::instance()?->info->fingerprintSha256)->not->toBe($before)
        ->and(SealCertificates::instance()?->info->subject)->toContain('Ny installationsförsegling');
});

it('gives copy recipients of a sealed envelope the sealed file only, which carries the summary', function () {
    s10SwitchOn();
    $envelope = s10Send($this->owner, $this->company, [['email' => 'anna@example.test', 'step' => 1, 'verification' => 'NONE']]);
    s10Sign('anna@example.test');
    $envelope->refresh();

    $files = CompletionFiles::forEnvelope($envelope, 'COPY');
    expect($files)->toHaveCount(1)->and($files[0]['path'])->toBe($envelope->sealed_path);
});

it('does not promise a matching checksum when the copy is the filled-in version', function () {
    $envelope = s10Send($this->owner, $this->company, [['email' => 'anna@example.test', 'step' => 1, 'verification' => 'NONE']]);
    $view = fn (bool $withFields): string => view('signing.summary', [
        'envelope' => $envelope, 'signers' => collect(), 'company' => $this->company, 'brand' => Brand::for($this->company),
        'title' => 'Avtal', 'withFields' => $withFields,
    ])->render();

    expect($view(true))->toContain('har en annan kontrollsumma')->not->toContain('PDF:en är oförändrad')
        ->and($view(false))->toContain('PDF:en är oförändrad');
});

it('warns, but does not refuse, a certificate whose name is not the company\'s', function () {
    $other = s10Pkcs12();

    Livewire::test(SigningSealSettings::class, ['company' => $this->company])
        ->set('certificate', UploadedFile::fake()->createWithContent('annan.p12', $other['pkcs12']))
        ->set('password', $other['password'])
        ->call('upload')
        ->assertHasNoErrors()
        ->assertSee('Namnet i certifikatet stämmer inte med företagets namn eller organisationsnummer');

    expect(SigningSealCertificate::query()->count())->toBe(1)
        ->and(SealCertificates::namesCompany('CN=X, O=Snickeri Demo AB, C=SE', 'Snickeri Demo AB', null))->toBeTrue()
        ->and(SealCertificates::namesCompany('CN=X, organizationIdentifier=NTRSE-5561234567', 'Annat AB', '556123-4567'))->toBeTrue()
        ->and(SealCertificates::namesCompany('CN=X, O=Annat AB', 'Snickeri Demo AB', '556123-4567'))->toBeFalse()
        ->and(SealCertificates::namesCompany('CN=X', 'Snickeri Demo AB', null))->toBeFalse();
});
