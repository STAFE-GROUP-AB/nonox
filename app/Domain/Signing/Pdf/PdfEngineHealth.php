<?php

declare(strict_types=1);

namespace App\Domain\Signing\Pdf;

/**
 * How the PDF engine is doing on this installation, for /hq, the module's
 * screens and `signing:engine-doctor`. Versions and yes/no only: never a
 * path inside the work directory, a file or a secret.
 */
final readonly class PdfEngineHealth
{
    /**
     * @param  array<string, string|int|null>  $versions  python, pyhanko, pypdfium2, protocol
     * @param  array<string, bool>  $fonts  sans, sans-bold, cjk, cjk-bold
     * @param  array<string, bool|null>|null  $selfTest  operation => passed (null: skipped), when a self-test ran
     * @param  list<string>  $problems  plain Swedish sentences
     */
    public function __construct(
        public string $driver,
        public bool $installed,
        public bool $working,
        public array $versions = [],
        public array $fonts = [],
        public ?array $selfTest = null,
        public array $problems = [],
        public ?string $checkedAt = null,
        public bool $sandboxed = false,
    ) {}

    /** Whether the screens that need the engine (fields, seal) may be offered. */
    public function usable(): bool
    {
        return $this->installed && $this->working;
    }

    /** Whether text in Chinese, Japanese and Korean can be stamped. */
    public function canStampCjk(): bool
    {
        return ($this->fonts['cjk'] ?? false) === true;
    }

    /**
     * @return array{driver: string, installed: bool, working: bool, versions: array<string, string|int|null>, fonts: array<string, bool>, self_test: array<string, bool|null>|null, problems: list<string>, checked_at: ?string, sandboxed: bool}
     */
    public function toArray(): array
    {
        return [
            'driver' => $this->driver,
            'installed' => $this->installed,
            'working' => $this->working,
            'versions' => $this->versions,
            'fonts' => $this->fonts,
            'self_test' => $this->selfTest,
            'problems' => $this->problems,
            'checked_at' => $this->checkedAt,
            'sandboxed' => $this->sandboxed,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        /** @var array<string, string|int|null> $versions */
        $versions = (array) ($data['versions'] ?? []);
        /** @var array<string, bool> $fonts */
        $fonts = (array) ($data['fonts'] ?? []);
        /** @var array<string, bool|null>|null $selfTest */
        $selfTest = isset($data['self_test']) ? (array) $data['self_test'] : null;
        /** @var list<string> $problems */
        $problems = array_values(array_map('strval', (array) ($data['problems'] ?? [])));

        return new self(
            driver: (string) ($data['driver'] ?? 'none'),
            installed: (bool) ($data['installed'] ?? false),
            working: (bool) ($data['working'] ?? false),
            versions: $versions,
            fonts: $fonts,
            selfTest: $selfTest,
            problems: $problems,
            checkedAt: isset($data['checked_at']) ? (string) $data['checked_at'] : null,
            sandboxed: (bool) ($data['sandboxed'] ?? false),
        );
    }
}
