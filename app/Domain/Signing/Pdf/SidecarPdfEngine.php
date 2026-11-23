<?php

declare(strict_types=1);

namespace App\Domain\Signing\Pdf;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use JsonException;
use SensitiveParameter;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimeoutException;
use Symfony\Component\Process\Process as SymfonyProcess;
use Throwable;

/**
 * Runs NONOX's Python PDF helper (tools/pdf-engine/nonox_pdf_engine.py,
 * pyHanko and pypdfium2, ADR-046 amendment 2026-11-18, owner decision 3).
 *
 * One call is one subprocess:
 * - the command is an argument list ([python, -I, script]), never a shell
 *   string, so nothing in a file name or a request reaches a shell;
 * - every input is checked here (a regular file, not a link, within the
 *   limits, the right magic bytes) and COPIED into a private work directory
 *   (0700) made for the call, which is the only place the helper may read or
 *   write; outputs are copied out of it and the directory is removed after;
 * - the request is JSON on standard input, secrets included, so no secret is
 *   ever on a command line or in a file;
 * - the process gets a wall-clock timeout here and caps its own memory and
 *   CPU time, and starts with an emptied environment (no APP_KEY, no database
 *   password), a fixed PATH, and HOME and TMPDIR inside the work directory;
 * - the answer is one JSON object on standard output, size-capped and
 *   checked; both streams are capped while the helper runs (2 MB of
 *   answer, 64 KB of stderr) and the run is ended when a cap is passed;
 *   stderr is never logged, as it could hold parts of a document;
 * - every file a writing operation returns is checked here to begin with
 *   the input's exact bytes before it is copied out.
 */
final class SidecarPdfEngine implements PdfEngine
{
    public const int PROTOCOL = 1;

    private const int MAX_ANSWER_BYTES = 2 * 1024 * 1024;

    private const int MAX_STDERR_BYTES = 64 * 1024;

    /** The hash algorithms a document timestamp may use (ADR-046 S11). */
    public const array MD_ALGORITHMS = ['sha256', 'sha384', 'sha512'];

    private const array REFUSAL_CODES = ['not_pdf', 'too_large', 'too_many_pages', 'encrypted', 'unreadable'];

    public function __construct(
        private readonly string $python,
        private readonly string $script,
        private readonly string $fontsDirectory,
        private readonly string $workRoot,
        private readonly int $timeoutSeconds = 60,
        private readonly int $memoryMb = 2048,
        private readonly string $sandbox = 'none',
        private readonly string $bwrapPath = '/usr/bin/bwrap',
    ) {
        if (! in_array($sandbox, ['none', 'bwrap'], true)) {
            throw new InvalidArgumentException('The sandbox must be none or bwrap.');
        }

        if ($timeoutSeconds < 1 || $timeoutSeconds > 600) {
            throw new InvalidArgumentException('The timeout must be 1 to 600 seconds.');
        }

        if ($memoryMb < 128 || $memoryMb > 16384) {
            throw new InvalidArgumentException('The memory limit must be 128 to 16384 MB.');
        }
    }

    /** The engine as config/nonox.php (signing.pdf_engine) describes it. */
    public static function fromConfig(): self
    {
        return new self(
            python: (string) config('nonox.signing.pdf_engine.python'),
            script: base_path('tools/pdf-engine/nonox_pdf_engine.py'),
            fontsDirectory: (string) config('nonox.signing.pdf_engine.fonts_dir'),
            workRoot: (string) config('nonox.signing.pdf_engine.work_dir'),
            timeoutSeconds: (int) config('nonox.signing.pdf_engine.timeout', 60),
            memoryMb: (int) config('nonox.signing.pdf_engine.memory_mb', 2048),
            sandbox: (string) config('nonox.signing.pdf_engine.sandbox', 'none'),
            bwrapPath: (string) config('nonox.signing.pdf_engine.bwrap_path', '/usr/bin/bwrap'),
        );
    }

    public function driver(): string
    {
        return 'sidecar';
    }

    public function isInstalled(): bool
    {
        return $this->python !== ''
            && is_file($this->python)
            && is_executable($this->python)
            && is_file($this->script);
    }

