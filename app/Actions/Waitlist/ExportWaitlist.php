<?php

declare(strict_types=1);

namespace App\Actions\Waitlist;

use App\Domain\Waitlist\WaitlistOptions;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Support\Audit\Audit;
use App\Support\Csv;
use App\Support\Hq\HqAccess;
use Illuminate\Database\Eloquent\Builder;

/**
 * The waiting list as a CSV file for a spreadsheet (ADR-038): semicolons,
 * UTF-8 with BOM, guarded against formulas. Every export is written to the
 * audit log with its filters and row count, since the file holds personal
 * data.
 */
class ExportWaitlist
{
    /**
     * @param  Builder<WaitlistEntry>  $query  the entries to export, already filtered
     * @param  array<string, mixed>  $filters  the filters, for the audit log
     */
    public function __invoke(User $admin, Builder $query, array $filters = []): string
    {
        HqAccess::ensure($admin);

        $out = fopen('php://temp', 'r+');
        assert(is_resource($out));

        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Id', 'Anmäld', 'Status', 'Namn', 'E-post', 'Telefon', 'Företag', 'Organisationsnummer', 'Bransch', 'Anställda', 'Nuvarande system', 'Vill testa', 'Varför byta', 'Beslutad', 'Anteckning'], ';', '"', '');

        $count = 0;

        foreach ($query->clone()->orderBy('created_at')->orderBy('id')->lazy(500) as $entry) {
            $count++;

            fputcsv($out, Csv::row([
                $entry->id,
                $entry->created_at->format('Y-m-d H:i'),
                $entry->status->label(),
                $entry->name,
                $entry->email,
                (string) $entry->phone,
                $entry->company_name,
                (string) $entry->org_number,
                WaitlistOptions::label('industry', $entry->industry),
                WaitlistOptions::label('employees', $entry->employees),
                WaitlistOptions::label('current_system', $entry->current_system),
                (string) $entry->wants_to_test,
                (string) $entry->why_switch,
                $entry->decided_at?->format('Y-m-d H:i') ?? '',
                (string) $entry->decision_note,
            ]), ';', '"', '');
        }

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        Audit::instance('waitlist.exported', null, ['rows' => $count, 'filters' => $filters], $admin);

        return $csv;
    }
}
