<?php

declare(strict_types=1);

namespace App\Support\Build;

use App\Models\FiscalYear;
use App\Models\SystemDocumentationVersion;
use Illuminate\Support\Collection;

/**
 * The systemdokumentation (docs/systemdokumentation/README.md) and the
 * versions of it this installation has taken into use. BFNAR 2013:2,
 * comment on p. 9.1: when the systemdokumentation changes, the replaced
 * version is kept, so the bookkeeping can be followed at every point in
 * time; the archive package of a fiscal year therefore carries the versions
 * that applied during the year (#44).
 */
final class SystemDocumentation
{
    public const string FILE = 'docs/systemdokumentation/README.md';

    private static ?string $fake = null;

    /** For tests: the text to treat as the current document, or null for the file. */
    public static function fake(?string $content): void
    {
        self::$fake = $content;
    }

    public static function content(): string
    {
        if (self::$fake !== null) {
            return self::$fake;
        }

        $path = base_path(self::FILE);

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    /** The "- Version: 0.81" line of the header. */
    public static function version(?string $content = null): ?string
    {
        return preg_match('/^- Version: ([0-9.]+)$/m', $content ?? self::content(), $match) === 1 ? $match[1] : null;
    }

    /** The "- Datum: 2026-11-02" line of the header. */
    public static function date(?string $content = null): ?string
    {
        return preg_match('/^- Datum: (\d{4}-\d{2}-\d{2})$/m', $content ?? self::content(), $match) === 1 ? $match[1] : null;
    }

    /**
     * Keeps the current document if this exact text has not been recorded
     * before; returns the new version, or null when nothing changed.
     */
    public static function record(): ?SystemDocumentationVersion
    {
        $content = self::content();

        if ($content === '') {
            return null;
        }

        $sha = hash('sha256', $content);

        if (SystemDocumentationVersion::query()->where('sha256', $sha)->exists()) {
            return null;
        }

        return SystemDocumentationVersion::create([
            'version' => self::version($content),
            'document_date' => self::date($content),
            'sha256' => $sha,
            'content' => $content,
            'program_version' => BuildInfo::version(),
            'program_sha' => BuildInfo::sha(),
            'recorded_at' => now(),
        ]);
    }

    /**
     * The versions that applied during the fiscal year: the one in use on
     * its first day and every one taken into use before its last day ended,
     * oldest first.
     *
     * @return Collection<int, SystemDocumentationVersion>
     */
    public static function forFiscalYear(FiscalYear $fiscalYear): Collection
    {
        $start = $fiscalYear->start_date->startOfDay();
        $end = $fiscalYear->end_date->endOfDay();
        $atStart = SystemDocumentationVersion::query()->where('recorded_at', '<', $start)->orderByDesc('recorded_at')->orderByDesc('id')->first();

        $during = SystemDocumentationVersion::query()
            ->whereBetween('recorded_at', [$start, $end])
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get();

        return $atStart === null ? $during->values() : $during->prepend($atStart)->values();
    }
}
