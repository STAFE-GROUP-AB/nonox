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

    /**
     * The company's own colours for the app itself, as the accent palette
     * the interface is built on (50 lightest to 950 darkest, each as
     * "r g b" for CSS variables). The accent colour is preferred, then the
     * main colour; one that is nearly white (a background colour on the
     * documents) is passed over. Null when there is no usable colour: the
     * app then keeps the NONOX palette. Shade 500 carries white initials and
     * marks, so it is darkened to 3:1; 600 carries white button text and 700
     * text on white, so both reach 4.5:1 (WCAG 2.2 AA).
     *
     * @return array<int, string>|null
     */
    public static function interfacePalette(Company $company): ?array
    {
        $color = collect([$company->brand_accent_color, $company->brand_color])
            ->first(fn (mixed $candidate): bool => is_string($candidate)
                && preg_match('/^#[0-9A-Fa-f]{6}$/', $candidate) === 1
                && self::contrast($candidate, '#FFFFFF') >= 1.2);

        if (! is_string($color)) {
            return null;
        }

        $mix = fn (string $hex, string $with, float $amount): string => sprintf('#%02X%02X%02X', ...array_map(
            fn (int $c, int $w): int => (int) round($c + ($w - $c) * $amount),
            self::rgb($hex),
            self::rgb($with),
        ));
        $readable = function (string $hex, float $ratio = 4.5) use ($mix): string {
            for ($step = 0.0; self::contrast($hex, '#FFFFFF') < $ratio && $step < 1.0; $step += 0.05) {
                $hex = $mix($hex, '#000000', 0.05);
            }

            return $hex;
        };
        $mark = $readable($color, 3.0);
        $button = $readable($color);
        $text = $readable($mix($button, '#000000', 0.12));

        $palette = [
            50 => $mix($color, '#FFFFFF', 0.94),
            100 => $mix($color, '#FFFFFF', 0.86),
            200 => $mix($color, '#FFFFFF', 0.72),
            300 => $mix($color, '#FFFFFF', 0.52),
            400 => $mix($color, '#FFFFFF', 0.28),
            500 => $mark,
            600 => $button,
            700 => $text,
            800 => $mix($text, '#000000', 0.25),
            900 => $mix($text, '#000000', 0.45),
            950 => $mix($text, '#000000', 0.7),
        ];

        return array_map(fn (string $hex): string => implode(' ', self::rgb($hex)), $palette);
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
