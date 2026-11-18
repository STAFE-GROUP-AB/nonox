<?php

declare(strict_types=1);

namespace App\Domain\Signing\Pdf;

use InvalidArgumentException;

/**
 * A line of text stamped onto a page. The font is "auto" (Noto Sans, or Noto
 * Sans CJK when the text holds Chinese, Japanese or Korean), or one of
 * "sans", "sans-bold", "cjk", "cjk-bold".
 */
final readonly class StampText implements StampItem
{
    public const array FONTS = ['auto', 'sans', 'sans-bold', 'cjk', 'cjk-bold'];

    public function __construct(
        public int $pageNumber,
        public float $x,
        public float $y,
        public string $text,
        public int $fontSize = 10,
        public string $font = 'auto',
    ) {
        if ($pageNumber < 1 || $pageNumber > PdfLimits::MAX_PAGES) {
            throw new InvalidArgumentException('The page must be between 1 and the page limit.');
        }

        if ($text === '' || mb_strlen($text) > 2000 || ! mb_check_encoding($text, 'UTF-8')) {
            throw new InvalidArgumentException('The text must be 1 to 2000 characters of UTF-8.');
        }

        if ($fontSize < 4 || $fontSize > 72) {
            throw new InvalidArgumentException('The font size must be 4 to 72 points.');
        }

        if (! in_array($font, self::FONTS, true)) {
            throw new InvalidArgumentException('Unknown font.');
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
            'type' => 'text',
            'page' => $this->pageNumber,
            'x' => $this->x,
            'y' => $this->y,
            'text' => $this->text,
            'font_size' => $this->fontSize,
            'font' => $this->font,
        ];
    }
}
