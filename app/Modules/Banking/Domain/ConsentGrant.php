<?php

declare(strict_types=1);

namespace App\Modules\Banking\Domain;

use Carbon\CarbonImmutable;

/** A consent the user approved in the bank, with the accounts it covers. */
final readonly class ConsentGrant
{
    /**
     * @param  list<ProviderAccount>  $accounts
     */
    public function __construct(
        public string $sessionId,
        public ?CarbonImmutable $validUntil,
        public array $accounts,
    ) {}
}
