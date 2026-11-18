<?php

declare(strict_types=1);

namespace App\Domain\Documents\Sources;

/**
 * What an adapter needs to know about a row to link it and describe it: the
 * file's id in the source's table, the parent's id where the route needs
 * one (a voucher, a message, a thread, or the kind of file) and the short
 * reference the query selected.
 */
final readonly class DocumentRef
{
    public function __construct(
        public string $sourceId,
        public ?string $parentId,
        public ?string $ref,
    ) {}
}
