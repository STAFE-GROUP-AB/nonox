<?php

declare(strict_types=1);

use App\Actions\Hq\UpdateInstanceSettings;
use App\Domain\Signing\Pdf\BubblewrapSandbox;
use App\Domain\Signing\Pdf\FakePdfEngine;
use App\Domain\Signing\Pdf\PdfEngineException;
use App\Domain\Signing\Pdf\PdfEngineHealth;
use App\Domain\Signing\Pdf\PdfEngineStatus;
use App\Domain\Signing\Pdf\SealLevel;
use App\Domain\Signing\Pdf\SealRequest;
use App\Domain\Signing\Pdf\SidecarPdfEngine;
use App\Domain\Signing\Seal\Sealing;
use App\Models\User;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;
use Tests\Support\PdfEngineFixtures;

/*
 * The PDF engine's optional OS sandbox (queue item 31). A real bwrap run needs
 * Linux and the package, so these tests check the argument list, the
 * configuration and the gates; the real run is skipped elsewhere.
 */

beforeEach(function () {
    $this->pdfDir = PdfEngineFixtures::directory();
    $this->script = PdfEngineFixtures::write($this->pdfDir, 'engine.py', '# fake');
    $this->fonts = $this->pdfDir.'/fonts';
    $this->work = $this->pdfDir.'/work/abc';
});

afterEach(function () {
    PdfEngineFixtures::cleanUp();
});

function sandboxHealthAnswer(): void
{
    Process::fake(['*' => fn (PendingProcess $process) => Process::result(json_encode(['ok' => true, 'result' => [
        'versions' => ['python' => '3.12.0', 'pyhanko' => '0', 'pypdfium2' => '0', 'protocol' => 1],
        'fonts' => ['sans' => true, 'sans-bold' => true, 'cjk' => false, 'cjk-bold' => false],
    ]]), '', 0)]);
}

it('builds a bwrap argument list that binds only the work directory read-write and has no network by default', function () {
    $python = $this->pdfDir.'/venv/bin/python';
    $paths = BubblewrapSandbox::pathsFor($python, $this->script, $this->fonts);
    $argv = (new BubblewrapSandbox('/usr/bin/bwrap', $paths))->command($this->work, [$python, '-I', $this->script]);
    $line = implode(' ', $argv);

    expect($argv[0])->toBe('/usr/bin/bwrap')
        ->and($argv)->toContain('--unshare-all', '--die-with-parent', '--new-session', '--clearenv')
        ->and($argv)->not->toContain('--share-net')
        ->and($line)->toContain('--ro-bind-try /etc/ld.so.cache /etc/ld.so.cache')
        ->and($line)->toContain('--ro-bind-try /etc/localtime /etc/localtime')
        ->and($line)->toContain('--bind '.$this->work.' '.$this->work)
        ->and($line)->toContain('--chdir '.$this->work)
        ->and($line)->toContain('--ro-bind-try '.$this->pdfDir.'/venv '.$this->pdfDir.'/venv')
        ->and($line)->toContain('--ro-bind-try '.dirname($this->script).' '.dirname($this->script))
        ->and($line)->toContain('--ro-bind-try '.$this->fonts.' '.$this->fonts)
        ->and($line)->toContain('--ro-bind-try /usr /usr')
        ->and(array_slice($argv, -3))->toBe([$python, '-I', $this->script]);

    expect($argv)->not->toContain(base_path())->not->toContain(base_path('.env'))->not->toContain(storage_path())->not->toContain(storage_path('app'));

    $writable = [];
    foreach ($argv as $i => $arg) {
        if ($arg === '--bind') {
            $writable[] = $argv[$i + 1];
        }
    }
    expect($writable)->toBe([$this->work]);
});

it('shares the network only when asked', function () {
    $sandbox = new BubblewrapSandbox('/usr/bin/bwrap', []);

    expect($sandbox->command($this->work, ['x'], true))->toContain('--share-net')
        ->and($sandbox->command($this->work, ['x'], false))->not->toContain('--share-net');
});

it('refuses read-only paths that are, or contain, the application, storage, .env or a home directory', function () {
    $protected = ['/home/forge/site/current', '/home/forge/site/current/.env', '/home/forge/site/current/storage', '/home/forge'];
    $allowed = '/home/forge/site/current/tools/pdf-engine';

    expect(BubblewrapSandbox::unsafePath(['/home/forge'], $protected, $allowed))->toBe('/home/forge')
        ->and(BubblewrapSandbox::unsafePath(['/home/forge/site'], $protected, $allowed))->toBe('/home/forge/site')
        ->and(BubblewrapSandbox::unsafePath(['/home/forge/site/current'], $protected, $allowed))->not->toBeNull()
        ->and(BubblewrapSandbox::unsafePath(['/home/forge/site/current/storage/app'], $protected, $allowed))->toBeNull()
        ->and(BubblewrapSandbox::unsafePath(['/home/forge/site/current/tools/pdf-engine/.venv'], $protected, $allowed))->toBeNull()
        ->and(BubblewrapSandbox::unsafePath([$allowed], $protected, $allowed))->toBeNull()
        ->and(BubblewrapSandbox::unsafePath(['/usr', '/opt/python'], $protected, $allowed))->toBeNull();
});

