<?php

declare(strict_types=1);

namespace App\Migration\Import\Actions;

use App\Actions\Accounting\ImportSie;
use App\Migration\Import\AttachmentZip;
use App\Migration\Import\OpenItemReconciliation;
use App\Migration\Import\RegisterFileReader;
use App\Migration\Import\RegisterImportEngine;
use App\Migration\Import\RegisterKind;
use App\Migration\Import\RowPlan;
use App\Models\Company;
use App\Models\RegisterImport;
use App\Models\RegisterImportPreset;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Files\PrivateFile;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Step 1, second half (ADR-076): applies the mapping the user chose, reads the
 * whole file through the same engine the import uses and stores the
 * validation report. Changes nothing in the registers.
 */
class AnalyseRegisterImport
{
    public const int PREVIEW_ROWS = 50;

    public const int PROBLEM_ROWS = 300;

    /**
     * @param  array<string, mixed>  $input  mapping, value_maps, reader, fill_empty, excluded, fiscal_year_id, preset_name
     */
    public function __invoke(User $user, RegisterImport $import, array $input): RegisterImport
    {
        Gate::forUser($user)->authorize('view', $import);

        return CurrentCompany::run($import->company_id, function () use ($user, $import, $input): RegisterImport {
            /** @var RegisterImport $import */
            $import = RegisterImport::query()->findOrFail($import->id);
            $company = Company::query()->findOrFail($import->company_id);
            $kind = $import->registerKind();

            if ($import->file_path === null || $import->status === RegisterImport::RUNNING || $import->status === RegisterImport::COMPLETED) {
                throw ValidationException::withMessages(['import' => __('Den här importen går inte att granska igen. Ladda upp filen på nytt om något ska läsas in.')]);
            }

            if ($kind === RegisterKind::Attachments) {
                return $this->analyseZip($user, $company, $import);
            }

            $options = $this->options($import, $input);
            $import->options = $options;
            $engine = new RegisterImportEngine($import);

            try {
                $table = RegisterFileReader::open($engine->path(), (array) $options['reader']);
            } catch (RuntimeException $error) {
                return $this->save($user, $company, $import, ['blockers' => [$error->getMessage()], 'counts' => []], $options);
            }

            $options['detected'] = $table->detected();
            $import->options = $options;
            $engine = new RegisterImportEngine($import);

            $blockers = $this->mappingBlockers($kind, $table->headers, (array) $options['mapping']);

            if ($kind->isOpenItems()) {
                $blockers = [...$blockers, ...OpenItemReconciliation::preconditions($company, $kind)];
            }

            $report = $blockers === [] ? $this->read($engine, $kind, $options) : ['counts' => [], 'rows' => [], 'problems' => []];
            $report['blockers'] = $blockers;

            if ($engine->truncated) {
                $report['blockers'][] = __('Filen har fler än :max rader. Dela upp den i flera filer.', ['max' => number_format(RegisterFileReader::MAX_ROWS, 0, ',', ' ')]);
            }

            if ($blockers === [] && $kind->isOpenItems()) {
                $report['reconciliation'] = OpenItemReconciliation::compare($kind, $report['open_item_ore'], isset($options['fiscal_year_id']) ? (int) $options['fiscal_year_id'] : null, array_map(intval(...), (array) ($options['extra_accounts'] ?? [])));
                $report['notes'] = $this->openItemNotes($kind, $options);

                if (! $report['reconciliation']['equal']) {
                    $report['blockers'][] = $report['reconciliation']['ledger_ore'] === null
                        ? __('Kontot :account finns inte i kontoplanen eller räkenskapsåret saknas.', ['account' => $kind->controlAccount()])
                        : __('Obetalt enligt filen och i NONOX (:file) är inte lika med saldot på :account i bokföringen (:ledger). Skillnaden är :difference. Rätta filen, ange fler konton som hör till samma fordran eller skuld, eller kontrollera att SIE-filen gäller samma datum.', [
                            'file' => self::kronor($report['reconciliation']['expected_ore']),
                            'account' => $kind->controlAccount(),
                            'ledger' => self::kronor((int) $report['reconciliation']['ledger_ore']),
                            'difference' => self::kronor((int) $report['reconciliation']['difference_ore']),
                        ]);
                }
            }

            if ($blockers === [] && ($report['counts']['read'] ?? 0) === 0) {
                $report['blockers'][] = __('Filen har inga rader under rubrikraden.');
            }

            unset($report['open_item_ore']);

            return $this->save($user, $company, $import, $report, $options);
        });
    }

