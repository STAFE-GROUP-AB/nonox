<?php

declare(strict_types=1);

use App\Domain\Signing\Pdf\FakePdfEngine;
use App\Domain\Signing\Pdf\NullPdfEngine;
use App\Domain\Signing\Pdf\PdfEngine;
use App\Domain\Signing\Pdf\PdfEngineException;
use App\Domain\Signing\Pdf\PdfEngineFailure;
use App\Domain\Signing\Pdf\PdfEngineHealth;
use App\Domain\Signing\Pdf\PdfEngineStatus;
use App\Domain\Signing\Pdf\PdfInspection;
use App\Domain\Signing\Pdf\PdfLimits;
use App\Domain\Signing\Pdf\PdfRefusal;
use App\Domain\Signing\Pdf\SealLevel;
use App\Domain\Signing\Pdf\SealRequest;
use App\Domain\Signing\Pdf\SidecarPdfEngine;
use App\Domain\Signing\Pdf\StampImage;
use App\Domain\Signing\Pdf\StampText;
use App\Models\User;
use App\Providers\PdfEngineServiceProvider;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimeout;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\Support\PdfEngineFixtures;

/*
 * The PDF engine's PHP side (ADR-046, amendment 2026-11-18, S5), always run:
 * the drivers, the checks before the helper is started, the protocol, the
 * failure and timeout paths, the cached status, the doctor, /hq and the
 * "Kräver PDF-motorn" notice. No Python is needed: the subprocess is faked.
 * The real helper is tested in PdfEngineRealTest, which skips without it.
 */

beforeEach(function () {
    $this->pdfDir = PdfEngineFixtures::directory();
    $this->workRoot = $this->pdfDir.'/work';
    // A stand-in "python" that exists and is executable, so the sidecar counts as installed.
    $this->python = PHP_BINARY;
    $this->script = PdfEngineFixtures::write($this->pdfDir, 'engine.py', '# fake');
    $this->engine = new SidecarPdfEngine($this->python, $this->script, $this->pdfDir.'/fonts', $this->workRoot, 5);
    $this->input = PdfEngineFixtures::write($this->pdfDir, 'in.pdf', PdfEngineFixtures::pdf(2));
});

afterEach(function () {
    PdfEngineFixtures::cleanUp();
});

/**
 * A fake helper: answers $result (or the closure's answer) and, for writing
 * operations, writes output.pdf into the work directory as the input plus one line.
 *
 * @param  array<string, mixed>|Closure(array<string, mixed>): array<string, mixed>  $result
 */
