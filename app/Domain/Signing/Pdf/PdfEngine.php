<?php

declare(strict_types=1);

namespace App\Domain\Signing\Pdf;

/**
 * The PDF engine (ADR-046, amendment 2026-11-18, gap 5 and package S5): the
 * one place NONOX reads, renders, stamps, extends, seals and checks an
 * EXISTING PDF. dompdf still makes the new PDFs (ADR-002).
 *
 * Every method that writes takes an input file and an output path, and the
 * output is an incremental update of the input: the input's bytes are the
 * first bytes of the output, so the original revision survives byte for byte
 * (the amendment's principle 2).
 *
 * Three drivers: {@see SidecarPdfEngine} runs the optional Python helper in
 * tools/pdf-engine; {@see NullPdfEngine} is what an installation without it
 * gets (every operation throws, screens say "Kräver PDF-motorn"); and
 * {@see FakePdfEngine} serves the test suite.
 *
 * Callers that take a PDF from outside (an upload, an API) must run
 * inspect()->assertAcceptable() on it first: inspect() reports active
 * content, the writing operations do not check for it again. The writing
 * operations enforce the size and page limits themselves.
 *
 * Timestamp URLs must be among nonox.signing.timestamp.urls; any other is
 * refused before the helper starts ({@see TimestampServers}).
 *
 * @throws PdfEngineException from every operation when it cannot be done
 */
interface PdfEngine
{
    /** "sidecar", "none" or "fake". */
    public function driver(): string;

    /** Whether the engine is installed, without starting it (cheap; safe on every request). */
    public function isInstalled(): bool;

    /** Versions, fonts and (with $selfTest) a run of every operation on a generated PDF. */
    public function health(bool $selfTest = false): PdfEngineHealth;

    /** Size, pages, revisions, signatures and active content; refuses what the limits forbid. */
    public function inspect(string $pdfPath): PdfInspection;

    /**
     * PNG images of the pages, into $outputDirectory as page-0001.png and so on.
     *
     * @param  list<int>|null  $pages  1-based page numbers, all pages when null
     * @return list<RenderedPage>
     */
    public function renderPages(string $pdfPath, string $outputDirectory, ?array $pages = null, float $scale = 1.5): array;

    /**
     * Run inspect()->assertAcceptable() on an outside PDF first.
     *
     * @param  list<StampItem>  $items
     */
    public function stamp(string $pdfPath, string $outputPath, array $items): PdfFileResult;

    /** Appends the pages of $appendixPath (for example the certificate) after the last page. */
    public function appendPages(string $pdfPath, string $appendixPath, string $outputPath): PdfFileResult;

    /** A PAdES seal (B-B, or B-T with a timestamp from the first configured server that answers). Run inspect()->assertAcceptable() on an outside PDF first. */
    public function seal(string $pdfPath, string $outputPath, SealRequest $request): SealResult;

    /**
     * A document timestamp (RFC 3161) over the whole file, from the first server that answers.
     *
     * @param  list<string>  $timestampUrls
     */
    public function timestamp(string $pdfPath, string $outputPath, array $timestampUrls): PdfFileResult;

    /**
     * Long-term validation, B-LT (ADR-046 S11): a DSS with a VRI entry per signature, holding the certificates,
     * OCSP answers and CRLs needed to validate every signature and timestamp in the file later. Fetches only from
     * public http(s) addresses the certificates name; throws ValidationDataUnavailable when one does not answer, so
     * nothing incomplete is written.
     */
    public function addValidationData(string $pdfPath, string $outputPath): LongTermResult;

    /**
     * Long-term validation, B-LTA (ADR-046 S11): the validation data of everything in the file (the newest timestamp
     * included), then a document timestamp over it from the first configured server that answers.
     *
     * @param  list<string>  $timestampUrls
     * @param  string  $mdAlgorithm  sha256, sha384 or sha512
     */
    public function archiveTimestamp(string $pdfPath, string $outputPath, array $timestampUrls, string $mdAlgorithm = 'sha256'): LongTermResult;

    /** Integrity and coverage of every signature and document timestamp in the file (no trust check, no network). */
    public function validate(string $pdfPath): ValidationReport;
}