it('reports an exposing Python location as a health problem and never starts the helper', function () {
    Process::fake();
    $bwrap = PdfEngineFixtures::write($this->pdfDir, 'bwrap', "#!/bin/sh\n");
    chmod($bwrap, 0755);
    // A "python" whose environment root is the application's own base path.
    $engine = new SidecarPdfEngine(base_path('bin-python-stand-in/x/python'), $this->script, $this->fonts, $this->pdfDir.'/work', 5, 2048, 'bwrap', $bwrap);
    expect($engine->health()->usable())->toBeFalse();

    $home = new SidecarPdfEngine(PHP_BINARY, $this->script, $this->fonts, $this->pdfDir.'/work', 5, 2048, 'bwrap', $bwrap);
    putenv('HOME='.dirname(PHP_BINARY, 2));
    $health = $home->health();
    putenv('HOME');

    expect($health->usable())->toBeFalse()->and($health->problems[0])->toContain('Sandlådan skulle göra');
    Process::assertNothingRan();
})->skip(fn (): bool => ! is_file(PHP_BINARY));

it('gives network only to the operations that need it', function () {
    $bwrap = PdfEngineFixtures::write($this->pdfDir, 'bwrap', "#!/bin/sh\n");
    chmod($bwrap, 0755);
    config(['nonox.signing.timestamp.urls' => ['https://tsa.example.test']]);
    $input = PdfEngineFixtures::write($this->pdfDir, 'in.pdf', PdfEngineFixtures::pdf(1));
    $engine = new SidecarPdfEngine(PHP_BINARY, $this->script, $this->fonts, $this->pdfDir.'/work', 5, 2048, 'bwrap', $bwrap);
    $ran = [];
    Process::fake(['*' => function (PendingProcess $process) use (&$ran) {
        $ran[] = in_array('--share-net', $process->command, true);

        return Process::result('{}', '', 1);
    }]);

    $attempt = function (Closure $call): void {
        try {
            $call();
        } catch (Throwable) {
        }
    };

    $attempt(fn () => $engine->inspect($input));
    $attempt(fn () => $engine->seal($input, $this->pdfDir.'/o1.pdf', new SealRequest(SealLevel::B_B, 'p12', 'pw')));
    $attempt(fn () => $engine->seal($input, $this->pdfDir.'/o2.pdf', new SealRequest(SealLevel::B_T, 'p12', 'pw', timestampUrls: ['https://tsa.example.test'])));
    $attempt(fn () => $engine->timestamp($input, $this->pdfDir.'/o3.pdf', ['https://tsa.example.test']));
    $attempt(fn () => $engine->addValidationData($input, $this->pdfDir.'/o4.pdf'));

    expect($ran)->toBe([false, false, true, true, true]);
});

it('throws and starts no process for a write operation when bwrap is configured but missing', function () {
    Process::fake();
    $input = PdfEngineFixtures::write($this->pdfDir, 'in.pdf', PdfEngineFixtures::pdf(1));
    $engine = new SidecarPdfEngine(PHP_BINARY, $this->script, $this->fonts, $this->pdfDir.'/work', 5, 2048, 'bwrap', $this->pdfDir.'/no-bwrap');

    expect(fn () => $engine->seal($input, $this->pdfDir.'/out.pdf', new SealRequest(SealLevel::B_B, 'p12', 'pw')))->toThrow(PdfEngineException::class);
    Process::assertNothingRan();
});

it('keeps the plain command when the sandbox is none', function () {
    sandboxHealthAnswer();
    $engine = new SidecarPdfEngine(PHP_BINARY, $this->script, $this->fonts, $this->pdfDir.'/work', 5);

    $health = $engine->health();

    expect($health->sandboxed)->toBeFalse();
    Process::assertRan(fn (PendingProcess $process): bool => $process->command === [PHP_BINARY, '-I', $this->script]);
});

it('wraps the helper in bwrap when the sandbox is on and reports it as sandboxed', function () {
    sandboxHealthAnswer();
    $bwrap = PdfEngineFixtures::write($this->pdfDir, 'bwrap', "#!/bin/sh\n");
    chmod($bwrap, 0755);
    $engine = new SidecarPdfEngine(PHP_BINARY, $this->script, $this->fonts, $this->pdfDir.'/work', 5, 2048, 'bwrap', $bwrap);

    $health = $engine->health();

    expect($health->sandboxed)->toBeTrue();
    Process::assertRan(fn (PendingProcess $process): bool => $process->command[0] === $bwrap
        && ! in_array('--share-net', $process->command, true)
        && array_slice($process->command, -3) === [PHP_BINARY, '-I', $this->script]);
});

