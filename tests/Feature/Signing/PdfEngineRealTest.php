<?php

declare(strict_types=1);

use App\Domain\Signing\Pdf\PdfEngineException;
use App\Domain\Signing\Pdf\PdfEngineFailure;
use App\Domain\Signing\Pdf\PdfLimits;
use App\Domain\Signing\Pdf\PdfRefusal;
use App\Domain\Signing\Pdf\SealLevel;
use App\Domain\Signing\Pdf\SealRequest;
use App\Domain\Signing\Pdf\SidecarPdfEngine;
use App\Domain\Signing\Pdf\StampImage;
use App\Domain\Signing\Pdf\StampText;
use Tests\Support\PdfEngineFixtures;

/*
 * The real PDF engine (tools/pdf-engine: pyHanko and pypdfium2, ADR-046
 * amendment 2026-11-18, S5). These tests run the helper itself and skip,
 * with a message, where Python or the pinned libraries are not installed
 * (see tools/pdf-engine/README.md). CI installs them. The timeout and crash
 * tests need only a Python 3.
 */

beforeEach(function () {
    $this->pdfDir = PdfEngineFixtures::directory();
    $this->workRoot = $this->pdfDir.'/work';
});

afterEach(function () {
    PdfEngineFixtures::cleanUp();
});

function s5RealEngine(object $test): SidecarPdfEngine
{
    if (! PdfEngineFixtures::realEngineReady()) {
        test()->markTestSkipped('The PDF engine is not installed here (Python with pyHanko and pypdfium2; see tools/pdf-engine/README.md).');
    }

    return PdfEngineFixtures::realEngine($test->workRoot);
}

function s5Fonts(): bool
{
    return is_file(config('nonox.signing.pdf_engine.fonts_dir').'/NotoSans-Regular.ttf');
}

it('reports the pinned versions and passes its self-test', function () {
    $health = s5RealEngine($this)->health(selfTest: true);
    $lock = (string) file_get_contents(base_path('tools/pdf-engine/requirements.txt'));

    expect($health->installed)->toBeTrue()
        ->and($health->working)->toBeTrue()
        ->and($health->versions['protocol'])->toBe(SidecarPdfEngine::PROTOCOL)
        ->and($lock)->toContain('pyhanko=='.$health->versions['pyhanko'])
        ->and($lock)->toContain('pypdfium2=='.$health->versions['pypdfium2'])
        ->and($health->selfTest['seal'])->toBeTrue()
        ->and($health->selfTest['append'])->toBeTrue();
});

it('inspects pages, sizes and revisions of a plain PDF', function () {
    $engine = s5RealEngine($this);
    $bytes = PdfEngineFixtures::pdf(3);
    $path = PdfEngineFixtures::write($this->pdfDir, 'plain.pdf', $bytes);

    $inspection = $engine->inspect($path);

    expect($inspection->pages)->toBe(3)
        ->and($inspection->sha256)->toBe(hash('sha256', $bytes))
        ->and($inspection->pageSizes[0]['width'])->toBe(595.28)
        ->and($inspection->revisions)->toBe(1)
        ->and($inspection->signatures)->toBe(0)
        ->and($inspection->refusal())->toBeNull();
});

it('finds JavaScript, launch actions and embedded files, so they can be refused', function (string $fixture, PdfRefusal $refusal) {
    $engine = s5RealEngine($this);
    $path = PdfEngineFixtures::write($this->pdfDir, 'active.pdf', PdfEngineFixtures::{$fixture}());

    expect($engine->inspect($path)->refusal())->toBe($refusal);
})->with([
    ['withJavaScript', PdfRefusal::JavaScript],
    ['withLaunchAction', PdfRefusal::LaunchAction],
    ['withEmbeddedFile', PdfRefusal::EmbeddedFiles],
]);

