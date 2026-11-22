<?php

declare(strict_types=1);

use App\Actions\Signing\SendForSigning;
use App\Actions\Signing\SignAtLink;
use App\Domain\Signing\Chain\ChainVerifier;
use App\Domain\Signing\CompletionFiles;
use App\Domain\Signing\Fields\SigningFields;
use App\Domain\Signing\Pdf\FakePdfEngine;
use App\Domain\Signing\Pdf\PdfEngineException;
use App\Domain\Signing\Pdf\PdfEngineFailure;
use App\Domain\Signing\Pdf\SealLevel;
use App\Domain\Signing\Pdf\TimestampFact;
use App\Domain\Signing\Retention\EnvelopeDeletion;
use App\Domain\Signing\Seal\EnvelopeTimestamper;
use App\Domain\Signing\Seal\LongTermValidation;
use App\Domain\Signing\Seal\SealCertificates;
use App\Domain\Signing\Seal\SealStatus;
use App\Domain\Signing\SigningPdfs;
use App\Jobs\TimestampSigningEnvelope;
use App\Mail\SignedDocumentMail;
use App\Mail\SigningEnvelopeMail;
use App\Mail\SigningRequestMail;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\InstanceSetting;
use App\Models\SigningDocumentVersion;
use App\Models\SigningDownloadLink;
use App\Models\SigningEnvelope;
use App\Models\SigningRequest;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Signing\Actions\StoreSigningDocument;
use App\Modules\Signing\Livewire\EnvelopePage;
use App\Modules\Signing\Models\SigningDocument;
use App\Modules\Signing\SigningModule;
use App\Support\Instance\InstanceSettings;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\PdfEngineFixtures;
use Tests\Support\SigningConsentFixture;

/*
 * Long-term validation of sealed files (ADR-046 amendment 2026-11-18, gap 5,
 * package S11): validation data after a B-T seal (B-LT), the nightly
 * signing:timestamp that adds a document timestamp (B-LTA) before the newest
 * timestamp stops being reliable, each file a TIMESTAMPED version that starts
 * with the previous file's bytes, retention and legal hold respected, inert
 * while sealing is off. The fake PDF engine serves; Python is never needed.
 */

const S11_TSA = 'https://tsa.example.test/tsr';

/** @return array{pkcs12: string, password: string} */
function s11Pkcs12(): array
{
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    $csr = openssl_csr_new(['commonName' => 'NONOX test – försegling'], $key, ['digest_alg' => 'sha256']);
    $certificate = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);
    $password = bin2hex(random_bytes(8));
    openssl_pkcs12_export($certificate, $pkcs12, $key, $password);

    return ['pkcs12' => (string) $pkcs12, 'password' => $password];
}

function s11SwitchOn(bool $on = true): void
{
    InstanceSetting::query()->updateOrCreate(['key' => InstanceSettings::SIGNING_SEAL_ENABLED], ['value' => $on]);
    InstanceSettings::current()->forget();
}

/** Sends a one-signer envelope and signs it, so it completes and is sealed. */
function s11Complete(User $user, Company $company): SigningEnvelope
{
    $path = tempnam(sys_get_temp_dir(), 's11');
    file_put_contents($path, PdfEngineFixtures::pdf(2));

    try {
        $document = app(StoreSigningDocument::class)($user, $company, $path, 'Avtal 2026.pdf', 'Samarbetsavtal');
    } finally {
        @unlink($path);
    }

    assert($document instanceof SigningDocument);
    $envelope = app(SendForSigning::class)->envelope($user, $company, $document, ['signers' => [['email' => 'anna@example.test', 'step' => 1, 'verification' => 'NONE']]], minSigners: 1);

    $url = null;
    Mail::assertQueued(SigningRequestMail::class, function ($mail) use (&$url): bool {
        $url = $mail->url;

        return true;
    });

    $links = app(SignAtLink::class);
    $request = $links->find(basename((string) parse_url((string) $url, PHP_URL_PATH)));
    SigningConsentFixture::give($request);

    $image = imagecreatetruecolor(300, 100);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
    imageline($image, 10, 50, 290, 60, (int) imagecolorallocate($image, 0, 0, 0));
    ob_start();
    imagepng($image);
    $png = 'data:image/png;base64,'.base64_encode((string) ob_get_clean());

    $links->sign($request, ['name' => 'Anna Andersson', 'accept' => '1', 'method' => 'DRAWN', 'signature' => $png, 'document_sha256' => $request->document_sha256, ...SigningPdfs::acceptanceFields($request)], '203.0.113.7', 'Pest');

    return $envelope->refresh();
}

