<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Signing\Pdf\SidecarPdfEngine;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * PDFs, keys and an engine for the PDF engine's tests (ADR-046 amendment
 * 2026-11-18, S5). Every PDF is synthetic and built here, byte by byte, with
 * a correct cross-reference table, so the tests need no PDF library.
 */
final class PdfEngineFixtures
{
    /** @var list<string> */
    private static array $created = [];

    private static ?bool $realEngineReady = null;

    /** A fresh private directory for one test, removed by {@see cleanUp()}. */
    public static function directory(): string
    {
        $directory = storage_path('framework/testing/pdf-engine/'.bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($directory, 0700);
        self::$created[] = $directory;

        return (string) realpath($directory);
    }

    /** Removes only this process's directories: parallel test processes share storage/. */
    public static function cleanUp(): void
    {
        foreach (self::$created as $directory) {
            File::deleteDirectory($directory);
        }

        self::$created = [];
    }

    /**
     * A PDF with $pages A4 pages. $catalogExtra is added to the catalogue, $pageExtra to each page and
     * $objects are added as numbered objects from 90 upwards.
     *
     * @param  list<string>  $objects
     */
    public static function pdf(int $pages = 1, string $catalogExtra = '', array $objects = [], string $pageExtra = ''): string
    {
        $bodies = [];
        $kids = [];

        for ($page = 0; $page < $pages; $page++) {
            $pageNumber = 3 + $page * 2;
            $contentNumber = $pageNumber + 1;
            $kids[] = "{$pageNumber} 0 R";
            $stream = 'BT /F1 12 Tf 72 720 Td (Sida '.($page + 1).') Tj ET';
            $bodies[$pageNumber] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] {$pageExtra} /Resources << /Font << /F1 << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> >> >> /Contents {$contentNumber} 0 R >>";
            $bodies[$contentNumber] = '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream";
        }

        $bodies[1] = "<< /Type /Catalog /Pages 2 0 R {$catalogExtra} >>";
        $bodies[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.$pages.' >>';

        foreach ($objects as $index => $object) {
            $bodies[90 + $index] = $object;
        }

        ksort($bodies);
        $size = max(array_keys($bodies)) + 1;
        $pdf = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];

        foreach ($bodies as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= "{$number} 0 obj\n{$body}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 {$size}\n0000000000 65535 f \n";

        for ($number = 1; $number < $size; $number++) {
            $pdf .= isset($offsets[$number])
                ? sprintf("%010d 00000 n \n", $offsets[$number])
                : "0000000000 65535 f \n";
        }

        return $pdf."trailer\n<< /Size {$size} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    public static function withJavaScript(): string
    {
        return self::pdf(1, '/OpenAction 90 0 R', ['<< /S /JavaScript /JS (app.alert\\(1\\)) >>']);
    }

    public static function withLaunchAction(): string
    {
        return self::pdf(1, '/OpenAction 90 0 R', ['<< /S /Launch /F (program) >>']);
    }

    public static function withEmbeddedFile(): string
    {
        return self::pdf(1, '/Names << /EmbeddedFiles << /Names [(note.txt) 90 0 R] >> >>', [
            '<< /Type /Filespec /F (note.txt) /EF << /F 91 0 R >> >>',
            "<< /Type /EmbeddedFile /Length 5 >>\nstream\nhello\nendstream",
        ]);
    }

    /** A synthetic PDF encrypted by pyHanko with a test password (tests/Fixtures/pdf-engine). */
    public static function encryptedPath(): string
    {
        return base_path('tests/Fixtures/pdf-engine/encrypted.pdf');
    }

    public static function write(string $directory, string $name, string $bytes): string
    {
        $path = $directory.DIRECTORY_SEPARATOR.$name;
        file_put_contents($path, $bytes);

        return $path;
    }

    /** A small PNG, as a drawn signature would be. */
    public static function png(string $directory): string
    {
        $image = imagecreatetruecolor(120, 40);
        $white = (int) imagecolorallocate($image, 255, 255, 255);
        $ink = (int) imagecolorallocate($image, 20, 30, 90);
        imagefill($image, 0, 0, $white);
        imageline($image, 5, 30, 115, 10, $ink);
        $path = $directory.DIRECTORY_SEPARATOR.'signature.png';
        imagepng($image, $path);

        return $path;
    }

    /**
     * A throwaway self-signed seal certificate and key as PKCS#12, made with
     * PHP's OpenSSL for one test run.
     *
     * @return array{pkcs12: string, password: string}
     */
    public static function pkcs12(): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        assert($key !== false);
        $csr = openssl_csr_new(['commonName' => 'NONOX test seal'], $key, ['digest_alg' => 'sha256']);
        assert($csr !== false && $csr !== true);
        $certificate = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
        assert($certificate !== false);
        $password = bin2hex(random_bytes(8));
        openssl_pkcs12_export($certificate, $pkcs12, $key, $password);

        return ['pkcs12' => (string) $pkcs12, 'password' => $password];
    }

    /** The Python that runs the real engine in tests: NONOX_PDF_ENGINE_PYTHON, else the local venv. */
    public static function python(): string
    {
        return (string) config('nonox.signing.pdf_engine.python');
    }

    /** Any Python 3 (the engine's, else the system's), for the timeout and crash stand-ins; null when there is none. */
    public static function anyPython(): ?string
    {
        foreach ([self::python(), '/usr/bin/python3', '/usr/local/bin/python3', '/opt/homebrew/bin/python3'] as $candidate) {
            if ($candidate !== '' && is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Whether the real engine can run here: Python with pyHanko and
     * pypdfium2. The tests that need it skip with a message when it cannot.
     */
    public static function realEngineReady(): bool
    {
        if (self::$realEngineReady !== null) {
            return self::$realEngineReady;
        }

        $python = self::python();

        if (! is_file($python) || ! is_executable($python)) {
            return self::$realEngineReady = false;
        }

        $result = Process::timeout(30)->run([$python, '-I', '-c', 'import pyhanko, pypdfium2']);

        return self::$realEngineReady = $result->successful();
    }

    public static function realEngine(string $workRoot, int $timeout = 60): SidecarPdfEngine
    {
        return new SidecarPdfEngine(
            python: self::python(),
            script: base_path('tools/pdf-engine/nonox_pdf_engine.py'),
            fontsDirectory: (string) config('nonox.signing.pdf_engine.fonts_dir'),
            workRoot: $workRoot,
            timeoutSeconds: $timeout,
        );
    }
}
