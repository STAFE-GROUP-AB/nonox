<?php

declare(strict_types=1);

namespace App\Support\Ixbrl;

use RuntimeException;

/**
 * The iXBRL file was not made: the report prints something the taxonomy
 * map cannot tag, a required text is missing, or the configured taxonomy
 * does not know a concept. The items are Swedish, for the user.
 */
final class IxbrlRefused extends RuntimeException
{
    /** @param  list<string>  $items */
    public function __construct(public readonly array $items, string $message)
    {
        parent::__construct($message);
    }
}
