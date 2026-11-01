<?php

declare(strict_types=1);

namespace App\Modules\Banking\Domain;

/** One page of transactions; $continuation is null on the last page. */
final readonly class TransactionPage
{
    /**
     * @param  list<ProviderTransaction>  $transactions
     */
    public function __construct(
        public array $transactions,
        public ?string $continuation,
    ) {}
}