/** @return list<SigningDocumentVersion> */
function s11Versions(SigningEnvelope $envelope): array
{
    return SigningDocumentVersion::query()->where('envelope_id', $envelope->id)->whereIn('kind', [SigningDocumentVersion::SEALED, SigningDocumentVersion::TIMESTAMPED])->orderBy('id')->get()->all();
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::now()->addMinutes(10)->startOfMinute());
    Storage::fake('local');
    Mail::fake();
    $this->engine = FakePdfEngine::install();
    // The seal's signature timestamp was made a moment before the seal was stored.
    $this->engine->sealTimestampAt = CarbonImmutable::now()->subSeconds(5);
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    app(ModuleManager::class)->enable(SigningModule::KEY, $this->company, $this->owner);

    $this->sealDir = sys_get_temp_dir().'/nonox-s11-'.bin2hex(random_bytes(6));
    mkdir($this->sealDir, 0700);
    $instance = s11Pkcs12();
    file_put_contents($this->sealDir.'/instance.p12', $instance['pkcs12']);
    config([
        'nonox.signing.seal.instance_pkcs12' => $this->sealDir.'/instance.p12',
        'nonox.signing.seal.instance_password' => $instance['password'],
        'nonox.signing.timestamp.urls' => [S11_TSA],
        'nonox.signing.ltv.renew_months' => 12,
        'nonox.signing.ltv.algorithm_valid_until' => [],
    ]);
    SealCertificates::forget();
    s11SwitchOn();
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

it('adds the validation data right after a B-T seal: PAdES B-LT as a TIMESTAMPED version that starts with the sealed bytes', function () {
    $this->engine->tsaCertificateNotAfter = CarbonImmutable::now()->addYears(4);
    $envelope = s11Complete($this->owner, $this->company);

    $disk = Storage::disk(SigningRequest::DISK);
    [$sealed, $extended] = s11Versions($envelope);
    $sealedBytes = (string) $disk->get($sealed->path);
    $extendedBytes = (string) $disk->get($extended->path);

    expect($envelope->seal_status)->toBe(SealStatus::Sealed)
        ->and($envelope->seal_level)->toBe(SealLevel::B_T)
        ->and($envelope->ltv_level)->toBe(SealLevel::B_LT)
        ->and($envelope->ltv_failure)->toBeNull()
        ->and($envelope->timestamp_valid_until?->toIso8601String())->toBe(CarbonImmutable::now()->addYears(4)->toIso8601String())
        ->and($sealed->kind)->toBe(SigningDocumentVersion::SEALED)
        ->and($extended->kind)->toBe(SigningDocumentVersion::TIMESTAMPED)
        ->and($extended->sha256)->toBe(hash('sha256', $extendedBytes))
        ->and(strlen($extendedBytes))->toBeGreaterThan(strlen($sealedBytes))
        ->and(str_starts_with($extendedBytes, $sealedBytes))->toBeTrue()
        ->and($this->engine->operations())->toContain('add_validation_data')->not->toContain('archive_timestamp')
        // The seal's own facts never change: the sealed file stays what the seal produced.
        ->and($envelope->sealed_sha256)->toBe($sealed->sha256)
        // Downloads and the API give the newest file.
        ->and(SigningFields::finalVersion($envelope))->toBe($extended->path);

    $audit = AuditLog::query()->where('action', EnvelopeTimestamper::AUDIT)->sole();
    expect($audit->payload)->toEqualCanonicalizing([
        'envelope_id' => $envelope->id,
        'version_id' => $extended->id,
        'step' => EnvelopeTimestamper::STEP_VALIDATION_DATA,
        'level' => 'B_LT',
        'timestamp_valid_until' => $envelope->timestamp_valid_until?->toIso8601String(),
    ]);

    // signing:verify checks the new version like every other one.
    $report = app(ChainVerifier::class)->verify($this->company);
    expect($report['ok'])->toBeTrue()->and($report['problems'])->toBe([]);

    $disk->put($extended->path, $extendedBytes.'x');
    expect(collect(app(ChainVerifier::class)->verify($this->company)['problems'])->pluck('code')->all())->toContain('FILE_CHANGED');
});