it('finds active content however it is hidden: only through a field parent, with escaped names, through an indirect /S, or as a form submission', function (string $fixture, PdfRefusal $refusal) {
    $engine = s5RealEngine($this);

    expect($engine->inspect(base_path("tests/Fixtures/pdf-engine/{$fixture}"))->refusal())->toBe($refusal);
})->with([
    'JavaScript in a field reachable only via /Parent' => ['parent_only.pdf', PdfRefusal::JavaScript],
    'escaped names /J#53 and /Java#53cript' => ['escaped.pdf', PdfRefusal::JavaScript],
    'an indirect /S' => ['indirect_s.pdf', PdfRefusal::JavaScript],
    'a SubmitForm action' => ['submit.pdf', PdfRefusal::ExternalAction],
]);

it('enforces the page limit when stamping and sealing too', function () {
    $engine = s5RealEngine($this);
    $long = PdfEngineFixtures::write($this->pdfDir, 'long.pdf', PdfEngineFixtures::pdf(PdfLimits::MAX_PAGES + 1));
    $longer = PdfEngineFixtures::write($this->pdfDir, 'longer.pdf', PdfEngineFixtures::pdf(PdfLimits::MAX_PAGES + 21));
    $key = PdfEngineFixtures::pkcs12();

    expect(fn () => $engine->stamp($long, $this->pdfDir.'/out.pdf', [new StampText(1, 72, 700, 'Text')]))
        ->toThrow(fn (PdfEngineException $e) => expect($e->refusal)->toBe(PdfRefusal::TooManyPages))
        ->and(fn () => $engine->seal($longer, $this->pdfDir.'/sealed.pdf', new SealRequest(SealLevel::B_B, $key['pkcs12'], $key['password'])))
        ->toThrow(fn (PdfEngineException $e) => expect($e->refusal)->toBe(PdfRefusal::TooManyPages));
});

it('refuses an encrypted PDF and one with more than 100 pages', function () {
    $engine = s5RealEngine($this);
    $long = PdfEngineFixtures::write($this->pdfDir, 'long.pdf', PdfEngineFixtures::pdf(PdfLimits::MAX_PAGES + 1));

    expect(fn () => $engine->inspect(PdfEngineFixtures::encryptedPath()))->toThrow(fn (PdfEngineException $e) => expect($e->refusal)->toBe(PdfRefusal::Encrypted))
        ->and(fn () => $engine->inspect($long))->toThrow(fn (PdfEngineException $e) => expect($e->refusal)->toBe(PdfRefusal::TooManyPages));
});

it('renders pages as PNG images within the pixel cap', function () {
    $engine = s5RealEngine($this);
    $path = PdfEngineFixtures::write($this->pdfDir, 'plain.pdf', PdfEngineFixtures::pdf(2));
    $out = $this->pdfDir.'/pages';
    mkdir($out);

    $pages = $engine->renderPages($path, $out, [2], scale: 6.0);

    expect($pages)->toHaveCount(1)
        ->and($pages[0]->page)->toBe(2)
        ->and(basename($pages[0]->path))->toBe('page-0002.png')
        ->and(getimagesize($pages[0]->path)[2])->toBe(IMAGETYPE_PNG)
        ->and($pages[0]->widthPx * $pages[0]->heightPx)->toBeLessThanOrEqual(PdfLimits::MAX_PIXELS_PER_PAGE)
        ->and($pages[0]->widthPt)->toBe(595.28);
});

it('stamps text in several scripts and an image as an incremental update that keeps the original bytes', function () {
    $engine = s5RealEngine($this);

    if (! s5Fonts()) {
        $this->markTestSkipped('The fonts are not fetched (scripts/fetch-signing-fonts.sh).');
    }

    $original = PdfEngineFixtures::pdf(2);
    $path = PdfEngineFixtures::write($this->pdfDir, 'plain.pdf', $original);
    $output = $this->pdfDir.'/stamped.pdf';

    $result = $engine->stamp($path, $output, [
        new StampText(1, 72, 700, 'Åsa Öberg · Ελένη · Дарья', 11),
        new StampText(2, 72, 680, '李明 · さくら · 김민준', 11),
        new StampImage(2, 72, 600, 120, 40, PdfEngineFixtures::png($this->pdfDir)),
    ]);
    $written = (string) file_get_contents($output);

    expect(str_starts_with($written, $original))->toBeTrue()
        ->and(strlen($written))->toBeGreaterThan(strlen($original))
        ->and($result->sha256)->toBe(hash('sha256', $written))
        ->and($engine->inspect($output)->revisions)->toBe(2)
        ->and($engine->inspect($output)->pages)->toBe(2);
});

