<?php

declare(strict_types=1);

namespace App\Domain\Signing\Providers;

use App\Models\SigningProvider;

/**
 * A pluggable signing provider (ADR-046 amendment, gap 12, package S17): a
 * service outside NONOX where the signer identifies themselves and signs.
 * Registered by key in config (nonox.signing.providers.drivers) and chosen
 * per signer as the check "PROVIDER" (gap 3). Drivers: "csc" (a remote
 * signing service with the Cloud Signature Consortium API v2) and "fake"
 * (tests). A later "bankid" driver fits the same contract (S18, deferred).
 *
 * A run is a sequence of browser steps driven by NONOX (SignWithProvider):
 *
 * 1. start() answers the first step, usually a redirect to the provider.
 * 2. collect() is called when a step comes back (the callback's query; a
 *    QR-code provider would be polled with an empty one). It answers the
 *    next step: another redirect, the signer's certificate (a provider that
 *    signs the document's own digest), the signature, or a failure.
 * 3. For a provider that signsDocumentDigest(), NONOX then prepares the
 *    signer's PAdES signature in the PDF engine and calls authorizeDigest()
 *    with the digest to be signed; the signature that collect() returns at
 *    the end becomes the signer's own signature in their revision of the PDF.
 *    A provider that does not (BankID signs its own data) would answer
 *    ProviderStep::completed() with evidence instead.
 *
 * Drivers keep their working data (tokens, verifiers, ids) in
 * ProviderRun::$secrets, which NONOX stores encrypted for the run and
 * empties when it ends; they never log it. Every outgoing request goes to
 * an address that passed ProviderAddress (public https only, pinned, no
 * redirects).
 */
interface SignatureProvider
{
    /** The driver key, as stored in signing_providers.driver. */
    public function key(): string;

    /** The level the company states the provider gives, repeated only as the provider's claim (principle 4). */
    public function claimedLevel(SigningProvider $config): SignatureLevel;

    /**
     * Checks a configuration against the service before it is saved (for CSC: the info method).
     *
     * @throws ProviderFailure
     */
    public function describe(SigningProvider $config): ProviderDescription;

    /** Whether the signature is made over the PDF's own digest (a PAdES signature in the signer's revision). */
    public function signsDocumentDigest(): bool;

    /** @throws ProviderFailure */
    public function start(ProviderRun $run): ProviderStep;

    /**
     * @param  array<string, string>  $callback  the query the provider sent the browser back with
     *
     * @throws ProviderFailure
     */
    public function collect(ProviderRun $run, array $callback): ProviderStep;

    /**
     * The signer authorises exactly this digest (the SHA-256 of the signature's signed attributes).
     *
     * @throws ProviderFailure
     */
    public function authorizeDigest(ProviderRun $run, string $digest): ProviderStep;
}