function s5FakeHelper(array|Closure $result): void
{
    Process::fake(['*' => function (PendingProcess $process) use ($result) {
        $request = json_decode((string) $process->input, true);
        $answer = $result instanceof Closure ? $result($request) : $result;

        if (isset($request['output']) && ($answer['ok'] ?? false) === true) {
            $work = $request['work_dir'];
            $bytes = file_get_contents($work.'/'.$request['input'])."\n% stamped\n";
            file_put_contents($work.'/'.$request['output'], $bytes);
            $answer['result'] += ['output' => $work.'/'.$request['output'], 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
        }

        return Process::result(json_encode($answer), '', ($answer['ok'] ?? false) ? 0 : 2);
    }]);
}

it('chooses no engine when it is switched off or missing, and the helper when it is there or required', function () {
    config(['nonox.signing.pdf_engine.driver' => 'none']);
    app()->forgetInstance(PdfEngine::class);
    expect(app(PdfEngine::class))->toBeInstanceOf(NullPdfEngine::class);

    config(['nonox.signing.pdf_engine.driver' => 'auto', 'nonox.signing.pdf_engine.python' => $this->pdfDir.'/no-python']);
    app()->register(PdfEngineServiceProvider::class, true);
    expect(app(PdfEngine::class))->toBeInstanceOf(NullPdfEngine::class);

    config(['nonox.signing.pdf_engine.driver' => 'sidecar']);
    app()->register(PdfEngineServiceProvider::class, true);
    $engine = app(PdfEngine::class);
    expect($engine)->toBeInstanceOf(SidecarPdfEngine::class)
        ->and($engine->isInstalled())->toBeFalse()
        ->and($engine->health()->problems)->not->toBe([]);

    config(['nonox.signing.pdf_engine.driver' => 'auto', 'nonox.signing.pdf_engine.python' => PHP_BINARY]);
    app()->register(PdfEngineServiceProvider::class, true);
    expect(app(PdfEngine::class))->toBeInstanceOf(SidecarPdfEngine::class);
});

it('without the engine refuses every PDF operation with "not installed" and starts no process', function () {
    Process::fake();
    $engine = new NullPdfEngine;
    $output = $this->pdfDir.'/out.pdf';
    $seal = new SealRequest(SealLevel::B_B, 'p12', 'secret');

    $calls = [
        fn () => $engine->inspect($this->input),
        fn () => $engine->renderPages($this->input, $this->pdfDir),
        fn () => $engine->stamp($this->input, $output, [new StampText(1, 10, 10, 'Text')]),
        fn () => $engine->appendPages($this->input, $this->input, $output),
        fn () => $engine->seal($this->input, $output, $seal),
        fn () => $engine->timestamp($this->input, $output, ['https://tsa.example.test']),
        fn () => $engine->validate($this->input),
    ];

    foreach ($calls as $call) {
        expect($call)->toThrow(PdfEngineException::class, 'Kräver PDF-motorn');
    }

    expect($engine->isInstalled())->toBeFalse()
        ->and($engine->health()->usable())->toBeFalse();
    Process::assertNothingRan();
});

it('runs the helper as an argument list in isolated mode, with the request on stdin and an emptied environment', function () {
    s5FakeHelper(['ok' => true, 'result' => ['bytes' => 10, 'sha256' => str_repeat('a', 64), 'pages' => 2, 'page_sizes' => [['width' => 595.28, 'height' => 841.89, 'rotation' => 0], ['width' => 595.28, 'height' => 841.89, 'rotation' => 90]], 'pdf_version' => '1.7', 'revisions' => 1, 'signatures' => 0, 'javascript' => false, 'launch_actions' => false, 'embedded_files' => false, 'xfa' => false]]);
    putenv('S5_PRETEND_SECRET=do-not-pass');

    $inspection = $this->engine->inspect($this->input);

    putenv('S5_PRETEND_SECRET');
    expect($inspection->pages)->toBe(2)
        ->and($inspection->pageSizes[1]['rotation'])->toBe(90)
        ->and($inspection->refusal())->toBeNull();

    Process::assertRan(function (PendingProcess $process): bool {
        $request = json_decode((string) $process->input, true);

        return $process->command === [PHP_BINARY, '-I', $this->script]
            && $process->timeout === 5
            && $request['protocol'] === SidecarPdfEngine::PROTOCOL
            && $request['op'] === 'inspect'
            && $request['input'] === 'input.pdf'
            && $request['max_bytes'] === PdfLimits::MAX_BYTES
            && $request['max_pages'] === PdfLimits::MAX_PAGES
            && str_starts_with($request['work_dir'], realpath($this->workRoot).'/')
            && $process->path === $request['work_dir']
            && ($process->environment['APP_KEY'] ?? null) === false
            && ($process->environment['S5_PRETEND_SECRET'] ?? null) === false
            && $process->environment['HOME'] === $request['work_dir']
            && $process->environment['PATH'] === '/usr/local/bin:/usr/bin:/bin';
    });

    expect(glob($this->workRoot.'/*'))->toBe([]);
});

it('passes the seal certificate and its password on stdin only, never on the command line', function () {
    s5FakeHelper(['ok' => true, 'result' => ['level' => 'B_B', 'certificate_sha256' => str_repeat('b', 64)]]);
    $output = $this->pdfDir.'/sealed.pdf';

    $result = $this->engine->seal($this->input, $output, new SealRequest(SealLevel::B_B, 'PKCS12-BYTES', 'pass-word'));

    expect($result->level)->toBe(SealLevel::B_B)
        ->and(str_starts_with((string) file_get_contents($output), (string) file_get_contents($this->input)))->toBeTrue();

    Process::assertRan(function (PendingProcess $process): bool {
        $request = json_decode((string) $process->input, true);

        return $request['secrets'] === ['pkcs12' => base64_encode('PKCS12-BYTES'), 'password' => base64_encode('pass-word')]
            && ! str_contains(implode(' ', $process->command), 'PKCS12')
            && ! str_contains(implode(' ', $process->command), 'pass-word');
    });

    expect(print_r(new SealRequest(SealLevel::B_B, 'PKCS12-BYTES', 'pass-word'), true))->not->toContain('PKCS12-BYTES')->not->toContain('pass-word');
});

it('checks every input before starting the helper', function () {
    Process::fake();
    $notPdf = PdfEngineFixtures::write($this->pdfDir, 'note.pdf', 'Hello, not a PDF');
    $tooLarge = $this->pdfDir.'/large.pdf';
    $handle = fopen($tooLarge, 'wb');
    fwrite($handle, '%PDF-1.7');
    ftruncate($handle, PdfLimits::MAX_BYTES + 1);
    fclose($handle);
    $link = $this->pdfDir.'/link.pdf';
    symlink($this->input, $link);
    $existing = PdfEngineFixtures::write($this->pdfDir, 'exists.pdf', 'x');

    expect(fn () => $this->engine->inspect($notPdf))->toThrow(fn (PdfEngineException $e) => expect($e->refusal)->toBe(PdfRefusal::NotPdf))
        ->and(fn () => $this->engine->inspect($tooLarge))->toThrow(fn (PdfEngineException $e) => expect($e->refusal)->toBe(PdfRefusal::TooLarge))
        ->and(fn () => $this->engine->inspect($link))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->engine->inspect($this->pdfDir.'/missing.pdf'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->engine->stamp($this->input, $existing, [new StampText(1, 1, 1, 'x')]))->toThrow(InvalidArgumentException::class, 'already exists')
        ->and(fn () => $this->engine->stamp($this->input, $this->pdfDir.'/out.pdf', [new StampImage(1, 1, 1, 10, 10, $this->input)]))->toThrow(InvalidArgumentException::class, 'PNG')
        ->and(fn () => $this->engine->renderPages($this->input, $this->pdfDir.'/nowhere'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->engine->timestamp($this->input, $this->pdfDir.'/out.pdf', ['file:///etc/passwd']))->toThrow(InvalidArgumentException::class);

    Process::assertNothingRan();
});

it('validates stamps and seal requests before anything runs', function () {
    expect(fn () => new StampText(0, 1, 1, 'x'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new StampText(1, 1, 1, ''))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new StampText(1, 1, 1, 'x', 200))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new StampText(1, 1, 1, 'x', font: 'comic'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new StampText(PdfLimits::MAX_PAGES + 1, 1, 1, 'x'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new StampImage(1, 1, 1, 0, 10, '/tmp/x.png'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new SealRequest(SealLevel::B_T, 'p12', 'pw'))->toThrow(InvalidArgumentException::class, 'timestamp URL')
        ->and(fn () => new SealRequest(SealLevel::B_B, 'p12', 'pw', fieldName: 'bad name'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new SealRequest(SealLevel::B_T, 'p12', 'pw', timestampUrls: ['ftp://tsa.example.test']))->toThrow(InvalidArgumentException::class)
        ->and(new SealRequest(SealLevel::B_T, 'p12', 'pw', timestampUrls: ['https://tsa.example.test/tsr'])->level)->toBe(SealLevel::B_T);
});

it('turns the helper refusals into plain Swedish messages', function (string $code, PdfRefusal $refusal) {
    s5FakeHelper(['ok' => false, 'error' => ['code' => $code, 'message' => 'detail']]);

    expect(fn () => $this->engine->inspect($this->input))->toThrow(function (PdfEngineException $e) use ($refusal) {
        expect($e->failure)->toBe(PdfEngineFailure::Refused)
            ->and($e->refusal)->toBe($refusal)
            ->and($e->getMessage())->toBe($refusal->message());
    });
    expect(glob($this->workRoot.'/*'))->toBe([]);
})->with([
    ['encrypted', PdfRefusal::Encrypted],
    ['too_many_pages', PdfRefusal::TooManyPages],
    ['unreadable', PdfRefusal::Unreadable],
    ['not_pdf', PdfRefusal::NotPdf],
]);

it('refuses active content found by the inspection, and never strips it', function () {
    $inspection = fn (array $flags): PdfInspection => PdfInspection::fromArray(array_merge(['bytes' => 100, 'sha256' => 'x', 'pages' => 1], $flags));

    expect($inspection(['javascript' => true])->refusal())->toBe(PdfRefusal::JavaScript)
        ->and($inspection(['launch_actions' => true])->refusal())->toBe(PdfRefusal::LaunchAction)
        ->and($inspection(['embedded_files' => true])->refusal())->toBe(PdfRefusal::EmbeddedFiles)
        ->and($inspection(['xfa' => true])->refusal())->toBe(PdfRefusal::XfaForm)
        ->and($inspection(['pages' => PdfLimits::MAX_PAGES + 1])->refusal())->toBe(PdfRefusal::TooManyPages)
        ->and($inspection([])->refusal())->toBeNull()
        ->and(fn () => $inspection(['javascript' => true])->assertAcceptable())->toThrow(PdfEngineException::class, 'JavaScript');
});

it('maps the helper failures, and tells transient ones from permanent ones', function (string $code, PdfEngineFailure $failure, bool $transient) {
    s5FakeHelper(['ok' => false, 'error' => ['code' => $code, 'message' => 'detail']]);

    expect(fn () => $this->engine->validate($this->input))->toThrow(function (PdfEngineException $e) use ($failure, $transient) {
        expect($e->failure)->toBe($failure)
            ->and($e->failure->isTransient())->toBe($transient)
            ->and($e->getMessage())->toBe($failure->message());
    });
})->with([
    ['timestamp_unavailable', PdfEngineFailure::TimestampUnavailable, true],
    ['bad_credentials', PdfEngineFailure::BadCredentials, false],
    ['font_missing', PdfEngineFailure::FontMissing, false],
    ['protocol_mismatch', PdfEngineFailure::ProtocolMismatch, false],
    ['memory', PdfEngineFailure::Memory, true],
    ['something_new', PdfEngineFailure::Failed, false],
]);

it('stops a helper that does not answer in time and removes its work directory', function () {
    Process::fake(['*' => fn () => throw new SymfonyTimeout(new SymfonyProcess(['sleep', '9']), SymfonyTimeout::TYPE_GENERAL)]);

    expect(fn () => $this->engine->inspect($this->input))->toThrow(function (PdfEngineException $e) {
        expect($e->failure)->toBe(PdfEngineFailure::Timeout)
            ->and($e->failure->isTransient())->toBeTrue();
    });
    expect(glob($this->workRoot.'/*'))->toBe([]);
});

it('treats a crash, an answer that is not the protocol and an oversized answer as failures', function (string $stdout, int $exit, PdfEngineFailure $failure) {
    Process::fake(['*' => Process::result($stdout, 'Traceback: secret document text', $exit)]);

    expect(fn () => $this->engine->inspect($this->input))->toThrow(function (PdfEngineException $e) use ($failure) {
        expect($e->failure)->toBe($failure)
            ->and($e->getMessage())->not->toContain('secret document text');
    });
})->with([
    'crash without output' => ['', 139, PdfEngineFailure::Crashed],
    'not json' => ['hello', 0, PdfEngineFailure::InvalidResponse],
    'json without ok' => ['{"result":{}}', 0, PdfEngineFailure::InvalidResponse],
    'ok but failing exit' => ['{"ok":true,"result":{}}', 1, PdfEngineFailure::InvalidResponse],
    'oversized' => ['{"ok":true,"result":{"x":"'.str_repeat('a', 2 * 1024 * 1024).'"}}', 0, PdfEngineFailure::InvalidResponse],
]);

it('never copies out a file the helper names outside its work directory', function () {
    $outside = PdfEngineFixtures::write($this->pdfDir, 'outside.pdf', '%PDF-1.7 elsewhere');
    Process::fake(['*' => fn () => Process::result(json_encode(['ok' => true, 'result' => ['output' => $outside, 'bytes' => 1, 'sha256' => 'x']]))]);

    expect(fn () => $this->engine->stamp($this->input, $this->pdfDir.'/out.pdf', [new StampText(1, 1, 1, 'x')]))
        ->toThrow(fn (PdfEngineException $e) => expect($e->failure)->toBe(PdfEngineFailure::InvalidResponse));
    expect(file_exists($this->pdfDir.'/out.pdf'))->toBeFalse();
});

it('copies a stamp image into the work directory and names it there', function () {
    $png = PdfEngineFixtures::png($this->pdfDir);
    s5FakeHelper(function (array $request): array {
        expect(file_exists($request['work_dir'].'/'.$request['items'][1]['path']))->toBeTrue();

        return ['ok' => true, 'result' => []];
    });

    $result = $this->engine->stamp($this->input, $this->pdfDir.'/out.pdf', [
        new StampText(1, 72, 700, 'Anna Andersson', 11),
        new StampImage(1, 72, 600, 120, 40, $png),
    ]);

    expect($result->bytes)->toBe(filesize($this->pdfDir.'/out.pdf'));
    Process::assertRan(fn (PendingProcess $process): bool => json_decode((string) $process->input, true)['items'][1]['path'] === 'image-1.png');
});

it('keeps the original bytes first in every file the fake engine writes', function () {
    $fake = FakePdfEngine::install();
    $engine = app(PdfEngine::class);
    $original = (string) file_get_contents($this->input);

    $stamped = $engine->stamp($this->input, $this->pdfDir.'/1.pdf', [new StampText(1, 1, 1, 'x')]);
    $appended = $engine->appendPages($stamped->path, $this->input, $this->pdfDir.'/2.pdf');
    $sealed = $engine->seal($appended->path, $this->pdfDir.'/3.pdf', new SealRequest(SealLevel::B_B, 'p12', 'pw'));

    expect(str_starts_with((string) file_get_contents($sealed->path), $original))->toBeTrue()
        ->and($engine->validate($sealed->path)->allIntact())->toBeTrue()
        ->and($fake->operations())->toBe(['stamp', 'append', 'seal', 'validate'])
        ->and($engine->inspect($this->input)->pages)->toBe(2)
        ->and($engine->inspect(PdfEngineFixtures::write($this->pdfDir, 'js.pdf', PdfEngineFixtures::withJavaScript()))->refusal())->toBe(PdfRefusal::JavaScript);

    $fake->failNext = new PdfEngineException(PdfEngineFailure::Timeout);
    expect(fn () => $engine->inspect($this->input))->toThrow(PdfEngineException::class);
});

it('caches the health for /hq and the screens, and never caches an engine that is not installed', function () {
    Cache::flush();
    $fake = FakePdfEngine::install();
    $status = app(PdfEngineStatus::class);

    expect($status->usable())->toBeTrue()
        ->and($status->usable())->toBeTrue()
        ->and(array_count_values($fake->operations())['health'])->toBe(1)
        ->and(Cache::get(PdfEngineStatus::CACHE_KEY)['driver'])->toBe('fake');

    Cache::flush();
    $null = new PdfEngineStatus(new NullPdfEngine);
    expect($null->usable())->toBeFalse()
        ->and($null->current()->installed)->toBeFalse()
        ->and(Cache::has(PdfEngineStatus::CACHE_KEY))->toBeFalse();
});

it('reports the engine through signing:engine-doctor with the right exit code', function () {
    app()->instance(PdfEngine::class, new NullPdfEngine);
    $this->artisan('signing:engine-doctor')->expectsOutputToContain('inte installerad')->assertExitCode(2);

    FakePdfEngine::install();
    $this->artisan('signing:engine-doctor')->expectsOutputToContain('OK: PDF-motorn fungerar.')->assertExitCode(0);
    $this->artisan('signing:engine-doctor --json')->expectsOutputToContain('"working": true')->assertExitCode(0);

    s5FakeHelper(fn (array $request): array => $request['op'] === 'health'
        ? ['ok' => true, 'result' => ['versions' => ['python' => '3.12.0', 'pyhanko' => '0.38.1', 'pypdfium2' => '5.14.0', 'protocol' => 1], 'fonts' => ['sans' => true, 'sans-bold' => true, 'cjk' => false, 'cjk-bold' => false]]]
        : ['ok' => true, 'result' => ['checks' => ['inspect' => true, 'seal' => false], 'passed' => false]]);
    app()->instance(PdfEngine::class, $this->engine);
    $this->artisan('signing:engine-doctor')
        ->expectsOutputToContain('Självtestet misslyckades för: seal.')
        ->expectsOutputToContain('Typsnitten för PDF-motorn saknas')
        ->assertExitCode(1);
});

it('warns when the installed libraries are not the pinned ones', function () {
    $script = base_path('tools/pdf-engine/nonox_pdf_engine.py');
    $engine = new SidecarPdfEngine(PHP_BINARY, $script, $this->pdfDir, $this->workRoot, 5);
    s5FakeHelper(['ok' => true, 'result' => ['versions' => ['pyhanko' => '0.1.0', 'pypdfium2' => '5.14.0', 'protocol' => 1], 'fonts' => ['sans' => true, 'sans-bold' => true, 'cjk' => true, 'cjk-bold' => true]]]);

    $health = $engine->health();

    expect($health->working)->toBeTrue()
        ->and(implode(' ', $health->problems))->toContain('pyhanko 0.1.0 är installerat men NONOX är provat med 0.38.1');
});

it('says "Kräver PDF-motorn" with a link to the guide where the engine is missing, and shows the control where it works', function () {
    app()->instance(PdfEngine::class, new NullPdfEngine);
    $without = Blade::render('<x-signing.requires-pdf-engine feature="Fält i PDF:en"><button>Placera fält</button></x-signing.requires-pdf-engine>');

    expect($without)->toContain('Kräver PDF-motorn')
        ->toContain('Fält i PDF:en kräver PDF-motorn')
        ->toContain(route('legal.selfHosting'))
        ->not->toContain('Placera fält');

    Cache::flush();
    FakePdfEngine::install();
    $with = Blade::render('<x-signing.requires-pdf-engine><button>Placera fält</button></x-signing.requires-pdf-engine>');

    expect($with)->toContain('Placera fält')->not->toContain('Kräver PDF-motorn');
});

it('shows the PDF engine on /hq Integrationer for the operator', function () {
    config(['nonox.hq_admins' => ['hq@example.test'], 'app.admin_email' => null]);
    $admin = User::factory()->withCompany()->create(['email' => 'hq@example.test']);
    app()->instance(PdfEngine::class, new NullPdfEngine);

    $this->actingAs($admin)->get(route('hq.integrations'))
        ->assertOk()
        ->assertSee('PDF-motor för signering')
        ->assertSee('Inte installerad')
        ->assertSee('signing:engine-doctor');

    Cache::flush();
    FakePdfEngine::install();
    $this->actingAs($admin)->get(route('hq.integrations'))->assertOk()->assertSee('Fungerar');
});

it('serialises the health without paths or secrets', function () {
    $health = (new FakePdfEngine)->health(true);
    $round = PdfEngineHealth::fromArray($health->toArray());

    expect($round->usable())->toBeTrue()
        ->and($round->canStampCjk())->toBeTrue()
        ->and($round->selfTest)->toBe(['inspect' => true, 'render' => true, 'stamp' => true, 'append' => true, 'seal' => true, 'validation_data' => true]);
});

it('checks the incremental-update promise itself and discards an output that rewrote the original', function () {
    Process::fake(['*' => function (PendingProcess $process) {
        $request = json_decode((string) $process->input, true);
        // A helper that claims success but rewrote the document from the first byte.
        $rewritten = str_replace('Sida 1', 'Sida 9', (string) file_get_contents($request['work_dir'].'/input.pdf'))."\n% stamped\n";
        file_put_contents($request['work_dir'].'/output.pdf', $rewritten);

        return Process::result(json_encode(['ok' => true, 'result' => ['output' => $request['work_dir'].'/output.pdf', 'bytes' => strlen($rewritten), 'sha256' => hash('sha256', $rewritten)]]));
    }]);
    $output = $this->pdfDir.'/out.pdf';

    expect(fn () => $this->engine->stamp($this->input, $output, [new StampText(1, 1, 1, 'x')]))
        ->toThrow(fn (PdfEngineException $e) => expect($e->failure)->toBe(PdfEngineFailure::NotIncremental));
    expect(file_exists($output))->toBeFalse()
        ->and(glob($this->workRoot.'/*'))->toBe([]);
});

it('refuses an output that only repeats the input without adding to it', function () {
    Process::fake(['*' => function (PendingProcess $process) {
        $request = json_decode((string) $process->input, true);
        copy($request['work_dir'].'/input.pdf', $request['work_dir'].'/output.pdf');

        return Process::result(json_encode(['ok' => true, 'result' => ['output' => $request['work_dir'].'/output.pdf']]));
    }]);

    expect(fn () => $this->engine->appendPages($this->input, $this->input, $this->pdfDir.'/out.pdf'))
        ->toThrow(fn (PdfEngineException $e) => expect($e->failure)->toBe(PdfEngineFailure::NotIncremental));
});

it('only contacts the timestamp servers in the instance configuration', function () {
    Process::fake();
    config(['nonox.signing.timestamp.urls' => ['https://tsa.example.test/tsr']]);

    expect(fn () => $this->engine->timestamp($this->input, $this->pdfDir.'/out.pdf', ['https://internal.example.test/']))->toThrow(InvalidArgumentException::class, 'configured timestamp servers')
        ->and(fn () => $this->engine->seal($this->input, $this->pdfDir.'/out.pdf', new SealRequest(SealLevel::B_T, 'p12', 'pw', timestampUrls: ['http://169.254.169.254/'])))->toThrow(InvalidArgumentException::class, 'configured timestamp servers')
        ->and(fn () => (new FakePdfEngine)->timestamp($this->input, $this->pdfDir.'/out.pdf', ['https://internal.example.test/']))->toThrow(InvalidArgumentException::class);
    Process::assertNothingRan();

    s5FakeHelper(['ok' => true, 'result' => []]);
    expect($this->engine->timestamp($this->input, $this->pdfDir.'/out.pdf', ['https://tsa.example.test/tsr'])->bytes)->toBeGreaterThan(0);
});

it('refuses actions that send data out or open other files, like JavaScript', function () {
    $inspection = PdfInspection::fromArray(['bytes' => 100, 'sha256' => 'x', 'pages' => 1, 'external_actions' => true]);
    $submit = PdfEngineFixtures::write($this->pdfDir, 'submit.pdf', (string) file_get_contents(base_path('tests/Fixtures/pdf-engine/submit.pdf')));

    expect($inspection->refusal())->toBe(PdfRefusal::ExternalAction)
        ->and(PdfRefusal::ExternalAction->message())->toContain('godtas inte')
        ->and((new FakePdfEngine)->inspect($submit)->refusal())->toBe(PdfRefusal::ExternalAction);
});

it('never reads a directory as an input', function () {
    Process::fake();
    $directory = $this->pdfDir.'/a-directory.pdf';
    mkdir($directory);

    expect(fn () => $this->engine->inspect($directory))->toThrow(InvalidArgumentException::class);
    Process::assertNothingRan();
});

it('answers at once with the last known health while another request is checking, and never waits', function () {
    Cache::flush();
    $fake = FakePdfEngine::install();
    $status = app(PdfEngineStatus::class);
    $lock = Cache::lock(PdfEngineStatus::LOCK_KEY, 60);
    $lock->get();

    $health = $status->current();

    expect($health->usable())->toBeFalse()
        ->and(implode(' ', $health->problems))->toContain('inte kontrollerad')
        ->and($fake->operations())->toBe([]);

    Cache::put(PdfEngineStatus::LAST_KEY, (new FakePdfEngine)->health()->toArray(), 60);
    expect($status->current()->usable())->toBeTrue();

    $lock->release();
    Cache::forget(PdfEngineStatus::LAST_KEY);
    expect($status->current()->usable())->toBeTrue()
        ->and(Cache::has(PdfEngineStatus::CACHE_KEY))->toBeTrue();
});

it('removes work directories left behind for more than an hour, and nothing else', function () {
    $root = $this->pdfDir.'/engine-work';
    config(['nonox.signing.pdf_engine.work_dir' => $root]);
    mkdir($root);
    $old = $root.'/'.str_repeat('a', 32);
    $fresh = $root.'/'.str_repeat('b', 32);
    $other = $root.'/keep-me';

    foreach ([$old, $fresh, $other] as $directory) {
        mkdir($directory);
        file_put_contents($directory.'/input.pdf', '%PDF-1.7');
    }

    touch($old, time() - 7200);
    touch($other, time() - 7200);

    $this->artisan('signing:prune-engine-work')->expectsOutputToContain('Tog bort 1')->assertExitCode(0);

    expect(is_dir($old))->toBeFalse()
        ->and(is_dir($fresh))->toBeTrue()
        ->and(is_dir($other))->toBeTrue();
});