    /** The ZIP of underlag: classifies every file, writes nothing. */
    private function analyseZip(User $user, Company $company, RegisterImport $import): RegisterImport
    {
        $counts = ['read' => 0, AttachmentZip::LINKED => 0, AttachmentZip::UNLINKED => 0, AttachmentZip::DUPLICATE => 0, AttachmentZip::REJECTED => 0];
        $rows = [];

        try {
            $path = PrivateFile::temporaryCopy(ImportSie::DISK, (string) $import->file_path);

            try {
                foreach (AttachmentZip::plan($path) as $entry) {
                    $counts['read']++;
                    $counts[$entry['status']]++;

                    if (count($rows) < self::PROBLEM_ROWS && ($entry['status'] !== AttachmentZip::LINKED || count($rows) < self::PREVIEW_ROWS)) {
                        $rows[] = ['name' => $entry['name'], 'status' => $entry['status'], 'reason' => $entry['reason'], 'target' => $entry['target']];
                    }
                }
            } finally {
                @unlink($path);
            }

            $report = ['counts' => $counts, 'rows' => $rows, 'blockers' => $counts['read'] === 0 ? [__('ZIP-filen innehåller inga filer.')] : []];
        } catch (RuntimeException $error) {
            $report = ['counts' => [], 'rows' => [], 'blockers' => [$error->getMessage()]];
        }

        return $this->save($user, $company, $import, $report, $import->options);
    }

