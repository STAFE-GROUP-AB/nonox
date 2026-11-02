<?php

declare(strict_types=1);

namespace App\Actions\AnnualReport;

use App\Domain\AnnualReport\AnnualAccounts;
use App\Domain\AnnualReport\AnnualReportDocument;
use App\Domain\AnnualReport\PledgedAssetKind;
use App\Domain\Closing\DividendLimit;
use App\Models\AnnualReport;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Saves the texts of a fiscal year's annual report. Signatories are given
 * one per line as "Name, role". Needs fiscal-years:manage.
 */
class SaveAnnualReport
{
    /**
     * @param  array<string, mixed>  $input  business_description, significant_events, dividend, average_employees, signing_place, signing_date, signatories, adoption_date, adoption_decision, certifier_name, certifier_role, pledged (kind => amount), pledged_other_text, contingent_liabilities, contingent_liabilities_text, group_commitments, events_after, events_effect_unknown, net_sales_comment
     */
    public function __invoke(User $user, FiscalYear $fiscalYear, array $input): AnnualReport
    {
        $company = Company::query()->findOrFail($fiscalYear->company_id);

        if (! $user->hasTeamPermission($company, 'fiscal-years:manage')) {
            throw new AuthorizationException;
        }

        if (! AnnualReportDocument::supports($company)) {
            throw ValidationException::withMessages(['report' => __('Årsredovisningen görs här för aktiebolag och ekonomiska föreningar.')]);
        }

        $validated = Validator::make($input, [
            'business_description' => ['nullable', 'string', 'max:5000'],
            'significant_events' => ['nullable', 'string', 'max:5000'],
            'dividend' => ['nullable', 'string'],
            'average_employees' => ['required', 'integer', 'min:0', 'max:100000'],
            'signing_place' => ['nullable', 'string', 'max:100'],
            'signing_date' => ['nullable', 'date_format:Y-m-d'],
            'signatories' => ['nullable', 'string', 'max:2000'],
            // ÅRL 8 kap. 3 §: the meeting adopts the accounts after the year has ended.
            'adoption_date' => ['nullable', 'date_format:Y-m-d', 'after:'.$fiscalYear->end_date->toDateString()],
            'adoption_decision' => ['nullable', 'string', 'max:2000'],
            'certifier_name' => ['nullable', 'string', 'max:255'],
            'certifier_role' => ['nullable', 'string', 'max:100'],
            'pledged' => ['nullable', 'array:'.implode(',', array_column(PledgedAssetKind::cases(), 'value'))],
            'pledged.*' => ['nullable', 'string', 'max:30'],
            'pledged_other_text' => ['nullable', 'string', 'max:2000'],
            'contingent_liabilities' => ['nullable', 'string', 'max:30'],
            'contingent_liabilities_text' => ['nullable', 'string', 'max:2000'],
            'group_commitments' => ['nullable', 'string', 'max:2000'],
            'events_after' => ['nullable', 'string', 'max:5000'],
            'events_effect_unknown' => ['nullable', 'boolean'],
            'net_sales_comment' => ['nullable', 'string', 'max:2000'],
        ], [
            'adoption_date.after' => __('Stämman kan fastställa räkningarna först efter räkenskapsårets slut, :date.', ['date' => $fiscalYear->end_date->toDateString()]),
        ], [
            'average_employees' => __('medelantal anställda'),
            'signing_date' => __('datum'),
            'adoption_date' => __('fastställelsedag'),
        ])->validate();

        try {
            $dividend = blank($validated['dividend'] ?? null) ? 0 : Money::parse((string) $validated['dividend'])->ore;
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['dividend' => __('Beloppet går inte att läsa. Skriv till exempel 12 500 eller 12 500,50.')]);
        }

        if ($dividend < 0) {
            throw ValidationException::withMessages(['dividend' => __('Utdelningen kan inte vara negativ.')]);
        }

        $pledged = [];

        foreach (PledgedAssetKind::cases() as $kind) {
            $amount = self::amount($validated['pledged'][$kind->value] ?? null, "pledged.{$kind->value}");

            if ($amount !== null && $amount !== 0) {
                $pledged[$kind->value] = $amount;
            }
        }

