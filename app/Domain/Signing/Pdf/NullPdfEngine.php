<?php

declare(strict_types=1);

namespace App\Domain\Signing\Pdf;

/**
 * The engine of an installation without the Python helper (owner decision 4):
 * signing works as before, every PDF operation throws "not installed", and
 * the screens that need it say "Kräver PDF-motorn".
 */
final class NullPdfEngine implements PdfEngine
{
    public function driver(): string
    {
        return 'none';
    }

    public function isInstalled(): bool
    {
        return false;
    }

    public function health(bool $selfTest = false): PdfEngineHealth
    {
        return new PdfEngineHealth(
            driver: 'none',
            installed: false,
            working: false,
            problems: [__('PDF-motorn är inte installerad. Signering fungerar som förut, men fält i PDF:en och försegling kräver motorn.')],
            checkedAt: now()->toIso8601String(),
        );
    }

    public function inspect(string $pdfPath): PdfInspection
    {
        throw PdfEngineException::notInstalled();
    }

    public function renderPages(string $pdfPath, string $outputDirectory, ?array $pages = null, float $scale = 1.5): array
    {
        throw PdfEngineException::notInstalled();
    }

    public function stamp(string $pdfPath, string $outputPath, array $items): PdfFileResult
    {
        throw PdfEngineException::notInstalled();
    }

    public function appendPages(string $pdfPath, string $appendixPath, string $outputPath): PdfFileResult
    {
        throw PdfEngineException::notInstalled();
    }

    public function seal(string $pdfPath, string $outputPath, SealRequest $request): SealResult
    {
        throw PdfEngineException::notInstalled();
    }

    public function timestamp(string $pdfPath, string $outputPath, array $timestampUrls): PdfFileResult
    {
        throw PdfEngineException::notInstalled();
    }

    public function validate(string $pdfPath): ValidationReport
    {
        throw PdfEngineException::notInstalled();
    }
}
