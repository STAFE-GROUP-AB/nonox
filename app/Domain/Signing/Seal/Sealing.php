<?php

declare(strict_types=1);

namespace App\Domain\Signing\Seal;

use App\Domain\Signing\Pdf\PdfEngine;
use App\Domain\Signing\Pdf\PdfEngineStatus;
use App\Domain\Signing\Pdf\TimestampServers;
use App\Support\Instance\InstanceSettings;

/**
 * Whether finished documents are sealed on this installation (ADR-046
 * amendment, gap 5, package S10), and with what.
 *
 * Sealing is off until an administrator switches it on in /hq (the instance
 * setting signing.seal_enabled, off by default everywhere): the owner decided
 * on 2026-11-18 that the seal, long-term validation and the Verify page are
 * not switched on in the hosted service until the legal question of
 * eIDAS trust services is answered (gap 5, "Legal items"). A self-hoster
 * switches it on after installing the PDF engine. It also needs the engine
 * and a seal certificate (the company's own, else the instance's).
 *
 * TODO(legal-verify): om driftbolaget, när det förvarar företagets
 * förseglingsnyckel och skapar stämplar åt företaget, tillhandahåller en
 * betrodd tjänst (förvaltning av utrustning för skapande av elektroniska
 * stämplar på distans, art. 3.16 i eIDAS i lydelsen enligt (EU) 2024/1183)
 * med krav enligt art. 19 och 19a och tillsyn av PTS; verifiera punkt och
 * artikelnummer mot den konsoliderade texten. Until answered the hosted
 * service keeps the switch off (ADR-046 gap 5, "Legal items").
 */
final class Sealing
{
    /** The longest the queue waits for a timestamp server before sealing without one (gap 5: 24 hours; the default is one, legal review F5). */
    public const int MAX_RETRY_HOURS = 24;

    /** The default wait for a timestamp server (legal review F5): an hour, so the copies are not held back long. */
    public const int DEFAULT_RETRY_HOURS = 1;

    public static function switchedOn(): bool
    {
        return InstanceSettings::current()->signingSealEnabled();
    }

    /**
     * What a completed envelope of the company is sealed with now, or null when it is not sealed (switched
     * off, no engine, no usable certificate). Must run inside the company.
     */
    public static function credentialsFor(int $companyId): ?SealCredentials
    {
        if (! self::switchedOn() || ! app(PdfEngine::class)->isInstalled()) {
            return null;
        }

        return SealCertificates::forCompany($companyId);
    }

    /**
     * Whether a completed envelope of the company is to be sealed: switched on, the engine installed and a
     * certificate available. Decrypts nothing (it runs in the signing transaction). Must run inside the company.
     */
    public static function applies(int $companyId): bool
    {
        return self::switchedOn()
            && app(PdfEngine::class)->isInstalled()
            && self::sandboxProblem(checkEngine: false) === null
            && SealCertificates::available($companyId);
    }

    /**
     * Why sealing is unavailable because of the sandbox, or null. Only an installation that sets
     * NONOX_PDF_ENGINE_REQUIRE_SANDBOX has such a reason: then the engine must be configured for the
     * sandbox and, when $checkEngine, its (cached) health must say that it runs in it. The signing
     * transaction passes false: it starts no process; the seal job checks the health.
     */
    public static function sandboxProblem(bool $checkEngine = true): ?string
    {
        if (! (bool) config('nonox.signing.pdf_engine.require_sandbox_for_seal', false)) {
            return null;
        }

        $configured = config('nonox.signing.pdf_engine.sandbox') === 'bwrap';

        if ($configured && (! $checkEngine || app(PdfEngineStatus::class)->current()->sandboxed)) {
            return null;
        }

        return __('Försegling kräver att PDF-motorn körs i en sandlåda.');
    }

    /** @return list<string> the configured RFC 3161 servers, in order */
    public static function timestampUrls(): array
    {
        return array_slice(TimestampServers::configured(), 0, 5);
    }

    public static function timestampTimeout(): int
    {
        return max(1, min(60, (int) config('nonox.signing.timestamp.timeout', 10)));
    }

    /** Hours the queue keeps trying a timestamp server before sealing without a timestamp (0–24, default 1). */
    public static function retryHours(): int
    {
        return max(0, min(self::MAX_RETRY_HOURS, (int) config('nonox.signing.seal.retry_hours', self::DEFAULT_RETRY_HOURS)));
    }
}
