<?php

declare(strict_types=1);

namespace App\Domain\Signing\Pdf;

/**
 * What the engine found in a PDF. Files that break the size, page or
 * encryption rules never get this far (inspect() throws a refusal); active
 * content is reported here, and {@see refusal()} says whether NONOX accepts
 * the file for signing.
 */
final readonly class PdfInspection
{
    /**
     * @param  list<array{width: float, height: float, rotation: int}>  $pageSizes  in PDF points
     */
    public function __construct(
        public int $bytes,
        public string $sha256,
        public int $pages,
        public array $pageSizes,
        public ?string $pdfVersion,
        public int $revisions,
        public int $signatures,
        public bool $javascript,
        public bool $launchActions,
        public bool $embeddedFiles,
        public bool $xfa,
        public bool $externalActions = false,
    ) {}

    /** @param  array<string, mixed>  $result  the helper's answer */
    public static function fromArray(array $result): self
    {
        $sizes = [];

        foreach ((array) ($result['page_sizes'] ?? []) as $size) {
            $size = (array) $size;
            $sizes[] = [
                'width' => (float) ($size['width'] ?? 0),
                'height' => (float) ($size['height'] ?? 0),
                'rotation' => (int) ($size['rotation'] ?? 0),
            ];
        }

        return new self(
            bytes: (int) ($result['bytes'] ?? 0),
            sha256: (string) ($result['sha256'] ?? ''),
            pages: (int) ($result['pages'] ?? 0),
            pageSizes: $sizes,
            pdfVersion: isset($result['pdf_version']) ? (string) $result['pdf_version'] : null,
            revisions: (int) ($result['revisions'] ?? 0),
            signatures: (int) ($result['signatures'] ?? 0),
            javascript: (bool) ($result['javascript'] ?? false),
            launchActions: (bool) ($result['launch_actions'] ?? false),
            embeddedFiles: (bool) ($result['embedded_files'] ?? false),
            xfa: (bool) ($result['xfa'] ?? false),
            externalActions: (bool) ($result['external_actions'] ?? false),
        );
    }

    /** Why NONOX will not send this file for signing, or null when it may. */
    public function refusal(): ?PdfRefusal
    {
        return match (true) {
            $this->bytes > PdfLimits::MAX_BYTES => PdfRefusal::TooLarge,
            $this->pages > PdfLimits::MAX_PAGES => PdfRefusal::TooManyPages,
            $this->pages < 1 => PdfRefusal::Unreadable,
            $this->javascript => PdfRefusal::JavaScript,
            $this->launchActions => PdfRefusal::LaunchAction,
            $this->embeddedFiles => PdfRefusal::EmbeddedFiles,
            $this->xfa => PdfRefusal::XfaForm,
            $this->externalActions => PdfRefusal::ExternalAction,
            default => null,
        };
    }

    /** @throws PdfEngineException with the refusal when the file is not accepted */
    public function assertAcceptable(): self
    {
        $refusal = $this->refusal();

        if ($refusal !== null) {
            throw PdfEngineException::refused($refusal);
        }

        return $this;
    }
}
