<?php

declare(strict_types=1);

use App\Actions\Hq\UpdateInstanceSettings;
use App\Domain\Signing\Pdf\BubblewrapSandbox;
use App\Domain\Signing\Pdf\FakePdfEngine;
use App\Domain\Signing\Pdf\PdfEngineHealth;
use App\Domain\Signing\Pdf\PdfEngineStatus;
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

it('builds a bwrap argument list that binds only the work directory read-write and shares the network', function () {
    $python = $this->pdfDir.'/venv/bin/python';
    $paths = BubblewrapSandbox::pathsFor($python, $this->script, $this->fonts);
    $argv = (new BubblewrapSandbox('/usr/bin/bwrap', $paths))->command($this->work, [$python, '-I', $this->script]);
    $line = implode(' ', $argv);

    expect($argv[0])->toBe('/usr/bin/bwrap')
        ->and($argv)->toContain('--unshare-all', '--share-net', '--die-with-parent', '--new-session', '--clearenv')
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
        && in_array('--share-net', $process->command, true)
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

it('runs the helper in a real sandbox on Linux with bubblewrap', function () {
    expect(SidecarPdfEngine::fromConfig()->health()->sandboxed)->toBeTrue();
})->skip(fn (): bool => PHP_OS_FAMILY !== 'Linux' || ! is_executable((string) config('nonox.signing.pdf_engine.bwrap_path')) || config('nonox.signing.pdf_engine.sandbox') !== 'bwrap', 'Needs Linux, bubblewrap and NONOX_PDF_ENGINE_SANDBOX=bwrap.');
