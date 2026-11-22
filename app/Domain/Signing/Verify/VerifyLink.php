<?php

declare(strict_types=1);

namespace App\Domain\Signing\Verify;

use App\Models\Company;
use App\Modules\StaffLedger\Support\QrSvg;
use App\Support\Instance\InstanceSettings;

/**
 * The link and QR code to the Verify page that a signing certificate or
 * summary carries (ADR-046 amendment, gap 6, S12). The hash is in the
 * fragment (#sha256=…), which a browser never sends to a server; the page
 * reads it in the browser. Nothing is shown while the page is switched off
 * (signing.verify_enabled, and the company's own switch), since the link would lead nowhere.
 */
final class VerifyLink
{
    /** On for this company: the installation's switch and the company's own (Inställningar för företaget → Signering). */
    public static function enabled(Company $company): bool
    {
        return InstanceSettings::current()->signingVerifyEnabled() && (bool) $company->signing_verify_enabled;
    }

    /** The short link, or null while the page is off or for anything that is not a SHA-256. */
    public static function url(?string $sha256, Company $company): ?string
    {
        if (! self::enabled($company) || $sha256 === null || preg_match('/^[0-9a-f]{64}$/D', $sha256) !== 1) {
            return null;
        }

        return route('signing.verifyPage').'#sha256='.$sha256;
    }

    /** The QR code of the link as an image data URI for a PDF, or null. */
    public static function qrDataUri(?string $sha256, Company $company): ?string
    {
        $url = self::url($sha256, $company);

        return $url === null ? null : 'data:image/svg+xml;base64,'.base64_encode(QrSvg::render($url, 240));
    }
}
