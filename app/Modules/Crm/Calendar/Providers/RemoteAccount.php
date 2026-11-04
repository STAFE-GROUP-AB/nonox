<?php

declare(strict_types=1);

namespace App\Modules\Crm\Calendar\Providers;

/**
 * The account the user signed in with at Google or Microsoft (ADR-049).
 * `organisation`: a Google Workspace or Microsoft Entra (work or school)
 * account, not a private one. Private accounts are refused unless the
 * company allows them.
 */
final readonly class RemoteAccount
{
    public function __construct(
        public ?string $email,
        public bool $organisation,
    ) {}
}
