<?php

declare(strict_types=1);

namespace App\Domain\Signing\Pdf;

use InvalidArgumentException;

/** A PNG image (a drawn signature, initials) stamped into a box on a page. */
final readonly class StampImage implements StampItem
{
    public function __construct(
        public int $pageNumber,
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public string $pngPath,
    ) {
        if ($pageNumber < 1 || $pageNumber > PdfLimits::MAX_PAGES) {
            throw new InvalidArgumentException('The page must be between 1 and the page limit.');
        }

        if ($width < 1 || $height < 1 || $width > 2000 || $height > 2000) {
            throw new InvalidArgumentException('The image box must be 1 to 2000 points wide and high.');
        }

        if (abs($x) > 10000 || abs($y) > 10000) {
            throw new InvalidArgumentException('The position is outside any page.');
        }
    }

    public function page(): int
    {
        return $this->pageNumber;
    }

    public function toRequest(?string $copiedImagePath = null): array
    {
        return [
            'type' => 'image',
            'page' => $this->pageNumber,
            'x' => $this->x,
            'y' => $this->y,
            'width' => $this->width,
            'height' => $this->height,
            'path' => $copiedImagePath ?? $this->pngPath,
        ];
    }
}