it('is not usable and says so in Swedish when bwrap is configured but missing, and starts no process', function () {
    Process::fake();
    $engine = new SidecarPdfEngine(PHP_BINARY, $this->script, $this->fonts, $this->pdfDir.'/work', 5, 2048, 'bwrap', $this->pdfDir.'/no-bwrap');

    $health = $engine->health();

    expect($health->usable())->toBeFalse()
        ->and($health->sandboxed)->toBeFalse()
        ->and($health->problems[0])->toContain('bubblewrap');
    Process::assertNothingRan();
});

it('keeps sandboxed in the cached health array and reads an old array as not sandboxed', function () {
    $health = new PdfEngineHealth('sidecar', true, true, sandboxed: true);

    expect(PdfEngineHealth::fromArray($health->toArray())->sandboxed)->toBeTrue();

    $old = $health->toArray();
    unset($old['sandboxed']);
    expect(PdfEngineHealth::fromArray($old)->sandboxed)->toBeFalse();
});

it('gates sealing on the sandbox only where the installation requires it', function () {
    FakePdfEngine::install();
    Cache::forget(PdfEngineStatus::CACHE_KEY);

    config(['nonox.signing.pdf_engine.require_sandbox_for_seal' => false, 'nonox.signing.pdf_engine.sandbox' => 'none']);
    expect(Sealing::sandboxProblem())->toBeNull();

    config(['nonox.signing.pdf_engine.require_sandbox_for_seal' => true]);
    expect(Sealing::sandboxProblem())->toBe('Försegling kräver att PDF-motorn körs i en sandlåda.')
        ->and(Sealing::sandboxProblem(checkEngine: false))->not->toBeNull();

    config(['nonox.signing.pdf_engine.sandbox' => 'bwrap']);
    expect(Sealing::sandboxProblem(checkEngine: false))->toBeNull()
        ->and(Sealing::sandboxProblem())->not->toBeNull();
});

it('refuses to switch sealing on in /hq when the sandbox is required and missing, and allows it otherwise', function () {
    FakePdfEngine::install();
    $admin = User::factory()->create(['email' => 'hq-sandbox@example.test']);
    config(['nonox.hq_admins' => ['hq-sandbox@example.test'], 'app.admin_email' => null, 'nonox.signing.pdf_engine.require_sandbox_for_seal' => true, 'nonox.signing.pdf_engine.sandbox' => 'none']);
    $input = ['waitlist_open' => true, 'registration_open' => false, 'signing_seal_enabled' => true];

    expect(fn () => app(UpdateInstanceSettings::class)($admin, $input))->toThrow(ValidationException::class);

    config(['nonox.signing.pdf_engine.require_sandbox_for_seal' => false]);
    expect(app(UpdateInstanceSettings::class)($admin, $input))->not->toBe([]);
});

it('runs a real command in the sandbox on Linux: no .env, no writes outside the work directory, no network', function () {
    $python = (string) config('nonox.signing.pdf_engine.python');
    $work = PdfEngineFixtures::directory();
    $paths = BubblewrapSandbox::pathsFor($python, base_path('tools/pdf-engine/nonox_pdf_engine.py'), (string) config('nonox.signing.pdf_engine.fonts_dir'));
    $code = implode("\n", [
        'import os, socket, sys',
        'r = {}',
        'try:',
        '    open(sys.argv[1]).read(); r["env"] = "READ"',
        'except OSError:',
        '    r["env"] = "denied"',
        'try:',
        '    open(sys.argv[2], "w").write("x"); r["write"] = "WROTE"',
        'except OSError:',
        '    r["write"] = "denied"',
        'open("inside.txt", "w").write("x"); r["inside"] = "ok"',
        's = socket.socket(); s.settimeout(2)',
        'try:',
        '    s.connect(("127.0.0.1", 80)); r["net"] = "CONNECTED"',
        'except ConnectionRefusedError:',
        '    r["net"] = "CONNECTED"',
        'except OSError:',
        '    r["net"] = "unreachable"',
        'print(r)',
    ]);
    $argv = (new BubblewrapSandbox((string) config('nonox.signing.pdf_engine.bwrap_path'), $paths))->command($work, [$python, '-I', '-c', $code, base_path('.env'), base_path('sandbox-write-probe.txt')], false);

    $result = Process::path($work)->run($argv);

    expect($result->output())->toContain("'env': 'denied'")->toContain("'write': 'denied'")->toContain("'inside': 'ok'")->toContain("'net': 'unreachable'")
        ->and(file_exists(base_path('sandbox-write-probe.txt')))->toBeFalse();
})->skip(fn (): bool => PHP_OS_FAMILY !== 'Linux' || ! is_executable((string) config('nonox.signing.pdf_engine.bwrap_path')) || config('nonox.signing.pdf_engine.sandbox') !== 'bwrap', 'Needs Linux, bubblewrap and NONOX_PDF_ENGINE_SANDBOX=bwrap.');
