<?php

declare(strict_types=1);

namespace App\Modules\Boardroom\Support;

use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Modules\Boardroom\Domain\EventHash;
use App\Modules\Boardroom\Domain\MinutesStatus;
use App\Modules\Boardroom\Domain\RegisterException;
use App\Modules\Boardroom\Models\Meeting;
use App\Modules\Boardroom\Models\Minutes;
use App\Modules\Boardroom\Models\ShareEvent;
use App\Modules\Boardroom\Models\Shareholder;
use App\Support\Csv;
use App\Support\Money\Money;
use App\Support\Tenancy\ArchivedCompany;

/**
 * The boardroom's part of the archive package (ADR-070 §4, AGENTS.md §10):
 * the full register at the end of the fiscal year as PDF, the register's
 * whole history up to the package as CSV with each entry's hash, and the
 * approved minutes of the year's meetings and of its annual general meeting
 * as stored, so they keep their SHA-256. The register itself is kept longer
 * than the package's seven years: while the company exists and ten years
 * after it is dissolved (ABL 5 kap. 3 §).
 */
final class BoardroomArchive
{
    /**
     * @return array{generated: array<string, string>, stored: array<string, string>}
     */
    public static function files(User $user, Company $company, FiscalYear $year): array
    {
        $generated = [];
        $stored = [];
        $mayRead = fn (string $permission): bool => (bool) ArchivedCompany::reading(fn (): bool => $user->hasTeamPermission($company, $permission));

        if ($mayRead('share-register:view') && ShareEvent::query()->exists()) {
            try {
                $state = ShareRegister::at($year->end_date);
                $generated["aktiebok/aktiebok-{$year->end_date->format('Ymd')}.pdf"] = BoardroomPdf::register($company, $state, $year->end_date, false, $user);
            } catch (RegisterException) {
                // The history below is still written; the verify command reports the broken register.
            }

            $generated['aktiebok/handelser.csv'] = self::history();
            $generated['aktiebok/hashkedja.json'] = self::chain();
        }

        if ($mayRead('meetings:view')) {
            $meetings = Meeting::query()
                ->where(fn ($query) => $query->whereBetween('meeting_date', [$year->start_date->toDateString(), $year->end_date->toDateString()])->orWhere('fiscal_year_id', $year->id))
                ->pluck('id');

            foreach (Minutes::query()->whereIn('meeting_id', $meetings)->where('status', MinutesStatus::Approved->value)->with('meeting:id,meeting_date,kind')->orderBy('id')->get() as $minutes) {
                $meeting = $minutes->meeting;
                $stored['protokoll/'.($meeting?->meeting_date->format('Ymd') ?? 'okant').'-'.mb_strtolower((string) $meeting?->kind->value)."-{$minutes->id}.pdf"] = (string) $minutes->pdf_path;
            }
        }

        return ['generated' => $generated, 'stored' => $stored];
    }

    /**
     * Every entry's canonical payload, the text that is hashed, with the
     * previous hash and its own, so the chain can be recomputed without NONOX:
     * sha256(previous_hash + payload) must equal hash, entry by entry. Personal
     * and foreign id numbers are in it as HMAC values only (EventHash).
     */
    private static function chain(): string
    {
        $entries = array_map(fn ($event): array => [
            'nummer' => $event->sequence,
            'foregaende_hash' => $event->previous_hash,
            'payload' => EventHash::canonicalJson($event),
            'hash' => $event->hash,
        ], ShareRegister::events());

        return (string) json_encode([
            'beskrivning' => 'Aktiebokens hashkedja. För varje händelse ska SHA-256 av foregaende_hash (tom för den första) följt av payload, som UTF-8, vara lika med hash. Person- och id-nummer finns bara som HMAC.',
            'handelser' => $entries,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Every entry, voided ones and corrections included, readable in a spreadsheet. */
    private static function history(): string
    {
        $events = ShareRegister::events();
        $holders = Shareholder::query()->get()->keyBy('id');
        $sequence = collect($events)->pluck('sequence', 'id');
        $out = fopen('php://temp', 'w+b');

        if ($out === false) {
            return '';
        }

        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, Csv::row(['nummer', 'handelse', 'galler_fran', 'beslutad', 'registrerad', 'infort', 'aktier_fran', 'aktier_till', 'fran_agare', 'till_agare', 'till_agare_id_nummer', 'till_agare_adress', 'aktiekapital_andring', 'pris', 'kvot', 'galler_handelse', 'anteckning', 'underlag', 'skal', 'hash', 'foregaende_hash']), ';', '"', '');

        foreach ($events as $event) {
            $snapshot = (array) $event->holder_snapshot;
            fputcsv($out, Csv::row([
                $event->sequence,
                $event->kind->label(),
                $event->effective_date->toDateString(),
                $event->decided_on?->toDateString(),
                $event->registered_on?->toDateString(),
                $event->recorded_at->format('Y-m-d H:i:s'),
                $event->share_from,
                $event->share_to,
                $event->from_holder_id === null ? null : $holders->get($event->from_holder_id)?->name,
                $snapshot['name'] ?? ($event->to_holder_id === null ? null : $holders->get($event->to_holder_id)?->name),
                $snapshot === [] ? null : Shareholder::identity($snapshot),
                $snapshot === [] ? null : Shareholder::address($snapshot),
                $event->capital_change_ore === null ? null : Money::ofOre($event->capital_change_ore)->format(false),
                $event->consideration_ore === null ? null : Money::ofOre($event->consideration_ore)->format(false),
                $event->ratio,
                $event->refers_to_event_id === null ? null : $sequence[$event->refers_to_event_id] ?? null,
                $event->note,
                $event->document_reference,
                $event->reason,
                $event->hash,
                $event->previous_hash,
            ]), ';', '"', '');
        }

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }
}