it('renews before the newest timestamp stops being reliable: the nightly run adds a document timestamp, PAdES B-LTA', function () {
    Queue::fake([TimestampSigningEnvelope::class]);
    $this->engine->tsaCertificateNotAfter = CarbonImmutable::now()->addMonths(30);
    $envelope = s11Complete($this->owner, $this->company);
    Queue::assertPushed(TimestampSigningEnvelope::class, fn (TimestampSigningEnvelope $job): bool => $job->envelopeId === $envelope->id && $job->companyId === $this->company->id);
    expect(app(EnvelopeTimestamper::class)->attempt($envelope->id))->toBe(EnvelopeTimestamper::EXTENDED);
    $envelope->refresh();
    $lastSealTimestamp = $envelope->last_timestamp_at;

    // Not yet: the timestamp is reliable for 30 more months, more than the twelve configured.
    Queue::fake([TimestampSigningEnvelope::class]);
    $this->artisan('signing:timestamp')->assertSuccessful();
    Queue::assertNothingPushed();

    // Nineteen months later it stops being reliable within twelve: renewed.
    $this->travel(19)->months();
    $this->engine->tsaCertificateNotAfter = CarbonImmutable::now()->addYears(5);
    $this->artisan('signing:timestamp')->expectsOutputToContain('köades för långtidsvalidering: 1')->assertSuccessful();
    Queue::assertPushed(TimestampSigningEnvelope::class, 1);
    expect(app(EnvelopeTimestamper::class)->attempt($envelope->id))->toBe(EnvelopeTimestamper::EXTENDED);

    $envelope->refresh();
    $versions = s11Versions($envelope);
    $disk = Storage::disk(SigningRequest::DISK);

    expect($versions)->toHaveCount(3)
        ->and($envelope->ltv_level)->toBe(SealLevel::B_LTA)
        ->and($envelope->last_timestamp_at?->greaterThan($lastSealTimestamp))->toBeTrue()
        ->and($envelope->timestamp_valid_until?->toIso8601String())->toBe(CarbonImmutable::now()->addYears(5)->toIso8601String())
        ->and(str_starts_with((string) $disk->get($versions[2]->path), (string) $disk->get($versions[1]->path)))->toBeTrue()
        ->and(str_starts_with((string) $disk->get($versions[2]->path), (string) $disk->get($versions[0]->path)))->toBeTrue()
        ->and(AuditLog::query()->where('action', EnvelopeTimestamper::AUDIT)->latest('id')->first()?->payload['step'])->toBe(EnvelopeTimestamper::STEP_DOCUMENT_TIMESTAMP)
        ->and(app(ChainVerifier::class)->verify($this->company)['ok'])->toBeTrue();

    // Idempotent: the same night again does nothing.
    expect(app(EnvelopeTimestamper::class)->attempt($envelope->id))->toBe(EnvelopeTimestamper::NOTHING)
        ->and(s11Versions($envelope))->toHaveCount(3);
});

it('ends a timestamp at the configured date for its hash algorithm, when that comes before the certificate expires', function () {
    config(['nonox.signing.ltv.algorithm_valid_until' => ['sha256' => CarbonImmutable::now()->addYears(2)->toDateString()]]);
    $this->engine->tsaCertificateNotAfter = CarbonImmutable::now()->addYears(6);

    $fact = new TimestampFact(TimestampFact::DOCUMENT, CarbonImmutable::now(), CarbonImmutable::now()->addYears(6), 'sha256');
    expect(LongTermValidation::validUntil($fact)->toDateString())->toBe(CarbonImmutable::now()->addYears(2)->toDateString())
        ->and(LongTermValidation::validUntil(new TimestampFact(TimestampFact::DOCUMENT, CarbonImmutable::now(), CarbonImmutable::now()->addYears(6), 'sha512'))->toIso8601String())
        ->toBe(CarbonImmutable::now()->addYears(6)->toIso8601String());

    $envelope = s11Complete($this->owner, $this->company);
    expect($envelope->timestamp_valid_until?->lessThan(CarbonImmutable::now()->addYears(2)->addDay()))->toBeTrue();
});

