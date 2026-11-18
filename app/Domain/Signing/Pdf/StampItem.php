<?php

declare(strict_types=1);

namespace App\Domain\Signing\Pdf;

/**
 * Something stamped onto a page: text ({@see StampText}) or a PNG image
 * ({@see StampImage}). Coordinates are PDF points from the page's lower left
 * corner, as in the PDF itself; pages are numbered from 1.
 */
interface StampItem
{
    public function page(): int;

    /**
     * The item as the helper's protocol describes it. An image's path is
     * replaced by the copy inside the work directory.
     *
     * @return array<string, mixed>
     */
    public function toRequest(?string $copiedImagePath = null): array;
}
