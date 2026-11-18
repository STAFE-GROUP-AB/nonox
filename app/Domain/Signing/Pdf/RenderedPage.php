<?php

declare(strict_types=1);

namespace App\Domain\Signing\Pdf;

/** One page rendered as a PNG image, with its size in pixels and in PDF points. */
final readonly class RenderedPage
{
    public function __construct(
        public int $page,
        public string $path,
        public int $widthPx,
        public int $heightPx,
        public float $widthPt,
        public float $heightPt,
    ) {}
}
