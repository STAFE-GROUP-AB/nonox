<?php

declare(strict_types=1);

namespace App\Actions\AnnualReport;

use App\Domain\AnnualReport\AnnualReportDocument;
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
     * @param  array<string, mixed>  $input  business_description, significant_events, dividend, average_employees, signing_place, signing_date, signatories, adoption_date, adoption_decision, certifier_name, certifier_role
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

        return CurrentCompany::run($company, function () use ($user, $company, $fiscalYear, $validated, $dividend): AnnualReport {
            // ABL 17 kap. 3 §: the proposal may not distribute more than the free equity (#84).
            DividendLimit::ensureWithin($company, $fiscalYear, $dividend);

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
                'updated_by' => $user->id,
            ]);

            Audit::record('annual_report.saved', $report, ['fiscal_year' => $fiscalYear->label()], $company, $user);

            return $report;
        });
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