        $contingent = self::amount($validated['contingent_liabilities'] ?? null, 'contingent_liabilities');

        return CurrentCompany::run($company, function () use ($user, $company, $fiscalYear, $validated, $dividend, $pledged, $contingent): AnnualReport {
            // ABL 17 kap. 3 §: the proposal may not distribute more than the free equity (#84).
            DividendLimit::ensureWithin($company, $fiscalYear, $dividend);

            // BFNAR 2016:10 p. 5.5: a net sales variation over 30 % must be commented on (#93).
            $variation = AnnualAccounts::for($fiscalYear)->netSalesVariations()[0] ?? null;

            if ($variation !== null && blank($validated['net_sales_comment'] ?? null)) {
                throw ValidationException::withMessages(['net_sales_comment' => __('Nettoomsättningen har förändrats med mer än 30 procent mellan :from och :to. Kommentera förändringen (BFNAR 2016:10 punkt 5.5).', ['from' => $variation['from'], 'to' => $variation['to']])]);
            }

            $report = AnnualReport::query()->updateOrCreate(['fiscal_year_id' => $fiscalYear->id], [
                'business_description' => blank($validated['business_description'] ?? null) ? null : $validated['business_description'],
                'significant_events' => blank($validated['significant_events'] ?? null) ? null : $validated['significant_events'],
                'dividend_ore' => $dividend,
                'average_employees' => (int) $validated['average_employees'],
                'signing_place' => blank($validated['signing_place'] ?? null) ? null : $validated['signing_place'],
                'signing_date' => blank($validated['signing_date'] ?? null) ? null : $validated['signing_date'],
                'signatories' => self::signatories((string) ($validated['signatories'] ?? '')),
                'adoption_date' => blank($validated['adoption_date'] ?? null) ? null : $validated['adoption_date'],
                'adoption_decision' => blank($validated['adoption_decision'] ?? null) ? null : trim((string) $validated['adoption_decision']),
                'certifier_name' => blank($validated['certifier_name'] ?? null) ? null : trim((string) $validated['certifier_name']),
                'certifier_role' => blank($validated['certifier_role'] ?? null) ? null : trim((string) $validated['certifier_role']),
                'pledged_assets' => $pledged === [] ? null : $pledged,
                'pledged_other_text' => blank($validated['pledged_other_text'] ?? null) ? null : trim((string) $validated['pledged_other_text']),
                'contingent_liabilities_ore' => $contingent,
                'contingent_liabilities_text' => blank($validated['contingent_liabilities_text'] ?? null) ? null : trim((string) $validated['contingent_liabilities_text']),
                'group_commitments' => blank($validated['group_commitments'] ?? null) ? null : trim((string) $validated['group_commitments']),
                'events_after' => blank($validated['events_after'] ?? null) ? null : trim((string) $validated['events_after']),
                'events_effect_unknown' => (bool) ($validated['events_effect_unknown'] ?? false),
                'net_sales_comment' => blank($validated['net_sales_comment'] ?? null) ? null : trim((string) $validated['net_sales_comment']),
                'updated_by' => $user->id,
            ]);

            Audit::record('annual_report.saved', $report, ['fiscal_year' => $fiscalYear->label()], $company, $user);

            return $report;
        });
    }

    /** A non-negative amount in öre, or null when the field is empty. */
    private static function amount(mixed $value, string $field): ?int
    {
        if (blank($value)) {
            return null;
        }

        try {
            $ore = Money::parse((string) $value)->ore;
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([$field => __('Beloppet går inte att läsa. Skriv till exempel 12 500 eller 12 500,50.')]);
        }

        if ($ore < 0) {
            throw ValidationException::withMessages([$field => __('Beloppet kan inte vara negativt.')]);
        }

        return $ore;
    }

    /** @return list<array{name: string, role: string}> */
    public static function signatories(string $text): array
    {
        $people = [];

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $parts = array_map('trim', explode(',', $line, 2));

            if ($parts[0] !== '') {
                $people[] = ['name' => $parts[0], 'role' => $parts[1] ?? ''];
            }
        }

        return $people;
    }
}