it('records validation data that is not available, audits it once, and tries again the next night', function () {
    $this->engine->failValidationData = new PdfEngineException(PdfEngineFailure::ValidationDataUnavailable);
    $envelope = s11Complete($this->owner, $this->company);

    expect($envelope->ltv_level)->toBeNull()
        ->and($envelope->ltv_failure)->toBe('validation_data_unavailable')
        ->and(s11Versions($envelope))->toHaveCount(1)
        ->and(AuditLog::query()->where('action', EnvelopeTimestamper::AUDIT_FAILED)->sole()->payload)->toEqualCanonicalizing(['envelope_id' => $envelope->id, 'failure' => 'validation_data_unavailable']);

    // The same night: not again.
    Queue::fake([TimestampSigningEnvelope::class]);
    $this->artisan('signing:timestamp')->assertSuccessful();
    Queue::assertNothingPushed();

    // The next night it fails again: still recorded, audited only once.
    $this->travel(1)->days();
    $this->artisan('signing:timestamp')->assertSuccessful();
    Queue::assertPushed(TimestampSigningEnvelope::class, 1);
    expect(app(EnvelopeTimestamper::class)->attempt($envelope->id))->toBe(EnvelopeTimestamper::FAILED)
        ->and(AuditLog::query()->where('action', EnvelopeTimestamper::AUDIT_FAILED)->count())->toBe(1);

    // The night after the data is there.
    $this->travel(1)->days();
    $this->engine->failValidationData = null;
    expect(app(EnvelopeTimestamper::class)->attempt($envelope->id))->toBe(EnvelopeTimestamper::EXTENDED)
        ->and($envelope->refresh()->ltv_level)->toBe(SealLevel::B_LT)
        ->and($envelope->ltv_failure)->toBeNull();
});

it('tries validation data nightly for a week after the seal, then weekly', function () {
    $envelope = new SigningEnvelope;
    $envelope->forceFill([
        'seal_status' => SealStatus::Sealed,
        'seal_level' => SealLevel::B_T,
        'sealed_at' => CarbonImmutable::now()->subDays(3),
        'ltv_attempted_at' => CarbonImmutable::now()->subDay(),
        'retention_until' => CarbonImmutable::now()->addYears(10),
    ]);

    expect(LongTermValidation::validationDataDue($envelope))->toBeTrue();

    $envelope->forceFill(['sealed_at' => CarbonImmutable::now()->subDays(10), 'ltv_attempted_at' => CarbonImmutable::now()->subDays(3)]);
    expect(LongTermValidation::validationDataDue($envelope))->toBeFalse();

    $envelope->forceFill(['ltv_attempted_at' => CarbonImmutable::now()->subDays(8)]);
    expect(LongTermValidation::validationDataDue($envelope))->toBeTrue();
});

it('never extends a seal without a timestamp: B-LT presupposes B-T', function () {
    config(['nonox.signing.timestamp.urls' => []]);
    $envelope = s11Complete($this->owner, $this->company);
    config(['nonox.signing.timestamp.urls' => [S11_TSA]]);

    expect($envelope->seal_level)->toBe(SealLevel::B_B)
        ->and(app(EnvelopeTimestamper::class)->attempt($envelope->id))->toBe(EnvelopeTimestamper::NOTHING)
        ->and($envelope->refresh()->ltv_level)->toBeNull()
        ->and(LongTermValidation::due()->pluck('id')->all())->not->toContain($envelope->id)
        ->and($this->engine->operations())->not->toContain('add_validation_data');
});