it('says which font is missing instead of stamping in the wrong one', function () {
    $engine = new SidecarPdfEngine(PdfEngineFixtures::python(), base_path('tools/pdf-engine/nonox_pdf_engine.py'), $this->pdfDir.'/no-fonts', $this->workRoot);

    if (! PdfEngineFixtures::realEngineReady()) {
        $this->markTestSkipped('The PDF engine is not installed here.');
    }

    $path = PdfEngineFixtures::write($this->pdfDir, 'plain.pdf', PdfEngineFixtures::pdf());

    expect(fn () => $engine->stamp($path, $this->pdfDir.'/out.pdf', [new StampText(1, 72, 700, 'Text')]))
        ->toThrow(fn (PdfEngineException $e) => expect($e->failure)->toBe(PdfEngineFailure::FontMissing));
    expect(file_exists($this->pdfDir.'/out.pdf'))->toBeFalse();
});

it('appends the certificate pages after the last page and keeps the original bytes', function () {
    $engine = s5RealEngine($this);
    $original = PdfEngineFixtures::pdf(2);
    $path = PdfEngineFixtures::write($this->pdfDir, 'document.pdf', $original);
    $certificate = PdfEngineFixtures::write($this->pdfDir, 'certificate.pdf', PdfEngineFixtures::pdf(2));

    $result = $engine->appendPages($path, $certificate, $this->pdfDir.'/finished.pdf');

    expect($result->pages)->toBe(4)
        ->and(str_starts_with((string) file_get_contents($result->path), $original))->toBeTrue()
        ->and($engine->inspect($result->path)->pages)->toBe(4);
});

it('seals at PAdES B-B with a PKCS#12 seal, and the seal validates as intact and covering the whole file', function () {
    $engine = s5RealEngine($this);
    $original = PdfEngineFixtures::pdf(1);
    $path = PdfEngineFixtures::write($this->pdfDir, 'document.pdf', $original);
    $key = PdfEngineFixtures::pkcs12();

    $sealed = $engine->seal($path, $this->pdfDir.'/sealed.pdf', new SealRequest(SealLevel::B_B, $key['pkcs12'], $key['password'], reason: 'NONOX test'));
    $report = $engine->validate($sealed->path);

    expect($sealed->level)->toBe(SealLevel::B_B)
        ->and($sealed->certificateSha256)->toHaveLength(64)
        ->and(str_starts_with((string) file_get_contents($sealed->path), $original))->toBeTrue()
        ->and($report->allIntact())->toBeTrue()
        ->and($report->signatures[0]['coverage'])->toBe('ENTIRE_FILE')
        ->and($report->signatures[0]['certificate_sha256'])->toBe($sealed->certificateSha256)
        ->and($engine->inspect($sealed->path)->signatures)->toBe(1);
});

it('notices a sealed file that was changed afterwards', function () {
    $engine = s5RealEngine($this);
    $path = PdfEngineFixtures::write($this->pdfDir, 'document.pdf', PdfEngineFixtures::pdf(1));
    $key = PdfEngineFixtures::pkcs12();
    $sealed = $engine->seal($path, $this->pdfDir.'/sealed.pdf', new SealRequest(SealLevel::B_B, $key['pkcs12'], $key['password']));
    $bytes = (string) file_get_contents($sealed->path);
    $tampered = PdfEngineFixtures::write($this->pdfDir, 'tampered.pdf', str_replace('(Sida 1)', '(Sida 9)', $bytes));

    expect($engine->validate($tampered)->allIntact())->toBeFalse();
});

