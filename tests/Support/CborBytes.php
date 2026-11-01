<?php

declare(strict_types=1);

namespace Tests\Support;

/** @internal a CBOR byte string, as opposed to a text string */
final class CborBytes
{
    public function __construct(public readonly string $bytes) {}
}
