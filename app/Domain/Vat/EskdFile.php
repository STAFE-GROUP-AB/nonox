<?php

declare(strict_types=1);

namespace App\Domain\Vat;

use App\Domain\Company\OrganisationNumber;
use App\Models\Company;

/**
 * The momsdeklaration as a file to upload in Skatteverket's e-service
 * (eSKDUpload, version 6.0).
 *
 * TODO(legal-verify): the element names and order follow the eSKDUpload 6.0
 * DTD as published in third-party documentation; validate a file in
 * Skatteverket's test service before relying on it, including the format of
 * OrgNr and that Period is the last month of the period (ÅÅÅÅMM).
 */
final class EskdFile
{
    /** Element order of the DTD. */
    private const array ORDER = [
        'ForsMomsEjAnnan', 'UttagMoms', 'UlagMargbesk', 'HyrinkomstFriv',
        'InkopVaruAnnatEg', 'InkopTjanstAnnatEg', 'InkopTjanstUtomEg', 'InkopVaruSverige', 'InkopTjanstSverige',
        'MomsUlagImport',
        'ForsVaruAnnatEg', 'ForsVaruUtomEg', 'InkopVaruMellan3p', 'ForsVaruMellan3p', 'ForsTjSkskAnnatEg', 'ForsTjOvrUtomEg', 'ForsKopareSkskSverige', 'ForsOvrigt',
        'MomsUtgHog', 'MomsUtgMedel', 'MomsUtgLag',
        'MomsInkopUtgHog', 'MomsInkopUtgMedel', 'MomsInkopUtgLag',
        'MomsImportUtgHog', 'MomsImportUtgMedel', 'MomsImportUtgLag',
        'MomsIngAvdr', VatBoxes::PAYABLE_ELEMENT,
    ];

    public static function render(Company $company, VatReturn $return): string
    {
        $boxes = $return->boxes();
        $values = [VatBoxes::PAYABLE_ELEMENT => $boxes[VatBoxes::PAYABLE]];

        foreach (VatBoxes::BOXES as $box => $definition) {
            if (($boxes[$box] ?? 0) !== 0) {
                $values[$definition['element']] = (int) $boxes[$box];
            }
        }

        $organisationNumber = OrganisationNumber::tryFrom((string) $company->org_number)?->formatted() ?? (string) $company->org_number;
        $lines = [
            '<?xml version="1.0" encoding="ISO-8859-1"?>',
            '<!DOCTYPE eSKDUpload PUBLIC "-//Skatteverket, Sweden//DTD Skatteverket eSKDUpload-DTD Version 6.0//SV" "https://www1.skatteverket.se/demoeskd/eSKDUpload_6p0.dtd">',
            '<eSKDUpload Version="6.0">',
            "  <OrgNr>{$organisationNumber}</OrgNr>",
            '  <Moms>',
            "    <Period>{$return->end->format('Ym')}</Period>",
        ];

        foreach (self::ORDER as $element) {
            if (array_key_exists($element, $values)) {
                $lines[] = "    <{$element}>{$values[$element]}</{$element}>";
            }
        }

        $lines[] = '  </Moms>';
        $lines[] = '</eSKDUpload>';

        return implode("\n", $lines)."\n";
    }

    public static function filename(Company $company, VatReturn $return): string
    {
        $digits = OrganisationNumber::tryFrom((string) $company->org_number)->digits ?? (string) $company->id;

        return "moms_{$digits}_{$return->end->format('Ym')}.xml";
    }
}