    public function health(bool $selfTest = false): PdfEngineHealth
    {
        $checkedAt = now()->toIso8601String();

        if (! $this->isInstalled()) {
            return new PdfEngineHealth(
                driver: $this->driver(),
                installed: false,
                working: false,
                problems: [__('PDF-motorn är vald men hittas inte. Kontrollera NONOX_PDF_ENGINE_PYTHON och installera enligt guiden för egen drift.')],
                checkedAt: $checkedAt,
            );
        }

        if ($this->sandbox === 'bwrap' && ! $this->bwrapAvailable()) {
            return new PdfEngineHealth(
                driver: $this->driver(),
                installed: true,
                working: false,
                problems: [__('Sandlådan (bubblewrap) är påslagen men hittas inte eller går inte att köra. Installera bubblewrap eller sätt NONOX_PDF_ENGINE_SANDBOX=none.')],
                checkedAt: $checkedAt,
            );
        }

        try {
            $answer = $this->call('health', [], [], min($this->timeoutSeconds, 30));
        } catch (PdfEngineException $exception) {
            $problem = $this->sandbox === 'bwrap'
                ? __('PDF-motorn startar inte i sandlådan: :reason', ['reason' => $exception->getMessage()])
                : $exception->getMessage();

            return new PdfEngineHealth($this->driver(), true, false, problems: [$problem], checkedAt: $checkedAt);
        }

        /** @var array<string, string|int|null> $versions */
        $versions = array_map(fn (mixed $value): string|int|null => is_int($value) || $value === null ? $value : (string) $value, (array) ($answer['versions'] ?? []));
        /** @var array<string, bool> $fonts */
        $fonts = array_map(fn (mixed $value): bool => $value === true, (array) ($answer['fonts'] ?? []));
        $problems = [];

        foreach ($this->pinnedVersions() as $package => $pinned) {
            $installed = $versions[$package] ?? null;

            if ($installed !== null && (string) $installed !== $pinned) {
                $problems[] = __(':package :installed är installerat men NONOX är provat med :pinned. Installera om från tools/pdf-engine/requirements.txt.', ['package' => $package, 'installed' => (string) $installed, 'pinned' => $pinned]);
            }
        }

        if (in_array(false, $fonts, true) || $fonts === []) {
            $problems[] = __('Typsnitten för PDF-motorn saknas eller är ofullständiga. Kör scripts/fetch-signing-fonts.sh.');
        }

        $results = null;
        $working = true;

        if ($selfTest) {
            try {
                $test = $this->call('selftest', [], [], $this->timeoutSeconds);
                $results = array_map(fn (mixed $value): ?bool => $value === null ? null : $value === true, (array) ($test['checks'] ?? []));
                $working = ($test['passed'] ?? false) === true;
            } catch (PdfEngineException $exception) {
                $working = false;
                $problems[] = $exception->getMessage();
            }

            if (! $working && $results !== null) {
                $failed = array_keys(array_filter($results, fn (?bool $passed): bool => $passed === false));
                $problems[] = __('Självtestet misslyckades för: :steps.', ['steps' => implode(', ', $failed)]);
            }
        }

        return new PdfEngineHealth($this->driver(), true, $working, $versions, $fonts, $results, $problems, $checkedAt, sandboxed: $this->sandbox === 'bwrap');
    }

    private function bwrapAvailable(): bool
    {
        return $this->bwrapPath !== '' && is_file($this->bwrapPath) && is_executable($this->bwrapPath);
    }

    /**
     * What is started for one call: the helper, wrapped in bubblewrap when the
     * sandbox is on. A configured sandbox that cannot run never falls back to
     * running the helper without it.
     *
     * @return list<string>
     */
    private function command(string $work): array
    {
        $inner = [$this->python, '-I', $this->script];

        if ($this->sandbox !== 'bwrap') {
            return $inner;
        }

        if (! $this->bwrapAvailable()) {
            throw new PdfEngineException(PdfEngineFailure::Failed, detail: 'sandbox missing');
        }

        return (new BubblewrapSandbox($this->bwrapPath, BubblewrapSandbox::pathsFor($this->python, $this->script, $this->fontsDirectory)))->command($work, $inner);
    }

    public function inspect(string $pdfPath): PdfInspection
    {
        $this->guardPdf($pdfPath, PdfLimits::MAX_BYTES);

        return PdfInspection::fromArray($this->call('inspect', [], ['input' => [$pdfPath, 'input.pdf']]));
    }

