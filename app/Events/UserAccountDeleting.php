<?php

declare(strict_types=1);

namespace App\Events;

/**
 * A user is deleting their account ("Radera mitt konto", DeleteAccount).
 * Dispatched inside its transaction before the remaining references to the
 * user are counted, so a module that keeps something that is the user's
 * alone (CRM's calendar links and connected calendars, ADR-049) removes it
 * in the same transaction.
 */
final readonly class UserAccountDeleting
{
    public function __construct(public int $userId) {}
}
