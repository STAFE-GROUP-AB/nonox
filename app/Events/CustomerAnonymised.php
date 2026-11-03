<?php

declare(strict_types=1);

namespace App\Events;

/**
 * A customer that is in the books was anonymised on request (GDPR art. 17,
 * EraseRegisterEntry). Dispatched inside the erasure's transaction, so a
 * module that keeps more about the customer (CRM) erases it in the same
 * transaction or not at all.
 */
final readonly class CustomerAnonymised
{
    public function __construct(
        public int $companyId,
        public int $customerId,
    ) {}
}