it('is inert while sealing is off, without the engine and without timestamp servers', function () {
    Queue::fake([TimestampSigningEnvelope::class]);
    $envelope = s11Complete($this->owner, $this->company);
    // A fresh fake: from here on nothing may be queued.
    Queue::fake([TimestampSigningEnvelope::class]);

    s11SwitchOn(false);
    $this->artisan('signing:timestamp')->expectsOutputToContain('Långtidsvalidering körs inte')->assertSuccessful();
    expect(app(EnvelopeTimestamper::class)->attempt($envelope->id))->toBe(EnvelopeTimestamper::NOTHING);

    s11SwitchOn();
    $this->engine->installed = false;
    $this->artisan('signing:timestamp')->expectsOutputToContain('Långtidsvalidering körs inte')->assertSuccessful();
    expect(app(EnvelopeTimestamper::class)->attempt($envelope->id))->toBe(EnvelopeTimestamper::NOTHING);

    $this->engine->installed = true;
    config(['nonox.signing.timestamp.urls' => []]);
    $this->artisan('signing:timestamp')->expectsOutputToContain('Långtidsvalidering körs inte')->assertSuccessful();
    expect(app(EnvelopeTimestamper::class)->attempt($envelope->id))->toBe(EnvelopeTimestamper::NOTHING);

    Queue::assertNothingPushed();
    expect($envelope->refresh()->ltv_level)->toBeNull()
        ->and($envelope->ltv_failure)->toBeNull()
        ->and($this->engine->operations())->not->toContain('add_validation_data');
});

it('extends only within retention or under a legal hold, never renews past the last day, and never moves retention', function () {
    $this->engine->tsaCertificateNotAfter = CarbonImmutable::now()->addYears(30);
    $envelope = s11Complete($this->owner, $this->company);
    $until = $envelope->retention_until;
    expect($envelope->ltv_level)->toBe(SealLevel::B_LT)->and($until)->not->toBeNull();

    // The timestamp is reliable past the last day the file is kept: no renewal, however close its expiry comes.
    $this->travelTo(CarbonImmutable::parse($until->toDateString())->subMonths(3));
    expect(LongTermValidation::renewalDue($envelope->refresh()))->toBeFalse();

    // Past retention: nothing is extended.
    $this->travelTo(CarbonImmutable::parse($until->toDateString())->addDays(2));
    expect(LongTermValidation::withinRetention($envelope))->toBeFalse()
        ->and(LongTermValidation::due()->pluck('id')->all())->not->toContain($envelope->id);

    // Under a legal hold the file is still renewed before its timestamp stops being reliable.
    DB::table('signing_envelopes')->where('id', $envelope->id)->update(['retention_hold' => true, 'retention_hold_reason' => 'TVIST', 'retention_hold_at' => now(), 'retention_hold_by' => $this->owner->id]);
    $this->travelTo(CarbonImmutable::now()->addYears(30)->subMonths(6));
    expect(LongTermValidation::due()->pluck('id')->all())->toContain($envelope->id)
        ->and(app(EnvelopeTimestamper::class)->attempt($envelope->id))->toBe(EnvelopeTimestamper::EXTENDED);

    $envelope->refresh();
    expect($envelope->ltv_level)->toBe(SealLevel::B_LTA)
        ->and($envelope->retention_until?->toDateString())->toBe($until->toDateString());
});

it('never extends a deleted or pruned envelope, nor a file that changed on disk', function () {
    Queue::fake([TimestampSigningEnvelope::class]);
    $envelope = s11Complete($this->owner, $this->company);
    $sealed = s11Versions($envelope)[0];
    $disk = Storage::disk(SigningRequest::DISK);

    // A changed sealed file: refused, recorded, nothing written.
    $original = (string) $disk->get($sealed->path);
    $disk->put($sealed->path, $original.'x');
    expect(app(EnvelopeTimestamper::class)->attempt($envelope->id))->toBe(EnvelopeTimestamper::FAILED)
        ->and($envelope->refresh()->ltv_failure)->toBe(EnvelopeTimestamper::FILE_CHANGED)
        ->and(s11Versions($envelope))->toHaveCount(1);
    $disk->put($sealed->path, $original);

    // Signers pruned: the envelope has lost its evidence on purpose and is never extended.
    $this->travel(1)->days();
    DB::statement('ALTER TABLE crm_signing_requests DISABLE TRIGGER USER');
    SigningRequest::query()->where('envelope_id', $envelope->id)->delete();
    DB::statement('ALTER TABLE crm_signing_requests ENABLE TRIGGER USER');
    expect(app(EnvelopeTimestamper::class)->attempt($envelope->id))->toBe(EnvelopeTimestamper::NOTHING)
        ->and(s11Versions($envelope))->toHaveCount(1);

    // A deleted envelope: nothing.
    expect(app(EnvelopeTimestamper::class)->attempt('00000000-0000-0000-0000-000000000000'))->toBe(EnvelopeTimestamper::NOTHING);
});