    public function renderPages(string $pdfPath, string $outputDirectory, ?array $pages = null, float $scale = 1.5): array
    {
        $this->guardPdf($pdfPath, PdfLimits::MAX_BYTES);

        if (! is_dir($outputDirectory) || ! is_writable($outputDirectory)) {
            throw new InvalidArgumentException('The output directory must exist and be writable.');
        }

        if ($scale < 0.1 || $scale > 6.0) {
            throw new InvalidArgumentException('The scale must be 0.1 to 6.');
        }

        if ($pages !== null) {
            foreach ($pages as $page) {
                if ($page < 1 || $page > PdfLimits::MAX_PAGES) {
                    throw new InvalidArgumentException('A page number is outside the page limit.');
                }
            }
        }

        $rendered = [];

        $this->withWorkDirectory(function (string $work) use ($pdfPath, $outputDirectory, $pages, $scale, &$rendered): void {
            $answer = $this->run($work, 'render_pages', [
                'output_prefix' => 'page',
                'scale' => $scale,
                'max_pixels' => PdfLimits::MAX_PIXELS_PER_PAGE,
                'pages' => $pages,
            ], ['input' => [$pdfPath, 'input.pdf']]);

            foreach ((array) ($answer['pages'] ?? []) as $page) {
                $page = (array) $page;
                $number = (int) ($page['page'] ?? 0);
                $target = $outputDirectory.DIRECTORY_SEPARATOR.sprintf('page-%04d.png', $number);
                $this->copyOut($work, (string) ($page['path'] ?? ''), $target, true);
                $rendered[] = new RenderedPage(
                    $number,
                    $target,
                    (int) ($page['width_px'] ?? 0),
                    (int) ($page['height_px'] ?? 0),
                    (float) ($page['width_pt'] ?? 0),
                    (float) ($page['height_pt'] ?? 0),
                );
            }
        });

        return $rendered;
    }

    public function stamp(string $pdfPath, string $outputPath, array $items): PdfFileResult
    {
        $this->guardPdf($pdfPath, PdfLimits::MAX_BYTES + PdfLimits::FINISHED_FILE_SLACK_BYTES);
        $this->guardOutput($outputPath);

        if ($items === [] || count($items) > PdfLimits::MAX_STAMPS) {
            throw new InvalidArgumentException('Stamp between 1 and '.PdfLimits::MAX_STAMPS.' items.');
        }

        $inputs = ['input' => [$pdfPath, 'input.pdf']];
        $requestItems = [];

        foreach ($items as $index => $item) {
            if ($item instanceof StampImage) {
                $this->guardPng($item->pngPath);
                $name = "image-{$index}.png";
                $inputs["image{$index}"] = [$item->pngPath, $name];
                $requestItems[] = $item->toRequest($name);

                continue;
            }

            $requestItems[] = $item->toRequest();
        }

        return $this->writeOne('stamp', ['items' => $requestItems, 'output' => 'output.pdf'], $inputs, $outputPath);
    }

    public function appendPages(string $pdfPath, string $appendixPath, string $outputPath): PdfFileResult
    {
        $this->guardPdf($pdfPath, PdfLimits::MAX_BYTES + PdfLimits::FINISHED_FILE_SLACK_BYTES);
        $this->guardPdf($appendixPath, PdfLimits::MAX_BYTES);
        $this->guardOutput($outputPath);

        return $this->writeOne('append_pages', ['appendix' => 'appendix.pdf', 'output' => 'output.pdf'], [
            'input' => [$pdfPath, 'input.pdf'],
            'appendix' => [$appendixPath, 'appendix.pdf'],
        ], $outputPath);
    }

