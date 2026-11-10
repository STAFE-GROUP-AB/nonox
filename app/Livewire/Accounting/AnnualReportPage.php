<?php

declare(strict_types=1);

namespace App\Livewire\Accounting;

use App\Actions\AnnualReport\K3NoteOverrides;
use App\Actions\AnnualReport\SaveAnnualReport;
use App\Actions\AnnualReport\SaveK3ReportInputs;
use App\Domain\AnnualReport\AnnualAccounts;
use App\Domain\AnnualReport\AnnualReportDocument;
use App\Domain\AnnualReport\K3AnnualReportDocument;
use App\Domain\AnnualReport\PledgedAssetKind;
use App\Domain\Closing\DividendLimit;
use App\Domain\Company\LegalForm;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\AnnualReport;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Support\Money\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Bokföring → Årsredovisning: the texts of the K2 annual report, a preview
 * built from the books, and the PDF. A K3 year (ADR-059, S1-P10, Beta) gets
 * the K3 document: what refuses it with the rule ids, the open questions,
 * the K3 inputs, the note list with a person's own wording, the preview and
 * the PDF once complete. K1 gets an explanation instead.
 */
class AnnualReportPage extends Component
{
    use ShowsToasts;

    #[Url(as: 'ar', except: 0)]
    public int $year = 0;

    /** @var array<string, mixed> */
    public array $form = [];

    public int $version = 0;

    /** @var array<string, mixed> the K3 inputs (SaveK3ReportInputs) */
    public array $k3Form = [];

    /** @var list<array{name: string, owner_type: string, org_number: string, votes: string, capital: string}> */
    public array $owners = [];

    public bool $ownersAnswered = false;

    /** @var list<array{description: string, amount: string}> */
    public array $exceptional = [];

    public ?string $editingNote = null;

    public string $noteText = '';

    public string $noteReason = '';

