<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Labels;

/**
 * One placed element on a label, in millimetres from the label's top-left
 * corner. A text box has lines and a size in points; an image box (logo,
 * barcode, QR) has a data URI. A barcode may have its digits (caption)
 * under the bars.
 */
final readonly class LabelBox
{
    /** @param  list<string>  $lines */
    public function __construct(
        public LabelElement $element,
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public array $lines = [],
        public float $pt = 0.0,
        public bool $bold = false,
        public string $align = 'left',
        public ?string $src = null,
        public ?string $caption = null,
        public float $captionPt = 0.0,
        public float $barsHeight = 0.0,
    ) {}

    public function isText(): bool
    {
        return $this->src === null;
    }
}