    public function seal(string $pdfPath, string $outputPath, #[SensitiveParameter] SealRequest $request): SealResult
    {
        $this->guardPdf($pdfPath, PdfLimits::MAX_BYTES + PdfLimits::FINISHED_FILE_SLACK_BYTES);
        $this->guardOutput($outputPath);
        TimestampServers::assertAllowed($request->timestampUrls);

        $result = null;

        $this->withWorkDirectory(function (string $work) use ($pdfPath, $outputPath, $request, &$result): void {
            $copied = [];
            $answer = $this->run($work, 'seal', [
                'output' => 'output.pdf',
                'level' => $request->level->value,
                'field_name' => $request->fieldName,
                'reason' => $request->reason,
                'location' => $request->location,
                'timestamp_urls' => $request->timestampUrls,
                'timestamp_timeout' => $request->timestampTimeoutSeconds,
                'secrets' => [
                    'pkcs12' => base64_encode($request->pkcs12),
                    'password' => base64_encode($request->password),
                ],
            ], ['input' => [$pdfPath, 'input.pdf']], copied: $copied);

            $this->copyOut($work, (string) ($answer['output'] ?? ''), $outputPath, false, $copied['input']);
            $level = SealLevel::tryFrom((string) ($answer['level'] ?? ''));

            if ($level === null) {
                throw new PdfEngineException(PdfEngineFailure::InvalidResponse);
            }

            $result = new SealResult(
                $outputPath,
                (int) ($answer['bytes'] ?? 0),
                (string) ($answer['sha256'] ?? ''),
                $level,
                (string) ($answer['certificate_sha256'] ?? ''),
                isset($answer['timestamp_url']) ? (string) $answer['timestamp_url'] : null,
            );
        });

        /** @var SealResult $result */
        return $result;
    }

    public function timestamp(string $pdfPath, string $outputPath, array $timestampUrls): PdfFileResult
    {
        $this->guardPdf($pdfPath, PdfLimits::MAX_BYTES + PdfLimits::FINISHED_FILE_SLACK_BYTES);
        $this->guardOutput($outputPath);

        if ($timestampUrls === [] || count($timestampUrls) > 5) {
            throw new InvalidArgumentException('Give one to five timestamp URLs.');
        }

        foreach ($timestampUrls as $url) {
            if (! SealRequest::isTimestampUrl($url)) {
                throw new InvalidArgumentException('A timestamp URL must be http(s).');
            }
        }

        TimestampServers::assertAllowed($timestampUrls);

        return $this->writeOne('timestamp', ['output' => 'output.pdf', 'timestamp_urls' => $timestampUrls], ['input' => [$pdfPath, 'input.pdf']], $outputPath);
    }

    public function addValidationData(string $pdfPath, string $outputPath): LongTermResult
    {
        $this->guardPdf($pdfPath, PdfLimits::MAX_BYTES + PdfLimits::FINISHED_FILE_SLACK_BYTES);
        $this->guardOutput($outputPath);

        return $this->writeLongTerm('add_validation_data', ['output' => 'output.pdf', 'fetch_timeout' => self::fetchTimeout()], $pdfPath, $outputPath);
    }

    public function archiveTimestamp(string $pdfPath, string $outputPath, array $timestampUrls, string $mdAlgorithm = 'sha256'): LongTermResult
    {
        $this->guardPdf($pdfPath, PdfLimits::MAX_BYTES + PdfLimits::FINISHED_FILE_SLACK_BYTES);
        $this->guardOutput($outputPath);

        if ($timestampUrls === [] || count($timestampUrls) > 5) {
            throw new InvalidArgumentException('Give one to five timestamp URLs.');
        }

        foreach ($timestampUrls as $url) {
            if (! SealRequest::isTimestampUrl($url)) {
                throw new InvalidArgumentException('A timestamp URL must be http(s).');
            }
        }

        if (! in_array($mdAlgorithm, self::MD_ALGORITHMS, true)) {
            throw new InvalidArgumentException('The hash algorithm must be sha256, sha384 or sha512.');
        }

        TimestampServers::assertAllowed($timestampUrls);

        return $this->writeLongTerm('archive_timestamp', [
            'output' => 'output.pdf',
            'timestamp_urls' => $timestampUrls,
            'timestamp_timeout' => max(1, min(60, (int) config('nonox.signing.timestamp.timeout', 10))),
            'fetch_timeout' => self::fetchTimeout(),
            'md_algorithm' => $mdAlgorithm,
        ], $pdfPath, $outputPath);
    }

    /** Seconds per OCSP, CRL or certificate request when validation data is collected (1–60, default 10). */
    private static function fetchTimeout(): int
    {
        return max(1, min(60, (int) config('nonox.signing.ltv.fetch_timeout', 10)));
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function writeLongTerm(string $operation, array $params, string $pdfPath, string $outputPath): LongTermResult
    {
        $result = null;

        $this->withWorkDirectory(function (string $work) use ($operation, $params, $pdfPath, $outputPath, &$result): void {
            $copied = [];
            $answer = $this->run($work, $operation, $params, ['input' => [$pdfPath, 'input.pdf']], copied: $copied);
            $this->copyOut($work, (string) ($answer['output'] ?? ''), $outputPath, false, $copied['input']);
            $result = LongTermResult::fromAnswer($outputPath, $answer);
        });

        /** @var LongTermResult $result */
        return $result;
    }

    public function prepareExternalSignature(string $pdfPath, string $outputPath, ExternalSignatureRequest $request): PreparedSignature
    {
        $this->guardPdf($pdfPath, PdfLimits::MAX_BYTES + PdfLimits::FINISHED_FILE_SLACK_BYTES);
        $this->guardOutput($outputPath);

        $result = null;

        $this->withWorkDirectory(function (string $work) use ($pdfPath, $outputPath, $request, &$result): void {
            $copied = [];
            $answer = $this->run($work, 'prepare_external_signature', [
                'output' => 'output.pdf',
                ...$request->toRequest(),
            ], ['input' => [$pdfPath, 'input.pdf']], copied: $copied);

            $this->copyOut($work, (string) ($answer['output'] ?? ''), $outputPath, false, $copied['input']);
            $prepared = (array) ($answer['prepared'] ?? []);
            $digest = base64_decode((string) ($prepared['digest'] ?? ''), true);
            $documentDigest = hex2bin((string) ($prepared['document_digest'] ?? ''));
            $attributes = base64_decode((string) ($prepared['signed_attributes'] ?? ''), true);

            if ($digest === false || strlen($digest) !== 32 || $documentDigest === false || $attributes === false || $attributes === '' || ! hash_equals(hash('sha256', $attributes, true), $digest)) {
                @unlink($outputPath);

                throw new PdfEngineException(PdfEngineFailure::InvalidResponse, detail: 'prepared');
            }

            $result = new PreparedSignature(
                $outputPath,
                (int) ($answer['bytes'] ?? 0),
                (string) ($answer['sha256'] ?? ''),
                $digest,
                $documentDigest,
                (int) ($prepared['reserved_start'] ?? 0),
                (int) ($prepared['reserved_end'] ?? 0),
                $attributes,
                $request->algorithm,
                (string) ($answer['certificate_sha256'] ?? ''),
            );
        });

        /** @var PreparedSignature $result */
        return $result;
    }

    public function finishExternalSignature(string $pdfPath, PreparedSignature $prepared, ExternalSignatureRequest $request, string $signature, string $outputPath): PdfFileResult
    {
        $this->guardPdf($pdfPath, PdfLimits::MAX_BYTES + PdfLimits::FINISHED_FILE_SLACK_BYTES);
        $this->guardPdf($prepared->path, PdfLimits::MAX_BYTES + PdfLimits::FINISHED_FILE_SLACK_BYTES);
        $this->guardOutput($outputPath);

        if ($signature === '' || strlen($signature) > 2048) {
            throw new PdfEngineException(PdfEngineFailure::SignatureInvalid);
        }

        $result = null;

        $this->withWorkDirectory(function (string $work) use ($pdfPath, $prepared, $request, $signature, $outputPath, &$result): void {
            $copied = [];
            $answer = $this->run($work, 'finish_external_signature', [
                'output' => 'output.pdf',
                ...$request->toRequest(),
                ...$prepared->toRequest(),
                'prepared_sha256' => $prepared->sha256,
                'signature' => base64_encode($signature),
            ], ['input' => [$pdfPath, 'input.pdf'], 'prepared' => [$prepared->path, 'prepared.pdf']], copied: $copied);

            $this->copyOut($work, (string) ($answer['output'] ?? ''), $outputPath, false, $copied['input']);
            $result = new PdfFileResult($outputPath, (int) ($answer['bytes'] ?? 0), (string) ($answer['sha256'] ?? ''));
        });

        /** @var PdfFileResult $result */
        return $result;
    }

    public function validate(string $pdfPath): ValidationReport
    {
        $this->guardPdf($pdfPath, PdfLimits::MAX_BYTES + PdfLimits::FINISHED_FILE_SLACK_BYTES);

        return ValidationReport::fromArray($this->call('validate', [], ['input' => [$pdfPath, 'input.pdf']]));
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, array{0: string, 1: string}>  $inputs
     */
    private function writeOne(string $operation, array $params, array $inputs, string $outputPath): PdfFileResult
    {
        $result = null;

        $this->withWorkDirectory(function (string $work) use ($operation, $params, $inputs, $outputPath, &$result): void {
            $copied = [];
            $answer = $this->run($work, $operation, $params, $inputs, copied: $copied);
            $this->copyOut($work, (string) ($answer['output'] ?? ''), $outputPath, false, $copied['input']);
            $result = new PdfFileResult(
                $outputPath,
                (int) ($answer['bytes'] ?? 0),
                (string) ($answer['sha256'] ?? ''),
                isset($answer['pages']) ? (int) $answer['pages'] : null,
                isset($answer['timestamp_url']) ? (string) $answer['timestamp_url'] : null,
            );
        });

        /** @var PdfFileResult $result */
        return $result;
    }

    /**
     * One call in its own work directory, which is removed afterwards.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, array{0: string, 1: string}>  $inputs
     * @return array<string, mixed>
     */
    private function call(string $operation, array $params, array $inputs, ?int $timeout = null): array
    {
        $answer = [];

        $this->withWorkDirectory(function (string $work) use ($operation, $params, $inputs, $timeout, &$answer): void {
            $answer = $this->run($work, $operation, $params, $inputs, $timeout);
        });

        return $answer;
    }

    private function withWorkDirectory(callable $callback): void
    {
        if (! $this->isInstalled()) {
            throw PdfEngineException::notInstalled();
        }

        if (! is_dir($this->workRoot)) {
            File::ensureDirectoryExists($this->workRoot, 0700);
        }

        $work = $this->workRoot.DIRECTORY_SEPARATOR.bin2hex(random_bytes(16));

        if (! mkdir($work, 0700) || ($real = realpath($work)) === false) {
            throw new PdfEngineException(PdfEngineFailure::Failed, detail: 'work directory');
        }

        try {
            $callback($real);
        } finally {
            File::deleteDirectory($real);
        }
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, array{0: string, 1: string}>  $inputs  key => [source path, name inside the work directory]
     * @param  array<string, array{bytes: int, sha256: string}>  $copied  filled with the size and hash of every input as it was copied in
     * @return array<string, mixed>
     */
    private function run(string $work, string $operation, #[SensitiveParameter] array $params, array $inputs, ?int $timeout = null, array &$copied = []): array
    {
        foreach ($inputs as $key => [$source, $name]) {
            $copied[$key] = $this->copyIn($source, $work.DIRECTORY_SEPARATOR.$name, in_array($key, ['input', 'appendix', 'prepared'], true));

            if (in_array($key, ['input', 'appendix', 'prepared'], true)) {
                $params[$key] = $name;
            }
        }

        $timeout ??= $this->timeoutSeconds;
        $request = array_merge($params, [
            'protocol' => self::PROTOCOL,
            'op' => $operation,
            'work_dir' => $work,
            'fonts_dir' => $this->fontsDirectory,
            'max_bytes' => PdfLimits::MAX_BYTES,
            'max_pages' => PdfLimits::MAX_PAGES,
            'memory_mb' => $this->memoryMb,
            'cpu_seconds' => $timeout + 5,
        ]);
        unset($params);

        try {
            $json = json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new PdfEngineException(PdfEngineFailure::InvalidRequest);
        } finally {
            unset($request);
        }

        $stdoutBytes = 0;
        $stderrBytes = 0;
        $overflow = null;

        try {
            $process = Process::path($work)
                ->timeout($timeout)
                ->env($this->environment($work))
                ->input($json)
                ->start($this->command($work), function (string $type, string $buffer) use (&$stdoutBytes, &$stderrBytes, &$overflow): void {
                    // Counts only; the loop below stops the process. Throwing here would leave
                    // a running process behind to call this again when it is released.
                    if ($type === SymfonyProcess::ERR) {
                        $stderrBytes += strlen($buffer);
                        $overflow ??= $stderrBytes > self::MAX_STDERR_BYTES ? 'stderr too large' : null;

                        return;
                    }

                    $stdoutBytes += strlen($buffer);
                    $overflow ??= $stdoutBytes > self::MAX_ANSWER_BYTES ? 'answer too large' : null;
                });

            while ($process->running()) {
                if ($overflow !== null) {
                    $process->stop(0);

                    break;
                }

                $process->ensureNotTimedOut();
                usleep(10_000);
            }

            if ($overflow !== null) {
                throw new PdfEngineException(PdfEngineFailure::InvalidResponse, detail: $overflow);
            }

            $result = $process->wait();
        } catch (ProcessTimedOutException|SymfonyTimeoutException) {
            throw new PdfEngineException(PdfEngineFailure::Timeout);
        } catch (PdfEngineException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new PdfEngineException(PdfEngineFailure::Crashed);
        } finally {
            unset($json);
        }

        $output = $result->output();

        if (strlen($output) > self::MAX_ANSWER_BYTES) {
            throw new PdfEngineException(PdfEngineFailure::InvalidResponse, detail: 'answer too large');
        }

        try {
            $answer = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new PdfEngineException($result->exitCode() === 0 || $result->exitCode() === 2 ? PdfEngineFailure::InvalidResponse : PdfEngineFailure::Crashed, detail: 'exit '.(string) $result->exitCode());
        }

        if (! is_array($answer) || ! is_bool($answer['ok'] ?? null)) {
            throw new PdfEngineException(PdfEngineFailure::InvalidResponse);
        }

        if ($answer['ok'] === false) {
            $error = (array) ($answer['error'] ?? []);
            $code = (string) ($error['code'] ?? 'failed');

            if (in_array($code, self::REFUSAL_CODES, true) && ($refusal = PdfRefusal::fromHelperCode($code)) !== null) {
                throw PdfEngineException::refused($refusal);
            }

            throw new PdfEngineException(PdfEngineFailure::fromHelperCode($code), detail: $code);
        }

        if ($result->exitCode() !== 0 || ! is_array($answer['result'] ?? null)) {
            throw new PdfEngineException(PdfEngineFailure::InvalidResponse);
        }

        /** @var array<string, mixed> */
        return $answer['result'];
    }

    /**
     * Copies one input into the work directory from a single open handle, so
     * the file that is checked is the file that is copied: a regular file
     * (fstat), within the size limit, with the right magic bytes. Returns its
     * size and SHA-256 as copied, for the incremental-update check.
     *
     * @return array{bytes: int, sha256: string}
     */
    private function copyIn(string $source, string $destination, bool $isPdf): array
    {
        if (is_link($source)) {
            throw new InvalidArgumentException('An input must not be a symbolic link.');
        }

        $in = @fopen($source, 'rb');

        if ($in === false) {
            throw new InvalidArgumentException('An input could not be opened.');
        }

        try {
            $stat = fstat($in);

            if ($stat === false || ($stat['mode'] & 0170000) !== 0100000) {
                throw new InvalidArgumentException('An input must be a regular file.');
            }

            $max = $isPdf ? PdfLimits::MAX_BYTES + PdfLimits::FINISHED_FILE_SLACK_BYTES : PdfLimits::MAX_STAMP_IMAGE_BYTES;

            if ($stat['size'] > $max) {
                throw $isPdf ? PdfEngineException::refused(PdfRefusal::TooLarge) : new InvalidArgumentException('A stamp image is larger than 5 MB.');
            }

            $magic = $isPdf ? '%PDF-' : "\x89PNG\r\n\x1a\n";

            if (fread($in, strlen($magic)) !== $magic) {
                throw $isPdf ? PdfEngineException::refused(PdfRefusal::NotPdf) : new InvalidArgumentException('A stamp image must be a PNG.');
            }

            rewind($in);
            $out = @fopen($destination, 'xb');

            if ($out === false) {
                throw new PdfEngineException(PdfEngineFailure::Failed, detail: 'copy in');
            }

            $context = hash_init('sha256');
            $bytes = 0;

            try {
                while (! feof($in) && $bytes <= $max) {
                    $chunk = (string) fread($in, 1024 * 1024);
                    hash_update($context, $chunk);
                    fwrite($out, $chunk);
                    $bytes += strlen($chunk);
                }
            } finally {
                fclose($out);
            }

            if ($bytes > $max) {
                throw $isPdf ? PdfEngineException::refused(PdfRefusal::TooLarge) : new InvalidArgumentException('A stamp image is larger than 5 MB.');
            }

            return ['bytes' => $bytes, 'sha256' => hash_final($context)];
        } finally {
            fclose($in);
        }
    }

    /**
     * An emptied environment: every inherited variable is removed (Symfony
     * Process drops a variable set to false), so the helper never sees
     * APP_KEY, the database password or a mail key.
     *
     * @return array<string, string|false>
     */
    private function environment(string $work): array
    {
        $environment = [];
        $inherited = array_merge(array_keys(getenv()), array_keys($_ENV), array_keys(array_filter($_SERVER, 'is_string')));

        foreach ($inherited as $name) {
            if (is_string($name) && $name !== '') {
                $environment[$name] = false;
            }
        }

        return array_merge($environment, [
            'PATH' => '/usr/local/bin:/usr/bin:/bin',
            'LANG' => 'C.UTF-8',
            'LC_ALL' => 'C.UTF-8',
            'HOME' => $work,
            'TMPDIR' => $work,
        ]);
    }

    /**
     * Copies a file the helper wrote (it must be inside the work directory)
     * to its destination. For a writing operation, $input is the size and
     * hash of the input as it was copied in, and the output must be longer
     * and begin with exactly those bytes: NONOX checks the incremental-update
     * promise itself instead of trusting the helper's word.
     *
     * @param  array{bytes: int, sha256: string}|null  $input
     */
    private function copyOut(string $work, string $produced, string $destination, bool $overwrite, ?array $input = null): void
    {
        $real = realpath($produced);

        if ($real === false || ! str_starts_with($real, $work.DIRECTORY_SEPARATOR) || ! is_file($real) || is_link($produced)) {
            throw new PdfEngineException(PdfEngineFailure::InvalidResponse, detail: 'output path');
        }

        $in = fopen($real, 'rb');

        if ($in === false) {
            throw new PdfEngineException(PdfEngineFailure::Failed, detail: 'copy out');
        }

        try {
            if ($input !== null) {
                $stat = fstat($in);
                $prefix = hash_init('sha256');
                hash_update_stream($prefix, $in, $input['bytes']);

                if ($stat === false || $stat['size'] <= $input['bytes'] || ! hash_equals($input['sha256'], hash_final($prefix))) {
                    throw new PdfEngineException(PdfEngineFailure::NotIncremental);
                }

                rewind($in);
            }

            if (! $overwrite && file_exists($destination)) {
                throw new InvalidArgumentException('The output file already exists.');
            }

            $out = @fopen($destination, $overwrite ? 'wb' : 'xb');

            if ($out === false) {
                throw new PdfEngineException(PdfEngineFailure::Failed, detail: 'copy out');
            }

            try {
                if (stream_copy_to_stream($in, $out) === false) {
                    throw new PdfEngineException(PdfEngineFailure::Failed, detail: 'copy out');
                }
            } finally {
                fclose($out);
            }
        } finally {
            fclose($in);
        }
    }

    private function guardPdf(string $path, int $maxBytes): void
    {
        if (! is_file($path) || is_link($path) || ! is_readable($path)) {
            throw new InvalidArgumentException('The PDF must be a readable regular file.');
        }

        $size = filesize($path);

        if ($size === false || $size > $maxBytes) {
            throw PdfEngineException::refused(PdfRefusal::TooLarge);
        }

        $handle = fopen($path, 'rb');
        $head = $handle === false ? false : fread($handle, 5);

        if ($handle !== false) {
            fclose($handle);
        }

        if ($head !== '%PDF-') {
            throw PdfEngineException::refused(PdfRefusal::NotPdf);
        }
    }

    private function guardPng(string $path): void
    {
        if (! is_file($path) || is_link($path) || ! is_readable($path)) {
            throw new InvalidArgumentException('A stamp image must be a readable regular file.');
        }

        $size = filesize($path);

        if ($size === false || $size > PdfLimits::MAX_STAMP_IMAGE_BYTES) {
            throw new InvalidArgumentException('A stamp image is larger than 5 MB.');
        }

        $handle = fopen($path, 'rb');
        $head = $handle === false ? false : fread($handle, 8);

        if ($handle !== false) {
            fclose($handle);
        }

        if ($head !== "\x89PNG\r\n\x1a\n") {
            throw new InvalidArgumentException('A stamp image must be a PNG.');
        }
    }

    private function guardOutput(string $path): void
    {
        $directory = dirname($path);

        if (! is_dir($directory) || ! is_writable($directory)) {
            throw new InvalidArgumentException('The output directory must exist and be writable.');
        }

        if (file_exists($path)) {
            throw new InvalidArgumentException('The output file already exists.');
        }
    }

    /**
     * The versions in the lock file (tools/pdf-engine/requirements.txt), to
     * tell an operator whose helper was installed from another lock.
     *
     * @return array<string, string>
     */
    private function pinnedVersions(): array
    {
        $lock = dirname($this->script).DIRECTORY_SEPARATOR.'requirements.txt';

        if (! is_file($lock)) {
            return [];
        }

        $pinned = [];

        foreach (['pyhanko', 'pypdfium2'] as $package) {
            if (preg_match('/^'.$package.'==([0-9A-Za-z.]+)/mi', (string) file_get_contents($lock), $match) === 1) {
                $pinned[$package] = $match[1];
            }
        }

        return $pinned;
    }
}