it('keeps long-term validation moving up only, its validity tied to a newer timestamp, and only on a B-T seal', function () {
    $envelope = s11Complete($this->owner, $this->company);
    expect($envelope->ltv_level)->toBe(SealLevel::B_LT);

    expect(fn () => DB::transaction(fn () => DB::table('signing_envelopes')->where('id', $envelope->id)->update(['ltv_level' => null])))
        ->toThrow(QueryException::class, 'only moves up');
    expect(fn () => DB::transaction(fn () => DB::table('signing_envelopes')->where('id', $envelope->id)->update(['timestamp_valid_until' => now()->addYears(50)])))
        ->toThrow(QueryException::class, 'only with a newer timestamp');
    expect(fn () => DB::transaction(fn () => DB::table('signing_envelopes')->where('id', $envelope->id)->update(['last_timestamp_at' => now()->subYear()])))
        ->toThrow(QueryException::class);

    config(['nonox.signing.timestamp.urls' => []]);
    $unstamped = s11Complete($this->owner, $this->company);
    expect(fn () => DB::transaction(fn () => DB::table('signing_envelopes')->where('id', $unstamped->id)->update(['ltv_level' => 'B_LT'])))
        ->toThrow(QueryException::class, 'signing_envelopes_ltv_level_check');

    // A newer timestamp moves both, and the level up.
    DB::table('signing_envelopes')->where('id', $envelope->id)->update(['ltv_level' => 'B_LTA', 'last_timestamp_at' => now()->addMinute(), 'timestamp_valid_until' => now()->addYears(3)]);
    expect($envelope->refresh()->ltv_level)->toBe(SealLevel::B_LTA);
});

it('runs as a unique job per envelope and records a job that died', function () {
    $job = new TimestampSigningEnvelope($this->company->id, 'envelope-1');
    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('envelope-1')
        ->and($job->backoff())->toBe([300, 1800]);

    Queue::fake([TimestampSigningEnvelope::class]);
    $envelope = s11Complete($this->owner, $this->company);
    (new TimestampSigningEnvelope($this->company->id, $envelope->id))->failed();

    expect($envelope->refresh()->ltv_failure)->toBe(EnvelopeTimestamper::JOB_FAILED)
        ->and($envelope->ltv_attempted_at)->not->toBeNull()
        ->and($envelope->ltv_level)->toBeNull();
});

it('is scheduled nightly', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($event): bool => str_contains((string) $event->command, 'signing:timestamp'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('40 2 * * *');
});

it('shows the long-term validation on the envelope page', function () {
    $this->engine->tsaCertificateNotAfter = CarbonImmutable::parse('2031-03-15 12:00:00', 'Europe/Stockholm');
    $envelope = s11Complete($this->owner, $this->company);
    $newest = EnvelopeTimestamper::newestFile($envelope->id);

    Livewire::test(EnvelopePage::class, ['envelopeId' => $envelope->id])
        ->assertSee('Valideringsdata har lagts till i filen (PAdES B-LT): certifikaten och deras spärrstatus.')
        ->assertSee('NONOX försöker lägga till en ny tidsstämpel före 2031-03-15')
        ->assertSee((string) $newest?->sha256);
});

/** Sends a one-signer envelope without signing it. */
function s11Send(User $user, Company $company): SigningEnvelope
{
    $path = tempnam(sys_get_temp_dir(), 's11');
    file_put_contents($path, PdfEngineFixtures::pdf(2));

    try {
        $document = app(StoreSigningDocument::class)($user, $company, $path, 'Avtal 2026.pdf', 'Samarbetsavtal');
    } finally {
        @unlink($path);
    }

    assert($document instanceof SigningDocument);

    return app(SendForSigning::class)->envelope($user, $company, $document, ['signers' => [['email' => 'bertil@example.test', 'step' => 1, 'verification' => 'NONE']]], minSigners: 1);
}

