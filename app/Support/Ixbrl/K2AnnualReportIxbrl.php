<?php

declare(strict_types=1);

namespace App\Support\Ixbrl;

use App\Domain\AnnualReport\AnnualReportDocument;
use App\Domain\AnnualReport\K2Taxonomy;
use App\Domain\Company\LegalForm;
use App\Models\AnnualReport;
use App\Models\Company;
use App\Models\FiscalYear;
use DOMDocument;
use LibXMLError;

/**
 * The K2 annual report of an aktiebolag as an Inline XBRL document (XHTML
 * with the facts tagged), for digital filing with Bolagsverket (ADR-058,
 * stage E.2). It renders the same array as the PDF,
 * AnnualReportDocument::data(), and never reads the ledger itself, so the
 * two cannot show different figures (both print PrintedFigures).
 *
 * The file is a download (Beta). NONOX does not file it: that needs
 * Bolagsverket's agreement and certificate (stages E.3–E.5), and the
 * fastställelseintyg is signed in Bolagsverket's own service.
 *
 * TODO(provider-verify): Bolagsverket's tillämpningsanvisning 1.8 (not
 * read: captcha): required tagging, allowed XHTML, size limit, the entity
 * identifier scheme, context ids and the period of the certificate facts.
 */
final class K2AnnualReportIxbrl
{
    /**
     * @throws IxbrlRefused when the report prints something the taxonomy map cannot tag
     */
    public static function render(Company $company, FiscalYear $fiscalYear, AnnualReport $report): string
    {
        if ($company->legal_form !== LegalForm::Ab) {
            throw new IxbrlRefused([__('Företagsformen')], __('iXBRL-filen görs bara för aktiebolag än så länge.'));
        }

        // The file is tagged with the K2 taxonomy and says BFNAR 2016:10 in note 1; the year's framework decides (ADR-059, A.2.1).
        $refusal = AnnualReportDocument::refusal($company, $fiscalYear);

        if ($refusal !== null) {
            throw new IxbrlRefused([__('Regelverket')], $refusal);
        }

        $data = AnnualReportDocument::data($company, $fiscalYear, $report);
        $current = new Period($fiscalYear->start_date->toDateString(), $fiscalYear->end_date->toDateString());
        $previousYear = $data['previousYear'];
        $previous = $previousYear instanceof FiscalYear
            ? new Period($previousYear->start_date->toDateString(), $previousYear->end_date->toDateString())
            : new Period(null, $fiscalYear->start_date->subDay()->toDateString());
        $years = [];

        foreach ($data['multiYear'] as $row) {
            $years[$row['label']] = new Period($row['start'], $row['end']);
        }

        $writer = new InlineXbrlWriter(K2Taxonomy::ENTITY_SCHEME, (string) preg_replace('/\D/', '', (string) $company->org_number), K2Taxonomy::CONCEPTS);
        // Every text a person typed passes InlineXbrlWriter::xmlText in the view; the whole body once more, for the texts printed untagged.
        $body = InlineXbrlWriter::xmlText(view('annual-report.ixbrl-body', [...$data, 'x' => $writer, 'current' => $current, 'previous' => $previous, 'years' => $years])->render());

        $missing = $writer->missing();

        if (! filled($report->business_description)) {
            array_unshift($missing, __('Allmänt om verksamheten (texten saknas)'));
        }

        if ($missing !== []) {
            throw new IxbrlRefused($missing, __('iXBRL-filen kan inte skapas. Det här i årsredovisningen saknas eller har ingen motsvarighet i taxonomin för K2: :items.', ['items' => implode('; ', $missing)]));
        }

        self::hiddenFacts($writer, $data, $current, $previous);

        $xhtml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".view('annual-report.ixbrl', [
            'company' => $company,
            'fiscalYear' => $fiscalYear,
            'header' => $writer->header([K2Taxonomy::ENTRY_POINT, K2Taxonomy::CERTIFICATE_ENTRY_POINT]),
            'body' => $body,
        ])->render();

        self::assertWellFormed($xhtml);

        $directory = config('services.bolagsverket.taxonomy_dir');

        if (is_string($directory) && $directory !== '') {
            $problems = TaxonomyConcepts::fromDirectory($directory)->problems($writer->usedConcepts(), K2Taxonomy::MEMBERS, [K2Taxonomy::ENTRY_POINT, K2Taxonomy::CERTIFICATE_ENTRY_POINT]);

            if ($problems !== []) {
                throw new IxbrlRefused($problems, __('iXBRL-filen stämmer inte med taxonomin som är installerad: :items.', ['items' => implode('; ', $problems)]));
            }
        }

        return $xhtml;
    }

    /**
     * @throws IxbrlRefused when the document is not well-formed XML
     */
    public static function assertWellFormed(string $xhtml): void
    {
        $previous = libxml_use_internal_errors(true);
        $loaded = (new DOMDocument)->loadXML($xhtml, LIBXML_NONET);
        $errors = array_map(fn (LibXMLError $error): string => trim($error->message), libxml_get_errors());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded || $errors !== []) {
            throw new IxbrlRefused($errors, __('iXBRL-filen blev inte giltig XML och lämnas inte ut: :items.', ['items' => implode('; ', $errors)]));
        }
    }

    public static function filename(Company $company, FiscalYear $fiscalYear): string
    {
        return 'Arsredovisning-'.preg_replace('/\D/', '', (string) $company->org_number).'-'.$fiscalYear->end_date->format('Y').'.xhtml';
    }

    /**
     * The value lists and the subtotals of the taxonomy's calculation
     * linkbase that the report does not print, as hidden facts. A subtotal
     * is the sum of its printed lines as tagged, so it adds up exactly.
     *
     * @param  array<string, mixed>  $data
     */
    private static function hiddenFacts(InlineXbrlWriter $writer, array $data, Period $current, Period $previous): void
    {
        foreach (K2Taxonomy::ENUMERATIONS as $concept => $member) {
            $writer->hiddenEnumeration($concept, $member, $current);
        }

        /** @var list<array{key: string, amount: int, previous: ?int}> $income */
        $income = $data['income'];
        $columns = ['amount' => $current];

        if ($data['hasPreviousIncome'] === true) {
            $columns['previous'] = $previous;
        }

        foreach (K2Taxonomy::INCOME_HIDDEN_SUBTOTALS as $concept => $weights) {
            foreach ($columns as $field => $period) {
                $sum = null;

                foreach ($income as $line) {
                    if (isset($weights[$line['key']])) {
                        $lineConcept = (string) K2Taxonomy::INCOME[$line['key']];
                        $sum = ($sum ?? 0) + $weights[$line['key']] * self::factValue(intdiv((int) $line[$field], 100), $lineConcept, 'credit');
                    }
                }

                if ($sum !== null) {
                    $writer->hiddenMoney($concept, $sum, $period);
                }
            }
        }

        /** @var array{sections: list<array{lines: list<array{key: string, amount: int, previous: int}>}>} $balance */
        $balance = $data['balance'];
        $lines = collect($balance['sections'])->flatMap(fn (array $section): array => $section['lines'])->keyBy('key');

        foreach (K2Taxonomy::BALANCE_HIDDEN_SUBTOTALS as $concept => $keys) {
            $present = $lines->only($keys);

            if ($present->isEmpty()) {
                continue;
            }

            foreach (['amount' => $current, 'previous' => $previous] as $field => $period) {
                $writer->hiddenMoney($concept, (int) $present->sum(fn (array $line): int => intdiv($line[$field], 100)), $period);
            }
        }
    }

    /** The fact value of an amount printed in the report's direction. */
    private static function factValue(int $kronor, string $concept, string $orientation): int
    {
        [, $balance] = K2Taxonomy::concept($concept);

        return $balance === null || $balance === $orientation ? $kronor : -$kronor;
    }
}
