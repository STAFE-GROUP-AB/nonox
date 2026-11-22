<?php

declare(strict_types=1);

namespace App\Domain\Signing\Pdf;

use InvalidArgumentException;

/**
 * The PDF engine of the test suite: no Python, no PDF library, the same
 * contract. It keeps the one promise every caller relies on (an output
 * starts with the input's bytes) by appending a PDF comment line per
 * operation, records every call, and can be told to fail the next one.
 *
 * Use {@see install()} in a test to bind it in the container.
 */
final class FakePdfEngine implements PdfEngine
{
    /** A 1 × 1 white PNG, written for every rendered page. */
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGP4//8/AAX+Av4N70a4AAAAAElFTkSuQmCC';

    /** @var list<array{operation: string, input: ?string}> */
    public array $calls = [];

    public ?PdfEngineException $failNext = null;

    /** @var list<array<string, mixed>> every item stamped, as the helper's protocol describes it */
    public array $stamped = [];

    public bool $installed = true;

    /** Thrown by seal() for a level that needs a timestamp: a timestamp server that does not answer (ADR-046 S10 tests). */
    public ?PdfEngineException $failTimestampedSeal = null;

    /** @var list<array{level: string, timestampUrls: list<string>, fieldName: string}> every seal asked for, without its secrets */
    public array $sealed = [];

    public static function install(): self
    {
        $fake = new self;
        app()->instance(PdfEngine::class, $fake);

        return $fake;
    }

    public function driver(): string
    {
        return 'fake';
    }

    public function isInstalled(): bool
    {
        return $this->installed;
    }

    public function health(bool $selfTest = false): PdfEngineHealth
    {
        $this->record('health', null);

        if (! $this->installed) {
            return new PdfEngineHealth('fake', false, false, problems: [PdfEngineFailure::NotInstalled->message()], checkedAt: now()->toIso8601String());
        }

        $checks = $selfTest ? ['inspect' => true, 'render' => true, 'stamp' => true, 'append' => true, 'seal' => true] : null;

        return new PdfEngineHealth(
            driver: 'fake',
            installed: true,
            working: true,
            versions: ['python' => 'fake', 'pyhanko' => 'fake', 'pypdfium2' => 'fake', 'protocol' => SidecarPdfEngine::PROTOCOL],
            fonts: ['sans' => true, 'sans-bold' => true, 'cjk' => true, 'cjk-bold' => true],
            selfTest: $checks,
            checkedAt: now()->toIso8601String(),
        );
    }

    public function inspect(string $pdfPath): PdfInspection
    {
        $bytes = $this->read('inspect', $pdfPath, PdfLimits::MAX_BYTES);

        if (str_contains($bytes, '/Encrypt')) {
            throw PdfEngineException::refused(PdfRefusal::Encrypted);
        }

        $pages = preg_match_all('#/Type\s*/Page(?![A-Za-z])#', $bytes);

        if ($pages > PdfLimits::MAX_PAGES) {
            throw PdfEngineException::refused(PdfRefusal::TooManyPages);
        }

        if ($pages < 1) {
            throw PdfEngineException::refused(PdfRefusal::Unreadable);
        }

        return new PdfInspection(
            bytes: strlen($bytes),
            sha256: hash('sha256', $bytes),
            pages: $pages,
            pageSizes: array_fill(0, $pages, ['width' => 595.28, 'height' => 841.89, 'rotation' => 0]),
            pdfVersion: preg_match('/^%PDF-(\d\.\d)/', $bytes, $match) === 1 ? $match[1] : null,
            revisions: max(1, substr_count($bytes, '%%EOF')),
            signatures: substr_count($bytes, '% nonox-fake-seal'),
            javascript: str_contains($bytes, '/JavaScript') || str_contains($bytes, '/JS '),
            launchActions: str_contains($bytes, '/Launch'),
            embeddedFiles: str_contains($bytes, '/EmbeddedFile'),
            xfa: str_contains($bytes, '/XFA'),
            externalActions: preg_match('#/(SubmitForm|ImportData|GoToR|GoToE|RichMedia)(?![A-Za-z])#', $bytes) === 1,
        );
    }

    public function renderPages(string $pdfPath, string $outputDirectory, ?array $pages = null, float $scale = 1.5): array
    {
        $inspection = $this->inspect($pdfPath);
        array_pop($this->calls);
        $this->record('render_pages', $pdfPath);
        $rendered = [];

        foreach ($pages ?? range(1, $inspection->pages) as $page) {
            if ($page < 1 || $page > $inspection->pages) {
                throw new PdfEngineException(PdfEngineFailure::InvalidRequest);
            }

            $path = $outputDirectory.DIRECTORY_SEPARATOR.sprintf('page-%04d.png', $page);
            file_put_contents($path, base64_decode(self::PNG));
            $rendered[] = new RenderedPage($page, $path, 1, 1, 595.28, 841.89);
        }

        return $rendered;
    }