it('never lets envelopes that keep failing crowd out a newer due one, for validation data and for renewals', function () {
    Queue::fake([TimestampSigningEnvelope::class]);
    $envelopes = collect(range(1, 4))->map(fn (): SigningEnvelope => s11Complete($this->owner, $this->company));
    $fresh = $envelopes->last();
    $failing = $envelopes->take(3)->pluck('id')->all();
    $now = CarbonImmutable::now();

    // Validation data: three tried an hour ago without success, one never tried. The same query as the nightly run,
    // with a batch of one or two instead of 500.
    DB::table('signing_envelopes')->whereIn('id', $failing)->update(['ltv_failure' => 'validation_data_unavailable', 'ltv_attempted_at' => $now->subHour()]);

    expect(LongTermValidation::due(batch: 2)->pluck('id')->all())->toBe([$fresh->id])
        ->and(LongTermValidation::due(batch: 1)->pluck('id')->all())->toBe([$fresh->id]);

    // The next night they are due again, but the one never tried still comes first.
    DB::table('signing_envelopes')->whereIn('id', $failing)->update(['ltv_attempted_at' => $now->subHours(25)]);
    expect(LongTermValidation::due(batch: 1)->pluck('id')->all())->toBe([$fresh->id])
        ->and(LongTermValidation::due(batch: 10)->pluck('id')->sort()->values()->all())->toBe($envelopes->pluck('id')->sort()->values()->all());

    // Renewals: three tried an hour ago without success, one tried three days ago; all four close to expiry.
    DB::table('signing_envelopes')->whereIn('id', $envelopes->pluck('id')->all())->update(['ltv_level' => 'B_LT', 'ltv_failure' => null, 'timestamp_valid_until' => $now->addMonth(), 'ltv_attempted_at' => $now->subDays(3)]);
    DB::table('signing_envelopes')->whereIn('id', $failing)->update(['ltv_failure' => 'timestamp_unavailable', 'ltv_attempted_at' => $now->subHour()]);

    expect(LongTermValidation::due(batch: 2)->pluck('id')->all())->toBe([$fresh->id])
        ->and(LongTermValidation::renewalDue($fresh->refresh()))->toBeTrue()
        ->and(LongTermValidation::renewalDue(SigningEnvelope::query()->findOrFail($failing[0])))->toBeFalse();
});

it('stops trying a revoked certificate and says why', function () {
    $this->engine->failValidationData = new PdfEngineException(PdfEngineFailure::CertificateRevoked);
    $envelope = s11Complete($this->owner, $this->company);

    expect($envelope->ltv_failure)->toBe('certificate_revoked')
        ->and(EnvelopeTimestamper::failureLabel('certificate_revoked'))->toContain('spärrat');

    $this->travel(8)->days();
    expect(LongTermValidation::due()->pluck('id')->all())->not->toContain($envelope->id);
});

it('gives a single signer and the company the newest long-term validation version', function () {
    $envelope = s11Complete($this->owner, $this->company);
    $signer = SigningRequest::query()->where('envelope_id', $envelope->id)->sole();
    $newest = EnvelopeTimestamper::newestFile($envelope->id);

    expect($newest?->kind)->toBe(SigningDocumentVersion::TIMESTAMPED)
        ->and(CompletionFiles::forSigner($signer)[0]['path'])->toBe($newest?->path)
        ->and(CompletionFiles::forEnvelope($envelope, SigningDownloadLink::COMPANY)[0]['path'])->toBe($newest?->path);
});

it('sends a link instead of attachments when the files grew past the mail limit after the mail was queued', function () {
    $envelope = s11Complete($this->owner, $this->company);
    $signer = SigningRequest::query()->where('envelope_id', $envelope->id)->sole();
    config(['nonox.signing.mail_attachment_limit_bytes' => 100]);

    $own = (new SignedDocumentMail($signer->id, $this->company->id, true))->to('anna@example.test');
    $own->render();

    expect($own->attachments())->toBe([])
        ->and(SigningDownloadLink::query()->where('envelope_id', $envelope->id)->where('kind', SigningDownloadLink::SIGNER)->where('email', 'anna@example.test')->count())->toBe(1);

    $copy = (new SigningEnvelopeMail($envelope->id, $this->company->id, SigningEnvelopeMail::COPY))->to('revisor@example.test');
    $copy->render();

    expect($copy->attachments())->toBe([])
        ->and(SigningDownloadLink::query()->where('envelope_id', $envelope->id)->where('kind', SigningDownloadLink::COPY)->where('email', 'revisor@example.test')->count())->toBe(1);

    // Under the limit the files are attached and no link is made.
    config(['nonox.signing.mail_attachment_limit_bytes' => CompletionFiles::MAIL_LIMIT_BYTES]);
    $small = (new SignedDocumentMail($signer->id, $this->company->id, false))->to('info@foretaget.test');
    $small->render();
    expect($small->attachments())->toHaveCount(2)
        ->and(SigningDownloadLink::query()->where('envelope_id', $envelope->id)->where('email', 'info@foretaget.test')->exists())->toBeFalse();
});

