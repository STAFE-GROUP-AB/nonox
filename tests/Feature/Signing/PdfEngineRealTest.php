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
use App\Domain\Signing\Pdf\TimestampFact;
use Symfony\Component\Process\Process;
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
        ->and($health->selfTest['append'])->toBeTrue()
        ->and($health->selfTest['validation_data'])->toBeTrue();
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

it('stamps text inside a field box, shrunk to fit and clipped there (ADR-046 S6)', function () {
    $engine = s5RealEngine($this);

    if (! s5Fonts()) {
        $this->markTestSkipped('The fonts are not fetched (scripts/fetch-signing-fonts.sh).');
    }

    $original = PdfEngineFixtures::pdf(1);
    $path = PdfEngineFixtures::write($this->pdfDir, 'plain.pdf', $original);
    $output = $this->pdfDir.'/boxed.pdf';

    // Far wider than its 40-point box at 11 points: the engine must shrink it and clip it, not fail.
    $result = $engine->stamp($path, $output, [
        new StampText(1, 72, 700, 'Västerhaninge sjöstad och omnejd', 11, boxWidth: 40, boxHeight: 14),
    ]);
    $written = (string) file_get_contents($output);

    expect(str_starts_with($written, $original))->toBeTrue()
        ->and($result->sha256)->toBe(hash('sha256', $written))
        ->and($engine->inspect($output)->revisions)->toBe(2)
        ->and(substr($written, strlen($original)))->toContain('/BBox');
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

it('stamps from the crop box: a stamp at (0, 0) on a cropped page lands in its visible lower left corner (ADR-046 S8)', function () {
    $engine = s5RealEngine($this);

    if (! s5Fonts()) {
        $this->markTestSkipped('The fonts are not fetched (scripts/fetch-signing-fonts.sh).');
    }

    $path = PdfEngineFixtures::write($this->pdfDir, 'cropped.pdf', PdfEngineFixtures::pdf(1, pageExtra: '/CropBox [50 50 400 600]'));
    $output = $this->pdfDir.'/cropped-stamped.pdf';
    $out = $this->pdfDir.'/pages';
    mkdir($out);

    $engine->stamp($path, $output, [new StampText(1, 0, 0, 'MMMM', 12, boxWidth: 40, boxHeight: 16)]);
    $page = $engine->renderPages($output, $out, [1], scale: 1.0)[0];
    $image = imagecreatefrompng($page->path);
    $dark = 0;

    // The rendered page is the crop box (350 x 550 points): the stamp's 40 x 16 box is in its lower left corner.
    for ($x = 0; $x < 45; $x++) {
        for ($y = $page->heightPx - 20; $y < $page->heightPx; $y++) {
            $dark += (imagecolorat($image, $x, $y) & 0xFF) < 128 ? 1 : 0;
        }
    }

    expect($page->widthPt)->toBe(350.0)->and($page->heightPt)->toBe(550.0)->and($dark)->toBeGreaterThan(10);
});

/**
 * A throwaway RFC 3161 server on 127.0.0.1 (tests/Support/pdf-engine/test_tsa.py, ADR-046 S11) for the
 * long-term validation tests; stopped after the test. Its URL is configured as the instance's only server.
 */
function s11TestTsa(object $test, int $days = 30): string
{
    $process = new Process([PdfEngineFixtures::python(), '-I', base_path('tests/Support/pdf-engine/test_tsa.py'), (string) $days]);
    $process->start();
    $port = null;

    foreach (range(1, 300) as $ignored) {
        if (preg_match('/^(\d+)\n/', $process->getOutput(), $match) === 1) {
            $port = (int) $match[1];

            break;
        }

        if (! $process->isRunning()) {
            break;
        }

        usleep(20_000);
    }

    if ($port === null) {
        $process->stop(0);
        $test->markTestSkipped('The test timestamp server did not start.');
    }

    $test->tsa = $process;
    $url = "http://127.0.0.1:{$port}/";
    config(['nonox.signing.timestamp.urls' => [$url]]);

    return $url;
}

afterEach(function () {
    if (isset($this->tsa) && $this->tsa instanceof Process) {
        $this->tsa->stop(0);
    }
});

it('adds validation data (B-LT) and document timestamps (B-LTA) as incremental updates that keep every earlier file and validate (ADR-046 S11)', function () {
    $engine = s5RealEngine($this);
    $url = s11TestTsa($this, days: 40);
    $path = PdfEngineFixtures::write($this->pdfDir, 'document.pdf', PdfEngineFixtures::pdf(1));
    $key = PdfEngineFixtures::pkcs12();

    $sealed = $engine->seal($path, $this->pdfDir.'/sealed.pdf', new SealRequest(SealLevel::B_T, $key['pkcs12'], $key['password'], timestampUrls: [$url]));
    $lt = $engine->addValidationData($sealed->path, $this->pdfDir.'/lt.pdf');
    $lta = $engine->archiveTimestamp($lt->path, $this->pdfDir.'/lta.pdf', [$url]);
    $renewed = $engine->archiveTimestamp($lta->path, $this->pdfDir.'/lta2.pdf', [$url], 'sha384');

    $bytes = fn (string $file): string => (string) file_get_contents($file);
    $report = $engine->validate($renewed->path);

    expect($sealed->level)->toBe(SealLevel::B_T)
        ->and(str_starts_with($bytes($lt->path), $bytes($sealed->path)))->toBeTrue()
        ->and(str_starts_with($bytes($lta->path), $bytes($lt->path)))->toBeTrue()
        ->and(str_starts_with($bytes($renewed->path), $bytes($lta->path)))->toBeTrue()
        ->and($lt->sha256)->toBe(hash('sha256', $bytes($lt->path)))
        ->and(str_contains($bytes($lt->path), '/DSS'))->toBeTrue()
        ->and(str_contains($bytes($lt->path), '/VRI'))->toBeTrue()
        ->and($lt->timestamps)->toHaveCount(1)
        ->and($lt->newest()?->kind)->toBe(TimestampFact::SIGNATURE)
        ->and($lt->newest()?->certificateNotAfter->greaterThan(now()->addDays(39)))->toBeTrue()
        ->and($lta->timestampUrl)->toBe($url)
        ->and($lta->newest()?->kind)->toBe(TimestampFact::DOCUMENT)
        ->and($renewed->timestamps)->toHaveCount(3)
        ->and($renewed->newest()?->mdAlgorithm)->toBe('sha384')
        ->and($report->allIntact())->toBeTrue()
        ->and(collect($report->signatures)->pluck('type')->all())->toBe(['signature', 'timestamp', 'timestamp']);
});

it('reports that no timestamp server answered for a document timestamp, so the renewal is tried again (ADR-046 S11)', function () {
    $engine = s5RealEngine($this);
    $url = s11TestTsa($this);
    $path = PdfEngineFixtures::write($this->pdfDir, 'document.pdf', PdfEngineFixtures::pdf(1));
    $key = PdfEngineFixtures::pkcs12();
    $sealed = $engine->seal($path, $this->pdfDir.'/sealed.pdf', new SealRequest(SealLevel::B_T, $key['pkcs12'], $key['password'], timestampUrls: [$url]));
    config(['nonox.signing.timestamp.urls' => ['http://127.0.0.1:9/tsa'], 'nonox.signing.timestamp.timeout' => 2]);

    expect(fn () => $engine->archiveTimestamp($sealed->path, $this->pdfDir.'/lta.pdf', ['http://127.0.0.1:9/tsa']))
        ->toThrow(fn (PdfEngineException $e) => expect($e->failure)->toBe(PdfEngineFailure::TimestampUnavailable));
    expect(file_exists($this->pdfDir.'/lta.pdf'))->toBeFalse();
});

it('writes nothing when a revocation address a certificate names cannot be used, here a loopback address (ADR-046 S11)', function () {
    $engine = s5RealEngine($this);
    $path = PdfEngineFixtures::write($this->pdfDir, 'document.pdf', PdfEngineFixtures::pdf(1));
    // A seal certificate issued by a test CA, naming an OCSP responder on the loopback address (port 9 refuses).
    $caKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $caCsr = openssl_csr_new(['commonName' => 'NONOX test CA'], $caKey, ['digest_alg' => 'sha256']);
    $config = tempnam($this->pdfDir, 'openssl');
    file_put_contents($config, "[ca]\nbasicConstraints=critical,CA:TRUE\n[leaf]\nauthorityInfoAccess=OCSP;URI:http://127.0.0.1:9/ocsp\n");
    $ca = openssl_csr_sign($caCsr, null, $caKey, 30, ['digest_alg' => 'sha256', 'config' => $config, 'x509_extensions' => 'ca']);
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $csr = openssl_csr_new(['commonName' => 'NONOX test seal'], $key, ['digest_alg' => 'sha256']);
    $leaf = openssl_csr_sign($csr, $ca, $caKey, 10, ['digest_alg' => 'sha256', 'config' => $config, 'x509_extensions' => 'leaf']);
    openssl_pkcs12_export($leaf, $pkcs12, $key, 'pw', ['extracerts' => [$ca]]);

    $sealed = $engine->seal($path, $this->pdfDir.'/sealed.pdf', new SealRequest(SealLevel::B_B, (string) $pkcs12, 'pw'));

    expect(fn () => $engine->addValidationData($sealed->path, $this->pdfDir.'/lt.pdf'))
        ->toThrow(fn (PdfEngineException $e) => expect($e->failure)->toBe(PdfEngineFailure::ValidationDataUnavailable)->and($e->failure->isTransient())->toBeTrue());
    expect(file_exists($this->pdfDir.'/lt.pdf'))->toBeFalse();
});