    public function stamp(string $pdfPath, string $outputPath, array $items): PdfFileResult
    {
        if ($items === []) {
            throw new InvalidArgumentException('Stamp at least one item.');
        }

        $file = $this->append('stamp', $pdfPath, $outputPath, (string) count($items));

        foreach ($items as $item) {
            $this->stamped[] = $item->toRequest();
        }

        return $file;
    }

    public function appendPages(string $pdfPath, string $appendixPath, string $outputPath): PdfFileResult
    {
        $this->read('append_pages', $appendixPath, PdfLimits::MAX_BYTES);
        array_pop($this->calls);

        return $this->append('append', $pdfPath, $outputPath, hash_file('sha256', $appendixPath) ?: '');
    }

    public function seal(string $pdfPath, string $outputPath, SealRequest $request): SealResult
    {
        TimestampServers::assertAllowed($request->timestampUrls);

        $timestampFailure = $this->failTimestampedSeal;

        if ($request->level->needsTimestamp() && $timestampFailure instanceof PdfEngineException) {
            $this->record('seal', $pdfPath);

            throw $timestampFailure;
        }

        $file = $this->append('seal', $pdfPath, $outputPath, $request->level->value);
        $this->sealed[] = ['level' => $request->level->value, 'timestampUrls' => $request->timestampUrls, 'fieldName' => $request->fieldName];

        // The certificate's own fingerprint, as the helper answers, when the PKCS#12 opens with its password.
        $bundle = [];
        $fingerprint = @openssl_pkcs12_read($request->pkcs12, $bundle, $request->password) && isset($bundle['cert']) && is_string($bundle['cert'])
            ? (string) openssl_x509_fingerprint($bundle['cert'], 'sha256')
            : hash('sha256', $request->pkcs12);

        return new SealResult($file->path, $file->bytes, $file->sha256, $request->level, $fingerprint, $request->timestampUrls[0] ?? null);
    }

    public function timestamp(string $pdfPath, string $outputPath, array $timestampUrls): PdfFileResult
    {
        if ($timestampUrls === []) {
            throw new InvalidArgumentException('Give at least one timestamp URL.');
        }

        TimestampServers::assertAllowed($timestampUrls);
        $file = $this->append('timestamp', $pdfPath, $outputPath, 'doc');

        return new PdfFileResult($file->path, $file->bytes, $file->sha256, timestampUrl: $timestampUrls[0]);
    }

    public function validate(string $pdfPath): ValidationReport
    {
        $bytes = $this->read('validate', $pdfPath, PdfLimits::MAX_BYTES + PdfLimits::FINISHED_FILE_SLACK_BYTES);
        $signatures = [];

        foreach (['seal' => 'signature', 'timestamp' => 'timestamp'] as $marker => $type) {
            $count = substr_count($bytes, "% nonox-fake-{$marker}");

            for ($index = 1; $index <= $count; $index++) {
                $signatures[] = ['field' => "Fake{$marker}{$index}", 'type' => $type, 'intact' => true, 'valid' => true, 'coverage' => 'ENTIRE_FILE', 'has_signature_timestamp' => false, 'certificate_sha256' => null];
            }
        }

        return new ValidationReport(hash('sha256', $bytes), max(1, substr_count($bytes, '%%EOF')), $signatures);
    }

    /**
     * The operations called so far, in order.
     *
     * @return list<string>
     */
    public function operations(): array
    {
        return array_column($this->calls, 'operation');
    }

    private function append(string $operation, string $pdfPath, string $outputPath, string $note): PdfFileResult
    {
        $bytes = $this->read($operation, $pdfPath, PdfLimits::MAX_BYTES + PdfLimits::FINISHED_FILE_SLACK_BYTES);

        if (file_exists($outputPath)) {
            throw new InvalidArgumentException('The output file already exists.');
        }

        $written = $bytes."\n% nonox-fake-{$operation} {$note}\n%%EOF\n";
        file_put_contents($outputPath, $written);

        return new PdfFileResult($outputPath, strlen($written), hash('sha256', $written));
    }

    private function read(string $operation, string $path, int $maxBytes): string
    {
        $this->record($operation, $path);

        if ($this->failNext !== null) {
            $failure = $this->failNext;
            $this->failNext = null;

            throw $failure;
        }

        if (! $this->installed) {
            throw PdfEngineException::notInstalled();
        }

        if (! is_file($path) || is_link($path)) {
            throw new InvalidArgumentException('The PDF must be a readable regular file.');
        }

        if ((int) filesize($path) > $maxBytes) {
            throw PdfEngineException::refused(PdfRefusal::TooLarge);
        }

        $bytes = (string) file_get_contents($path);

        if (! str_starts_with($bytes, '%PDF-')) {
            throw PdfEngineException::refused(PdfRefusal::NotPdf);
        }

        return $bytes;
    }

    private function record(string $operation, ?string $input): void
    {
        $this->calls[] = ['operation' => $operation, 'input' => $input];
    }
}
