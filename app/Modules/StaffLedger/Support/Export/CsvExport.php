<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Support\Export;

use App\Modules\StaffLedger\Models\Entry;
use App\Support\Csv;

/**
 * The ledger as CSV: one row per entry, corrections included, semicolon
 * separated and UTF-8 with BOM so a spreadsheet opens it with å, ä and ö.
 */
final class CsvExport
{
    public static function render(ControlVisitReport $report): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        fwrite($handle, "\u{FEFF}");
        fputcsv($handle, Csv::row([
            __('Post'), __('Lokal'), __('Adress'), __('Namn'), __('Person- eller samordningsnummer'), __('Arbetsgivare'), __('Arbetsgivarens organisationsnummer'),
            __('Händelse'), __('In/ut'), __('Tid'), __('Mottagen'), __('Sätt'), __('Rättar post'), __('Rättelse'), __('Orsak'), __('Registrerad av'), __('Anmärkningar'),
        ]), ';', '"', '');

        foreach ($report->entries as $entry) {
            /** @var Entry $entry */
            $location = $report->locations->firstWhere('id', $entry->location_id);

            fputcsv($handle, Csv::row([
                $entry->id,
                $location?->name,
                $location?->address(),
                $entry->person_name,
                ControlVisitReport::formatNumber($entry->personal_number),
                $entry->employer_name,
                $entry->employer_org_number,
                $entry->type->label(),
                $entry->effectiveDirection()?->label(),
                $entry->occurred_at->format('Y-m-d H:i:s'),
                $entry->received_at->format('Y-m-d H:i:s'),
                $entry->method->label(),
                $entry->corrects_entry_id,
                $entry->correction?->label(),
                $entry->reason,
                $entry->user?->name,
                implode(', ', $entry->flags),
            ]), ';', '"', '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