    /**
     * What the user should know before confirming open invoices.
     *
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    private function openItemNotes(RegisterKind $kind, array $options): array
    {
        $notes = [
            __('Avstämningen görs på totalsumman av obetalda fakturor, inte per kund eller leverantör. Två fel som tar ut varandra syns alltså inte.'),
            __('Fakturor i annan valuta än kronor läses inte in. De är felrader och ingår inte i summan.'),
        ];

        if ($kind === RegisterKind::OpenCustomerInvoices && ! isset($options['mapping']['ocr'])) {
            $notes[] = __('Kolumnen med OCR-nummer är inte kopplad. Utan det gamla OCR-numret kan inte kundens betalning matchas mot fakturan automatiskt. Koppla den om filen har den.');
        }

        $default = OpenItemReconciliation::defaultYear();

        if (isset($options['fiscal_year_id']) && $default !== null && (int) $options['fiscal_year_id'] !== $default->id) {
            $notes[] = __('Du har valt ett annat räkenskapsår än det senaste som har bokförda verifikationer. Kontrollera att saldot på :account gäller samma datum som listan.', ['account' => $kind->controlAccount()]);
        }

        return $notes;
    }

    public static function kronor(int $ore): string
    {
        return number_format($ore / 100, 2, ',', ' ').' kr';
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function options(RegisterImport $import, array $input): array
    {
        $options = $import->options;
        $kind = $import->registerKind();
        $fields = array_keys($kind->fields());

        if (array_key_exists('mapping', $input)) {
            $options['mapping'] = array_filter(
                array_intersect_key(array_map(fn (mixed $header): string => trim((string) $header), (array) $input['mapping']), array_flip($fields)),
                fn (string $header): bool => $header !== '',
            );
        }

        if (array_key_exists('value_maps', $input)) {
            $maps = [];

            foreach ((array) $input['value_maps'] as $field => $codes) {
                foreach ((array) $codes as $code => $value) {
                    if (in_array($field, $fields, true) && trim((string) $value) !== '') {
                        $maps[$field][mb_strtolower((string) $code)] = trim((string) $value);
                    }
                }
            }

            $options['value_maps'] = array_replace_recursive((array) ($options['value_maps'] ?? []), $maps);
        }

        foreach (['encoding', 'delimiter', 'sheet'] as $key) {
            if (isset($input['reader'][$key])) {
                $value = trim((string) $input['reader'][$key]);
                $options['reader'][$key] = $value === '' || $value === 'auto' ? null : ($value === 'tab' ? "\t" : $value);
            }
        }

        if (array_key_exists('fill_empty', $input)) {
            $options['fill_empty'] = (bool) $input['fill_empty'];
        }

        if (array_key_exists('excluded', $input)) {
            $options['excluded'] = array_values(array_unique(array_map(intval(...), (array) $input['excluded'])));
        }

        $options['preset_name'] = trim((string) ($input['preset_name'] ?? ''));

        if (array_key_exists('extra_accounts', $input)) {
            $control = (int) $kind->controlAccount();
            $options['extra_accounts'] = array_values(array_unique(array_filter(
                array_map(intval(...), preg_split('/[\s,;]+/', (string) $input['extra_accounts']) ?: []),
                fn (int $number): bool => $number >= 1000 && $number <= 8999 && $number !== $control,
            )));
        }

        if (array_key_exists('match_nonox_number', $input)) {
            $options['match_nonox_number'] = (bool) $input['match_nonox_number'];
        }

        if (isset($input['fiscal_year_id'])) {
            $options['fiscal_year_id'] = (int) $input['fiscal_year_id'] ?: null;
        }

        return $options;
    }

    /**
     * @param  list<string>  $headers
     * @param  array<string, string>  $mapping
     * @return list<string>
     */
    private function mappingBlockers(RegisterKind $kind, array $headers, array $mapping): array
    {
        $blockers = [];

        if ($headers === []) {
            return [__('Filen har ingen rubrikrad som går att läsa. Första raden ska innehålla kolumnernas namn.')];
        }

        foreach ($kind->requiredFields() as $field) {
            if (! isset($mapping[$field])) {
                $blockers[] = __('Välj vilken kolumn som är ":field".', ['field' => $kind->fields()[$field]['label']]);
            }
        }

        foreach ($mapping as $field => $header) {
            if (! in_array($header, $headers, true)) {
                $blockers[] = __('Kolumnen ":header" finns inte i filen.', ['header' => mb_substr($header, 0, 40)]);
            }
        }

        foreach (array_count_values($mapping) as $header => $times) {
            if ($times > 1) {
                $blockers[] = __('Kolumnen ":header" används för mer än ett fält.', ['header' => mb_substr((string) $header, 0, 40)]);
            }
        }

        return $blockers;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function read(RegisterImportEngine $engine, RegisterKind $kind, array $options): array
    {
        $counts = ['read' => 0, RowPlan::CREATE => 0, RowPlan::FILL => 0, RowPlan::EXISTS => 0, RowPlan::DUPLICATE => 0, RowPlan::EXCLUDED => 0, RowPlan::ERROR => 0, 'warnings' => 0];
        $preview = [];
        $problems = [];
        $unmapped = [];
        $emails = [];
        $openOre = 0;

        foreach ($engine->plan() as $plan) {
            $counts['read']++;
            $counts[$plan->status]++;

            $warnings = $plan->warnings;
            $email = mb_strtolower((string) ($plan->values['email'] ?? ''));

            if ($email !== '' && $plan->errors === []) {
                if (isset($emails[$email])) {
                    $warnings[] = __('Samma e-postadress finns på rad :line.', ['line' => $emails[$email]]);
                }

                $emails[$email] ??= $plan->line;
            }

            if ($warnings !== []) {
                $counts['warnings']++;
            }

            foreach ($plan->unmapped as $field => $code) {
                if (count($unmapped[$field] ?? []) < 50) {
                    $unmapped[$field][mb_strtolower($code)] = $code;
                }
            }

            if ($kind->isOpenItems() && $plan->countsAsOpenItem()) {
                $openOre += (int) $plan->values['remaining'];
            }

            $row = $this->summary($plan, $warnings);

            if (count($preview) < self::PREVIEW_ROWS) {
                $preview[] = $row;
            } elseif (($plan->errors !== [] || $warnings !== [] || $plan->status === RowPlan::DUPLICATE) && count($problems) < self::PROBLEM_ROWS) {
                $problems[] = $row;
            }
        }

        // A problem in the first rows is already in the preview; the list shows the rest.
        return [
            'counts' => $counts,
            'rows' => $preview,
            'problems' => $problems,
            'unmapped' => $unmapped,
            'open_item_ore' => $openOre,
        ];
    }

    /**
     * What the preview shows of a row. Personal numbers are masked to their
     * last four digits; no more of the row is kept in the report than this.
     *
     * @param  list<string>  $warnings
     * @return array<string, mixed>
     */
    private function summary(RowPlan $plan, array $warnings): array
    {
        $values = $plan->values;
        $digits = fn (string $value): string => (string) preg_replace('/\D/', '', $value);

        return [
            'line' => $plan->line,
            'status' => $plan->status,
            'name' => mb_substr((string) ($values['name'] ?? $values['invoice_number'] ?? ''), 0, 80),
            'ref' => mb_substr((string) ($values['external_ref'] ?? $values['party_ref'] ?? $values['number'] ?? ''), 0, 40),
            'number' => isset($values['org_number']) ? (string) $values['org_number'] : (isset($values['personal_number']) ? str_repeat('•', 6).substr($digits((string) $values['personal_number']), -4) : ''),
            'city' => mb_substr((string) ($values['city'] ?? ''), 0, 40),
            'amount' => isset($values['remaining']) ? self::kronor((int) $values['remaining']) : null,
            'errors' => $plan->errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  array<string, mixed>  $options
     */
    private function save(User $user, Company $company, RegisterImport $import, array $report, array $options): RegisterImport
    {
        return DB::transaction(function () use ($user, $company, $import, $report, $options): RegisterImport {
            $name = trim((string) ($options['preset_name'] ?? ''));
            unset($options['preset_name']);
            $import->forceFill(['options' => $options, 'report' => $report, 'status' => RegisterImport::ANALYSED, 'error' => null])->save();

            if ($name !== '' && ($options['mapping'] ?? []) !== []) {
                RegisterImportPreset::query()->updateOrCreate(
                    ['kind' => $import->kind, 'name' => mb_substr($name, 0, 80)],
                    ['mapping' => $options['mapping']],
                );
            }

            Audit::record('register.analysed', $import, [
                'kind' => $import->kind,
                'sha256' => $import->sha256,
                'counts' => $report['counts'] ?? [],
                'blockers' => count($report['blockers'] ?? []),
            ], $company, $user);

            return $import;
        });
    }
}