    public function mount(): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), 'fiscal-years:manage'), 403);

        if (FiscalYear::query()->whereKey($this->year)->doesntExist()) {
            // The latest year that has ended; otherwise the current one.
            $this->year = (int) (FiscalYear::query()->whereDate('end_date', '<', now()->toDateString())->orderByDesc('start_date')->value('id')
                ?? FiscalYear::query()->orderByDesc('start_date')->value('id'));
        }

        $this->loadForm();
    }

    public function updatedYear(): void
    {
        $this->loadForm();
    }

    public function saveK3(SaveK3ReportInputs $save): void
    {
        $answers = [];

        foreach (SaveK3ReportInputs::ANSWERS as $key) {
            $value = $this->k3Form['answers'][$key] ?? '';
            $answers[$key] = $value === 'yes' ? true : ($value === 'no' ? false : null);
        }

        $input = array_intersect_key($this->k3Form, array_flip([...SaveK3ReportInputs::TEXTS, 'average_employees_fte', 'k3_long_term_liabilities']));
        $input['k3_answers'] = $answers;
        $input['k3_major_owners'] = $this->ownersAnswered ? array_map(fn (array $owner): array => [
            'name' => $owner['name'],
            'owner_type' => $owner['owner_type'],
            'org_number' => $owner['owner_type'] === 'legal' ? $owner['org_number'] : '',
            'votes_bp' => self::basisPoints($owner['votes']),
            'capital_bp' => self::basisPoints($owner['capital']),
        ], $this->owners) : null;
        $input['k3_exceptional_items'] = array_values(array_filter($this->exceptional, fn (array $item): bool => trim($item['description']) !== '' || trim($item['amount']) !== ''));

        $save($this->user(), FiscalYear::query()->findOrFail($this->year), $input);
        $this->version++;
        $this->toast(__('Uppgifterna för K3 är sparade.'));
    }

    public function addOwner(): void
    {
        $this->ownersAnswered = true;
        $this->owners[] = ['name' => '', 'owner_type' => 'legal', 'org_number' => '', 'votes' => '', 'capital' => ''];
    }

    public function removeOwner(int $index): void
    {
        $this->owners = array_values(array_filter($this->owners, fn (array $owner, int $key): bool => $key !== $index, ARRAY_FILTER_USE_BOTH));
    }

    public function addExceptional(): void
    {
        $this->exceptional[] = ['description' => '', 'amount' => ''];
    }

    public function removeExceptional(int $index): void
    {
        $this->exceptional = array_values(array_filter($this->exceptional, fn (array $item, int $key): bool => $key !== $index, ARRAY_FILTER_USE_BOTH));
    }

    public function editNote(string $key): void
    {
        $note = $this->k3Document()?->notes()?->note($key);
        abort_if($note === null, 404);

        $this->editingNote = $key;
        $this->noteText = implode("\n\n", $note->text());
        $this->noteReason = (string) ($note->overrideReason ?? '');
        $this->resetErrorBag();
    }

    public function cancelNote(): void
    {
        $this->editingNote = null;
        $this->noteText = '';
        $this->noteReason = '';
    }

    public function saveNote(K3NoteOverrides $overrides): void
    {
        if ($this->editingNote === null) {
            return;
        }

        $overrides->set($this->user(), FiscalYear::query()->findOrFail($this->year), $this->editingNote, $this->noteText, $this->noteReason);
        $this->cancelNote();
        $this->version++;
        $this->toast(__('Noten har fått din egen formulering.'));
    }

    public function removeNoteOverride(K3NoteOverrides $overrides, string $key): void
    {
        $overrides->remove($this->user(), FiscalYear::query()->findOrFail($this->year), $key);
        $this->version++;
        $this->toast(__('Noten använder åter den text som NONOX tar fram.'));
    }

    public function save(SaveAnnualReport $save): void
    {
        $save($this->user(), FiscalYear::query()->findOrFail($this->year), $this->form);
        $this->version++;
        $this->toast(__('Årsredovisningen är sparad.'));
    }

    public function render(): View
    {
        $fiscalYear = FiscalYear::query()->findOrFail($this->year);
        $k3 = K3AnnualReportDocument::supports($this->company(), $fiscalYear);
        $supported = $k3 || AnnualReportDocument::supports($this->company(), $fiscalYear);
        $document = $k3 ? $this->k3Document($fiscalYear) : null;

        return view('livewire.accounting.annual-report', [
            'years' => FiscalYear::query()->orderByDesc('start_date')->get(),
            'fiscalYear' => $fiscalYear,
            'supported' => $supported,
            'refusal' => AnnualReportDocument::refusal($this->company(), $fiscalYear),
            'legalFormSupported' => AnnualReportDocument::supportsLegalForm($this->company()),
            'k3' => $k3,
            'k3Refusals' => $document?->refusals() ?? [],
            'k3Questions' => $document?->questions() ?? [],
            'k3Notes' => $document?->notes()?->notes() ?? [],
            'k3Complete' => $document?->complete() ?? false,
            'k3Answers' => SaveK3ReportInputs::ANSWERS,
            'k3Texts' => SaveK3ReportInputs::TEXTS,
            'ixbrl' => ! $k3 && $this->company()->legal_form === LegalForm::Ab,
            'freeEquity' => $supported ? DividendLimit::freeEquityOre($fiscalYear) : 0,
            'hasBankLoans' => $supported && self::hasBankLoans($fiscalYear),
            'netSalesVariations' => $supported && ! $k3 ? AnnualAccounts::for($fiscalYear)->netSalesVariations() : [],
            'pledgedKinds' => PledgedAssetKind::cases(),
        ]);
    }

    /**
     * Loans from credit institutions at the end of the year (BAS 2330–2359,
     * 2410–2419, 2480–2489), where a företagsinteckning is likely; the page
     * then reminds about the note on ställda säkerheter (#92).
     */
    private static function hasBankLoans(FiscalYear $fiscalYear): bool
    {
        $accounts = AnnualAccounts::for($fiscalYear);

        foreach ([[2330, 2359], [2410, 2419], [2480, 2489]] as [$from, $to]) {
            if ($accounts->closingOf($from, $to) !== 0) {
                return true;
            }
        }

        return false;
    }

    private function loadForm(): void
    {
        $report = AnnualReport::query()->where('fiscal_year_id', $this->year)->first();
        // Jetstream keeps the company's owner on the company itself, other owners as members.
        $owners = collect([$this->company()->owner()->value('name')])
            ->merge($this->company()->users()->wherePivot('role', 'owner')->pluck('name'))
            ->filter()->unique()->map(fn (mixed $name): string => "{$name}, ".__('Styrelseledamot'))->implode("\n");

        $this->form = [
            'business_description' => (string) ($report->business_description ?? ''),
            'significant_events' => (string) ($report->significant_events ?? ''),
            'dividend' => $report === null || $report->dividend_ore->ore === 0 ? '' : $report->dividend_ore->format(withCurrency: false),
            'average_employees' => (string) ($report->average_employees ?? 0),
            'signing_place' => (string) ($report->signing_place ?? $this->company()->city ?? ''),
            'signing_date' => (string) ($report?->signing_date?->toDateString() ?? ''),
            'signatories' => $report === null ? $owners : collect($report->signatories)->map(fn (array $person): string => trim("{$person['name']}, {$person['role']}", ', '))->implode("\n"),
            'adoption_date' => (string) ($report?->adoption_date?->toDateString() ?? ''),
            'adoption_decision' => (string) ($report->adoption_decision ?? ''),
            'certifier_name' => (string) ($report->certifier_name ?? ''),
            'certifier_role' => (string) ($report->certifier_role ?? ''),
            'pledged' => collect(PledgedAssetKind::cases())->mapWithKeys(fn (PledgedAssetKind $kind): array => [
                $kind->value => isset($report->pledged_assets[$kind->value]) ? Money::ofOre((int) $report->pledged_assets[$kind->value])->format(withCurrency: false) : '',
            ])->all(),
            'pledged_other_text' => (string) ($report->pledged_other_text ?? ''),
            'contingent_liabilities' => $report?->contingent_liabilities_ore === null ? '' : Money::ofOre($report->contingent_liabilities_ore)->format(withCurrency: false),
            'contingent_liabilities_text' => (string) ($report->contingent_liabilities_text ?? ''),
            'group_commitments' => (string) ($report->group_commitments ?? ''),
            'events_after' => (string) ($report->events_after ?? ''),
            'events_effect_unknown' => (bool) ($report->events_effect_unknown ?? false),
            'net_sales_comment' => (string) ($report->net_sales_comment ?? ''),
        ];
        $this->loadK3Form($report);
        $this->resetErrorBag();
    }

    private function loadK3Form(?AnnualReport $report): void
    {
        $answers = [];

        foreach (SaveK3ReportInputs::ANSWERS as $key) {
            $answer = $report->k3_answers[$key] ?? null;
            $answers[$key] = $answer === true ? 'yes' : ($answer === false ? 'no' : '');
        }

        $this->k3Form = [
            ...array_map(fn (string $field): string => (string) ($report?->getAttribute($field) ?? ''), array_combine(SaveK3ReportInputs::TEXTS, SaveK3ReportInputs::TEXTS)),
            'average_employees_fte' => (string) ($report->average_employees_fte ?? ''),
            'k3_long_term_liabilities' => $report?->k3_long_term_liabilities_ore === null ? '' : Money::ofOre((int) $report->k3_long_term_liabilities_ore)->format(withCurrency: false),
            'answers' => $answers,
        ];
        $this->ownersAnswered = $report?->k3_major_owners !== null;
        $this->owners = array_map(fn (array $owner): array => [
            'name' => (string) $owner['name'],
            'owner_type' => (string) ($owner['owner_type'] ?? 'legal'),
            'org_number' => (string) ($owner['org_number'] ?? ''),
            'votes' => self::percent((int) $owner['votes_bp']),
            'capital' => self::percent((int) $owner['capital_bp']),
        ], $report->k3_major_owners ?? []);
        $this->exceptional = array_map(fn (array $item): array => [
            'description' => (string) $item['description'],
            'amount' => Money::ofOre((int) $item['amount_ore'])->format(withCurrency: false),
        ], $report->k3_exceptional_items ?? []);
        $this->cancelNote();
    }

    /** The K3 document of the year, or null when the year is not a K3 year. */
    private function k3Document(?FiscalYear $fiscalYear = null): ?K3AnnualReportDocument
    {
        $fiscalYear ??= FiscalYear::query()->findOrFail($this->year);

        if (! K3AnnualReportDocument::supports($this->company(), $fiscalYear)) {
            return null;
        }

        return K3AnnualReportDocument::for($this->company(), $fiscalYear, K3AnnualReportDocument::report($fiscalYear));
    }

    /** "25,5" (per cent) as 2550 basis points; anything unreadable as -1, which SaveK3ReportInputs refuses. */
    private static function basisPoints(string $percent): int
    {
        $value = str_replace([' ', ','], ['', '.'], trim(rtrim(trim($percent), '%')));

        return is_numeric($value) ? (int) round((float) $value * 100) : -1;
    }

    private static function percent(int $basisPoints): string
    {
        return rtrim(rtrim(number_format($basisPoints / 100, 2, ',', ''), '0'), ',');
    }

    private function company(): Company
    {
        /** @var Company $company */
        $company = $this->user()->currentCompany();

        return $company;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
