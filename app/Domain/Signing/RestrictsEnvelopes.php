<?php

declare(strict_types=1);

namespace App\Domain\Signing;

use App\Models\Company;
use App\Models\SigningEnvelope;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * A Signable whose envelopes have rules of their own beyond who may send
 * the document (ADR-046 amendment, gap 10, package S7): a standalone
 * document's envelope can be confidential, and someone else's envelope is
 * reminded, moved and withdrawn only with signing:manage.
 *
 * The engine asks the document, so no module is named in the core: the
 * file route, the deadline change, the reminder and the void all call it
 * when the document implements it. A document that does not implement it
 * keeps the rules of Signable::authorizeSigning alone.
 */
interface RestrictsEnvelopes
{
    /**
     * May the user see the envelope, its files and its certificates? A
     * confidential envelope is seen only by the person who sent it and by
     * members who manage signing; the answer holds also while the module is
     * off, for the archive.
     */
    public function mayViewEnvelope(User $user, Company $company, SigningEnvelope $envelope): bool;

    /**
     * May the user remind, change the last day of, or withdraw the envelope?
     * Sending rights for one's own envelope, management rights for another's.
     *
     * @throws AuthorizationException
     */
    public function authorizeEnvelope(User $user, SigningEnvelope $envelope): void;
}
