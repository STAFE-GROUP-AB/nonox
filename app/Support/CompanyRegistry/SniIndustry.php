<?php

declare(strict_types=1);

namespace App\Support\CompanyRegistry;

use App\Domain\Company\Industry;

/**
 * Which of our industries an SNI code suggests (ADR-035 addendum). The
 * register holds SNI 2025 (ADR-047); the divisions and codes below are
 * checked against database/data/sni/sni-2025.json, SCB's own list. It is
 * a suggestion the user can change, never a decision: a code that fits no
 * industry with its own page suggests Other.
 *
 * 56300 is "Barverksamhet" in SCB's list (a bar or pub, not a cafe), so it
 * suggests Restaurant: the register has no code that means cafe.
 *
 * The register cannot tell an accounting firm from an economy consultant
 * (both are 69.20), so the more common one, the accounting firm, is
 * suggested. Division 74 (design, photography, translation) is not
 * consultancy and suggests Other.
 */
final class SniIndustry
{
    public static function for(?string $sniCode): ?Industry
    {
        $code = (string) preg_replace('/\D/', '', (string) $sniCode);

        if (strlen($code) < 2) {
            return null;
        }

        $division = (int) substr($code, 0, 2);

        return match (true) {
            in_array($code, ['56110', '56120', '56300'], true) => Industry::Restaurant,
            in_array($code, ['96210', '96220', '96230'], true) => Industry::HairBeauty,
            $code === '69201' => Industry::AccountingFirm,
            $division === 62, $code === '70200', $division === 71 => Industry::Consultant,
            $division >= 41 && $division <= 43 => Industry::Crafts,
            $division === 47 => Industry::Retail,
            $division >= 10 && $division <= 33 => Industry::Manufacturing,
            default => Industry::Other,
        };
    }
}
