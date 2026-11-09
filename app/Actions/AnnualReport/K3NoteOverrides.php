<?php

declare(strict_types=1);

namespace App\Actions\AnnualReport;

use App\Domain\AnnualReport\K3Note;
use App\Domain\AnnualReport\K3Notes;
use App\Domain\AnnualReport\K3SizeClass;
use App\Models\AnnualReport;
use App\Models\AnnualReportNoteOverride;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * A person's own wording of a K3 note (ADR-059, A.2.7; spec S1 Behaviour 6).
 * The override replaces the text only; the note's data and whether it is
 * printed still come from the books (K3Notes). The hash of the generated text
 * it replaced is kept, so the report can show that the books changed after
 * the override was written. Needs fiscal-years:manage; refused for a year that
 * is not K3 and once the report is adopted in a closed year (K3ReportWrite).
 * The audit log gets ids and the note key, never the text or the reason.
 */
class K3NoteOverrides
{
    public function set(User $user, FiscalYear $fiscalYear, string $noteKey, string $text, string $reason): AnnualReportNoteOverride
    {
        $company = K3ReportWrite::company($user, $fiscalYear);

        $validated = Validator::make(['note_key' => $noteKey, 'text' => trim($text), 'reason' => trim($reason)], [
            'note_key' => ['required', 'string', 'max:60'],
            'text' => ['required', 'string', 'max:5000'],
            'reason' => ['required', 'string', 'max:500'],
        ], [], [
            'text' => __('text'),
            'reason' => __('skäl'),
        ])->validate();

        if (! K3Notes::isKey($noteKey)) {
            throw ValidationException::withMessages(['note_key' => __('Det finns ingen sådan not.')]);
        }

        return CurrentCompany::run($company, fn (): AnnualReportNoteOverride => DB::transaction(function () use ($user, $company, $fiscalYear, $validated, $noteKey): AnnualReportNoteOverride {
            $report = K3ReportWrite::lockedReport($user, $company, $fiscalYear);

            $override = AnnualReportNoteOverride::query()->updateOrCreate(['annual_report_id' => $report->id, 'note_key' => $noteKey], [
                'text' => $validated['text'],
                'reason' => $validated['reason'],
                'generated_hash' => K3Note::hash(self::generatedText($company, $fiscalYear, $report, $noteKey)),
                'set_by' => $user->id,
            ]);

            Audit::record('annual_report.note_override_set', $override, [
                'fiscal_year_id' => $fiscalYear->id,
                'annual_report_id' => $report->id,
                'note_key' => $noteKey,
            ], $company, $user);

            return $override;
        }));
    }

    public function remove(User $user, FiscalYear $fiscalYear, string $noteKey): void
    {
        $company = K3ReportWrite::company($user, $fiscalYear);

        CurrentCompany::run($company, fn () => DB::transaction(function () use ($user, $company, $fiscalYear, $noteKey): void {
            $report = K3ReportWrite::lockedReport($user, $company, $fiscalYear);
            $override = AnnualReportNoteOverride::query()->where('annual_report_id', $report->id)->where('note_key', $noteKey)->first();

            if ($override === null) {
                return;
            }

            $override->delete();

            Audit::record('annual_report.note_override_removed', $override, [
                'fiscal_year_id' => $fiscalYear->id,
                'annual_report_id' => $report->id,
                'note_key' => $noteKey,
            ], $company, $user);
        }));
    }

    /**
     * The generated text the override replaces; empty when the note is not
     * present now. The size does not change any text (it only refuses), so
     * the notes are generated as for a mindre företag.
     *
     * @return list<string>
     */
    private static function generatedText(Company $company, FiscalYear $fiscalYear, AnnualReport $report, string $noteKey): array
    {
        return K3Notes::for($company, $fiscalYear, $report, K3SizeClass::Mindre)->note($noteKey)->generatedText ?? [];
    }
}