it('writes every long-term validation file under a new name in the envelope\'s directory, so a retry never touches an earlier file', function () {
    $this->engine->failValidationData = new PdfEngineException(PdfEngineFailure::ValidationDataUnavailable);
    $envelope = s11Complete($this->owner, $this->company);
    $disk = Storage::disk(SigningRequest::DISK);

    // What an attempt interrupted between writing its file and storing its row leaves behind.
    $leftover = EnvelopeTimestamper::newPath($this->company->id, $envelope->id);
    $disk->put($leftover, '%PDF-left');

    $this->travel(1)->days();
    $this->engine->failValidationData = null;
    expect(app(EnvelopeTimestamper::class)->attempt($envelope->id))->toBe(EnvelopeTimestamper::EXTENDED);
    [, $extended] = s11Versions($envelope);

    expect($extended->path)->not->toBe($leftover)
        ->and(str_starts_with($extended->path, EnvelopeTimestamper::envelopeDirectory($this->company->id, $envelope->id).'/'))->toBeTrue()
        ->and(EnvelopeTimestamper::isLongTermFile($extended->path))->toBeTrue()
        ->and(EnvelopeTimestamper::envelopeOf($extended->path))->toBe($envelope->id)
        ->and(EnvelopeTimestamper::isLongTermFile('crm-signing/'.$this->company->id.'/avtal.pdf'))->toBeFalse()
        ->and(EnvelopeTimestamper::newPath($this->company->id, $envelope->id))->not->toBe(EnvelopeTimestamper::newPath($this->company->id, $envelope->id))
        ->and($disk->get($leftover))->toBe('%PDF-left');
});

it('sweeps a long-term validation file only when no version holds it and it is older than a day', function () {
    $envelope = s11Complete($this->owner, $this->company);
    [, $extended] = s11Versions($envelope);
    $disk = Storage::disk(SigningRequest::DISK);

    $old = EnvelopeTimestamper::newPath($this->company->id, $envelope->id);
    $recent = EnvelopeTimestamper::newPath($this->company->id, $envelope->id);
    $disk->put($old, '%PDF-left');
    $disk->put($recent, '%PDF-left');
    touch($disk->path($old), time() - 2 * 86400);
    touch($disk->path($extended->path), time() - 2 * 86400);

    $this->artisan('signing:prune-engine-work')->expectsOutputToContain('Tog bort 1 kvarlämnade filer från långtidsvalidering.')->assertSuccessful();

    expect($disk->exists($old))->toBeFalse()
        ->and($disk->exists($recent))->toBeTrue()
        ->and($disk->exists($extended->path))->toBeTrue();
});

it('deletes the envelope\'s long-term validation directory with the envelope', function () {
    $envelope = s11Send($this->owner, $this->company);
    $other = s11Send($this->owner, $this->company);
    $disk = Storage::disk(SigningRequest::DISK);
    $leftover = EnvelopeTimestamper::newPath($this->company->id, $envelope->id);
    $kept = EnvelopeTimestamper::newPath($this->company->id, $other->id);
    $disk->put($leftover, '%PDF-left');
    $disk->put($kept, '%PDF-left');

    EnvelopeDeletion::delete($envelope->refresh(), EnvelopeDeletion::ERASURE, $this->owner);

    expect($disk->exists($leftover))->toBeFalse()
        ->and($disk->directoryExists(EnvelopeTimestamper::envelopeDirectory($this->company->id, $envelope->id)))->toBeFalse()
        ->and($disk->exists($kept))->toBeTrue();
});
