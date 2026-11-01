<?php

declare(strict_types=1);

namespace App\Modules\Banking\Domain;

/** Where to send the user to approve the consent in the bank. */
final readonly class AuthorisationStart
{
    public function __construct(
        public string $url,
        public ?string $authorisationId = null,
    ) {}
}
