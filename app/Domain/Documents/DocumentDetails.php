<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use App\Models\Company;
use App\Models\DocumentTag;
use App\Models\FiscalYear;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Validates what a person enters about a document, on upload and on edit,
 * and computes its retain_until date from the retention rule.
 */
final class DocumentDetails
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     attributes: array{retention_class: string, title: string, category: string, is_accounting_record: bool, fiscal_year_id: ?int, contract_ends_on: ?string, contract_retention_years: ?int, keep_until: ?string, retain_until: ?string, contains_personal_data: bool},
     *     tags: list<string>
     * }
     */
    public static function validate(User $user, Company $company, array $input): array
    {
        $data = Validator::make($input, [
            'title' => ['required', 'string', 'max:200'],
            'category' => ['required', 'string', 'max:60'],
            'retention_class' => ['required', Rule::enum(RetentionClass::class)],
            'is_accounting_record' => ['nullable', 'boolean'],
            'fiscal_year_id' => ['nullable', 'integer', Rule::requiredIf(self::needsFiscalYear($input))],
            'contract_ends_on' => ['nullable', 'date_format:Y-m-d'],
            'contract_retention_years' => ['nullable', 'integer', 'min:0', 'max:'.DocumentRetention::MAX_CONTRACT_YEARS],
            'keep_until' => ['nullable', 'date_format:Y-m-d'],
            'contains_personal_data' => ['required', Rule::in(['0', '1', 0, 1, true, false])],
            'tags' => ['nullable', 'string', 'max:500'],
        ], [
            'title.required' => __('Ge dokumentet en rubrik.'),
            'title.max' => __('Rubriken får vara högst 200 tecken.'),
            'category.required' => __('Välj en kategori.'),
            'category.max' => __('Kategorin får vara högst 60 tecken.'),
            'retention_class.required' => __('Välj hur länge dokumentet ska sparas.'),
            'fiscal_year_id.required' => __('Välj räkenskapsåret som räkenskapsinformationen hör till.'),
            'contains_personal_data.required' => __('Svara på om dokumentet innehåller personuppgifter.'),
            'contract_retention_years.max' => __('Välj högst :max år.', ['max' => DocumentRetention::MAX_CONTRACT_YEARS]),
        ])->validate();

        $class = RetentionClass::from((string) $data['retention_class']);

        if (! in_array($class, DocumentAccess::classes($user, $company, 'documents:manage'), true)) {
            throw ValidationException::withMessages(['retention_class' => $class === RetentionClass::Personnel
                ? __('Bara den som får se känsliga löneuppgifter kan spara personaldokument.')
                : __('Du kan bara spara personaldokument.')]);
        }

        $fiscalYear = null;

        if (filled($data['fiscal_year_id'] ?? null)) {
            // The global company scope: another company's fiscal year is not found.
            $fiscalYear = FiscalYear::query()->find((int) $data['fiscal_year_id']);

            if (! $fiscalYear instanceof FiscalYear || $fiscalYear->company_id !== $company->id) {
                throw ValidationException::withMessages(['fiscal_year_id' => __('Välj ett räkenskapsår i företaget.')]);
            }
        }

        $accountingRecord = $class === RetentionClass::Contract && (bool) ($data['is_accounting_record'] ?? false);

        // The fiscal year only matters for räkenskapsinformation; another class keeps none.
        if ($class !== RetentionClass::Accounting && ! $accountingRecord) {
            $fiscalYear = null;
        }

        $contractEnds = $class === RetentionClass::Contract && filled($data['contract_ends_on'] ?? null) ? CarbonImmutable::parse((string) $data['contract_ends_on']) : null;
        $contractYears = $class === RetentionClass::Contract ? (int) ($data['contract_retention_years'] ?? 0) : null;
        $keepUntil = filled($data['keep_until'] ?? null) ? CarbonImmutable::parse((string) $data['keep_until']) : null;
        $ruleDate = DocumentRetention::ruleDate($class, $fiscalYear, $contractEnds, $contractYears, $accountingRecord);
        $latest = DocumentRetention::maxKeepUntil($ruleDate);

        if ($keepUntil !== null && $keepUntil->greaterThan($latest)) {
            throw ValidationException::withMessages(['keep_until' => __('Välj ett datum senast :date, högst :years år efter regeln.', ['date' => $latest->toDateString(), 'years' => DocumentRetention::MAX_EXTRA_YEARS])]);
        }

        $retainUntil = DocumentRetention::retainUntil($ruleDate, $keepUntil);

        return [
            'attributes' => [
                // The class first: a personnel document's title is stored encrypted (Document::title()).
                'retention_class' => $class->value,
                'title' => trim((string) $data['title']),
                'category' => trim((string) $data['category']),
                'is_accounting_record' => $accountingRecord,
                'fiscal_year_id' => $fiscalYear?->id,
                'contract_ends_on' => $contractEnds?->toDateString(),
                'contract_retention_years' => $contractYears,
                'keep_until' => $keepUntil?->toDateString(),
                'retain_until' => $retainUntil?->toDateString(),
                'contains_personal_data' => in_array($data['contains_personal_data'], ['1', 1, true], true),
            ],
            'tags' => self::tags((string) ($data['tags'] ?? '')),
        ];
    }

    /** @param  array<string, mixed>  $input */
    private static function needsFiscalYear(array $input): bool
    {
        $class = $input['retention_class'] ?? null;

        return $class === RetentionClass::Accounting->value
            || ($class === RetentionClass::Contract->value && in_array($input['is_accounting_record'] ?? false, [true, 1, '1'], true));
    }

    /**
     * Tags from a comma-separated text: trimmed, at most 40 characters each,
     * without duplicates (case-insensitive), at most ten.
     *
     * @return list<string>
     */
    public static function tags(string $text): array
    {
        $tags = [];

        foreach (explode(',', $text) as $tag) {
            $tag = trim((string) preg_replace('/\s+/u', ' ', $tag));

            if ($tag === '') {
                continue;
            }

            if (mb_strlen($tag) > DocumentTag::MAX_LENGTH) {
                throw ValidationException::withMessages(['tags' => __('En tagg får vara högst :max tecken.', ['max' => DocumentTag::MAX_LENGTH])]);
            }

            $tags[mb_strtolower($tag)] ??= $tag;
        }

        if (count($tags) > DocumentTag::MAX_PER_DOCUMENT) {
            throw ValidationException::withMessages(['tags' => __('Ett dokument kan ha högst :max taggar.', ['max' => DocumentTag::MAX_PER_DOCUMENT])]);
        }

        return array_values($tags);
    }
}
