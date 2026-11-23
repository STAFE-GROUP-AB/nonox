<?php

declare(strict_types=1);

use App\Modules\Ai\Support\AiAnalysisException;
use App\Modules\Ai\Support\HeicImage;

function heicScratch(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'heic-test-').'.heic';
    file_put_contents($path, $contents);

    return $path;
}

it('recognises a HEIF file by its ftyp box, not by its name', function (): void {
    $svg = heicScratch('<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>');

    expect(HeicImage::looksLikeHeif(base_path('tests/Fixtures/receipts/kvitto.heic')))->toBeTrue()
        ->and(HeicImage::looksLikeHeif($svg))->toBeFalse()
        ->and(HeicImage::looksLikeHeif(heicScratch('')))->toBeFalse()
        ->and(HeicImage::looksLikeHeif(heicScratch("\0\0\0\x18ftypisom\0\0\0\0")))->toBeFalse();
});

it('never hands a disguised file to another ImageMagick decoder', function (): void {
    $svg = heicScratch('<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>');

    expect(fn () => HeicImage::toJpegBase64($svg))->toThrow(AiAnalysisException::class);
})->skip(fn () => ! HeicImage::supported(), 'Imagick with HEIC support is not installed.');

it('still turns a real HEIC photo into a JPEG', function (): void {
    $jpeg = base64_decode(HeicImage::toJpegBase64(base_path('tests/Fixtures/receipts/kvitto.heic')), true);

    expect(substr((string) $jpeg, 0, 3))->toBe("\xFF\xD8\xFF");
})->skip(fn () => ! HeicImage::supported(), 'Imagick with HEIC support is not installed.');
