<?php

declare(strict_types=1);

namespace App\Domain\Signing;

use App\Models\Company;
use App\Models\SigningRequest;
use App\Models\User;

/**
 * What a module adds to the follow-up of a signature (ADR-075 §7): CRM wins
 * the deal chosen when sending and logs a note on the account. Registered in
 * SigningHooks by the module's service provider; a hook checks itself that
 * its module is on in the company. Runs inside the follow-up's transaction
 * and the company.
 */
interface SigningHook
{
    /**
     * After the core's own steps (a quote's order and invoice draft).
     *
     * @return list<string> steps that could not be taken, for the audit log and the notice
     */
    public function signed(Company $company, SigningRequest $request, ?User $actor, ?string $orderId, ?string $invoiceId): array;

    public function declined(Company $company, SigningRequest $request): void;

    /**
     * Users besides the sender who are told about the signature or the
     * decline (a deal's or an agreement's owner).
     *
     * @return list<int>
     */
    public function recipients(Company $company, SigningRequest $request): array;

    /**
     * Whether a member may get the company's copy of the signed document
     * (with its prices) when the company has no address of its own. A module
     * says no for its own roles, such as a work order's field worker.
     */
    public function mayReceiveCopy(Company $company, User $user, SigningRequest $request): bool;
}
