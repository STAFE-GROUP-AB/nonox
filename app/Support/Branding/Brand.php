<?php

declare(strict_types=1);

namespace App\Support\Branding;

use App\Models\Company;
use Illuminate\Support\Facades\Storage;

/**
 * A company's look on its own documents and in the employee portal: the
 * logo, a main colour (bars, totals, highlights) and an accent colour
 * (headings and labels). Without its own colours a company's documents are
 * neutral ink, never NONOX's colours.
 */
final readonly class Brand
{
    public const string DISK = 'local';

    public const string DEFAULT_COLOR = '#15171C';

    public function __construct(
        public string $color,
        public string $accent,
        public ?string $logoPath,
    ) {}

    public static function for(Company $company): self
    {
        $color = $company->brand_color ?? self::DEFAULT_COLOR;

        return new self($color, $company->brand_accent_color ?? $color, $company->logo_path);
    }

    /** Black or white, whichever reads best on the main colour (WCAG contrast). */
    public function onColor(): string
    {
        return self::contrast($this->color, '#FFFFFF') >= self::contrast($this->color, '#000000') ? '#FFFFFF' : '#000000';
    }

    /** The main colour mixed with white, for light backgrounds. */
    public function tint(float $amount = 0.92): string
    {
        [$r, $g, $b] = self::rgb($this->color);

        return sprintf('#%02X%02X%02X', ...array_map(fn (int $c): int => (int) round($c + (255 - $c) * $amount), [$r, $g, $b]));
    }

    /** The logo as a data URI for PDFs, which never load files over the network. */
    public function logoDataUri(): ?string
    {
        if ($this->logoPath === null || ! Storage::disk(self::DISK)->exists($this->logoPath)) {
            return null;
        }

        $mime = Storage::disk(self::DISK)->mimeType($this->logoPath) ?: 'image/png';

        return "data:{$mime};base64,".base64_encode((string) Storage::disk(self::DISK)->get($this->logoPath));
    }

    /** Contrast ratio between two colours, 1 to 21 (WCAG 2.2). */
    public static function contrast(string $first, string $second): float
    {
        $a = self::luminance($first);
        $b = self::luminance($second);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    private static function luminance(string $hex): float
    {
        $channels = array_map(function (int $c): float {
            $c /= 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /** @return array{int, int, int} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }
}