it('refuses a seal whose password is wrong', function () {
    $engine = s5RealEngine($this);
    $path = PdfEngineFixtures::write($this->pdfDir, 'document.pdf', PdfEngineFixtures::pdf(1));
    $key = PdfEngineFixtures::pkcs12();

    expect(fn () => $engine->seal($path, $this->pdfDir.'/sealed.pdf', new SealRequest(SealLevel::B_B, $key['pkcs12'], 'wrong-password')))
        ->toThrow(fn (PdfEngineException $e) => expect($e->failure)->toBe(PdfEngineFailure::BadCredentials));
});

it('reports that no timestamp server answered, so sealing at B-T can be retried later', function () {
    $engine = s5RealEngine($this);
    $path = PdfEngineFixtures::write($this->pdfDir, 'document.pdf', PdfEngineFixtures::pdf(1));
    $key = PdfEngineFixtures::pkcs12();
    // Port 9 (discard) on the loopback address refuses at once; nothing leaves the machine.
    config(['nonox.signing.timestamp.urls' => ['http://127.0.0.1:9/tsa']]);
    $request = new SealRequest(SealLevel::B_T, $key['pkcs12'], $key['password'], timestampUrls: ['http://127.0.0.1:9/tsa'], timestampTimeoutSeconds: 2);

    expect(fn () => $engine->seal($path, $this->pdfDir.'/sealed.pdf', $request))
        ->toThrow(fn (PdfEngineException $e) => expect($e->failure)->toBe(PdfEngineFailure::TimestampUnavailable)->and($e->failure->isTransient())->toBeTrue());
    expect(file_exists($this->pdfDir.'/sealed.pdf'))->toBeFalse();
});

it('stops a helper that runs past the timeout', function () {
    $python = PdfEngineFixtures::anyPython() ?? $this->markTestSkipped('No Python 3 here.');
    $engine = new SidecarPdfEngine($python, base_path('tests/Fixtures/pdf-engine/sleeper.py'), $this->pdfDir, $this->workRoot, timeoutSeconds: 1);
    $path = PdfEngineFixtures::write($this->pdfDir, 'document.pdf', PdfEngineFixtures::pdf(1));
    $started = microtime(true);

    expect(fn () => $engine->inspect($path))->toThrow(fn (PdfEngineException $e) => expect($e->failure)->toBe(PdfEngineFailure::Timeout));
    expect(microtime(true) - $started)->toBeLessThan(10)
        ->and(glob($this->workRoot.'/*'))->toBe([]);
});

it('stops a helper that floods its output, on either stream, without waiting for the timeout', function (string $script) {
    $python = PdfEngineFixtures::anyPython() ?? $this->markTestSkipped('No Python 3 here.');
    $engine = new SidecarPdfEngine($python, base_path("tests/Fixtures/pdf-engine/{$script}"), $this->pdfDir, $this->workRoot, timeoutSeconds: 20);
    $path = PdfEngineFixtures::write($this->pdfDir, 'document.pdf', PdfEngineFixtures::pdf(1));
    $started = microtime(true);

    expect(fn () => $engine->inspect($path))->toThrow(fn (PdfEngineException $e) => expect($e->failure)->toBe(PdfEngineFailure::InvalidResponse));
    expect(microtime(true) - $started)->toBeLessThan(10)
        ->and(glob($this->workRoot.'/*'))->toBe([]);
})->with(['flood_stdout.py', 'flood_stderr.py']);

it('treats a helper that dies without an answer as crashed and keeps its output out of the message', function () {
    $python = PdfEngineFixtures::anyPython() ?? $this->markTestSkipped('No Python 3 here.');
    $engine = new SidecarPdfEngine($python, base_path('tests/Fixtures/pdf-engine/crasher.py'), $this->pdfDir, $this->workRoot);
    $path = PdfEngineFixtures::write($this->pdfDir, 'document.pdf', PdfEngineFixtures::pdf(1));

    expect(fn () => $engine->inspect($path))->toThrow(function (PdfEngineException $e) {
        expect($e->failure)->toBe(PdfEngineFailure::Crashed)
            ->and($e->getMessage())->not->toContain('secret document');
    });
});
