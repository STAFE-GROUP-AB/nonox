<?php

declare(strict_types=1);

namespace App\Modules\Agreements\Actions;

use App\Actions\Accounting\AttachToVoucher;
use App\Models\Company;
use App\Models\User;
use App\Modules\Agreements\Agents\AgreementIdentificationAgent;
use App\Modules\Agreements\Agents\EmploymentTermsAgent;
use App\Modules\Agreements\AgreementsModule;
use App\Modules\Agreements\Models\AgreementDocument;
use App\Modules\Agreements\Models\AgreementProposal;
use App\Modules\Agreements\Models\AgreementRuleVersion;
use App\Modules\Agreements\Support\AgreementVersions;
use App\Modules\Agreements\Support\DocumentText;
use App\Modules\Agreements\Support\PersonalDataMasker;
use App\Modules\Ai\Actions\AnalyzeDocument;
use App\Modules\Ai\AiModule;
use App\Modules\Ai\Support\AiAnalysisException;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\CollectiveAgreement;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\Employment;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * NONOX AI fills in the agreement setup (ADR-033 §6). The company uploads
 * documents; AgreementIdentificationAgent proposes which agreement and
 * variant the company is bound by, and EmploymentTermsAgent proposes each
 * employment's agreement facts. Only text is sent, with personal numbers,
 * bank details, addresses, e-mail addresses and phone numbers masked first.
 * Every proposal keeps its quote and page and whether the quote was found
 * in the document. Nothing is applied until a person with
 * agreements:manage accepts it; agreement values never come from the AI.
 * The audit log records each analysis and decision without any content.
 *
 * Available only while both NONOX AI and Avtal are on and the user has
 * ai:use and agreements:manage.
 */
class AgreementAi
{
    public const string DISK = AttachToVoucher::DISK;

    public const int MAX_BYTES = 5 * 1024 * 1024;

    /** Proposals that only compare with what is registered under Lön → Anställda; accepting them changes nothing. */
    public const array INFORMATION_FIELDS = ['salary_form', 'monthly_salary', 'hourly_rate', 'start_date', 'deviation'];

    public const array EMPLOYMENT_TYPES = ['TILLSVIDARE', 'ALLMAN_VISSTID', 'VIKARIAT', 'SASONG', 'PROVANSTALLNING', 'TIMANSTALLD'];

    public function __construct(
        private readonly ModuleManager $modules,
        private readonly AnalyzeDocument $analyze,
        private readonly AgreementSetup $setup,
    ) {}

    public static function availableFor(User $user, Company $company): bool
    {
        $modules = app(ModuleManager::class);

        return $modules->isEnabled(AiModule::KEY, $company)
            && $modules->isEnabled(AgreementsModule::KEY, $company)
            && $user->hasTeamPermission($company, 'ai:use')
            && $user->hasTeamPermission($company, 'agreements:manage');
    }

    public function upload(User $user, Company $company, ?Employee $employee, string $type, string $originalName, string $mime, string $contents): AgreementDocument
    {
        $this->authorize($user, $company);
        $types = $employee === null ? AgreementDocument::COMPANY_TYPES : AgreementDocument::EMPLOYEE_TYPES;

        if (! in_array($type, $types, true)) {
            throw ValidationException::withMessages(['document_type' => [__('Välj vad det är för dokument.')]]);
        }

        if (! in_array($mime, DocumentText::MIMES, true) || $contents === '' || strlen($contents) > self::MAX_BYTES) {
            throw ValidationException::withMessages(['document' => [__('Ladda upp en PDF med text eller en textfil, högst 5 MB.')]]);
        }

        if (DocumentText::pages($contents, $mime) === null) {
            throw ValidationException::withMessages(['document' => [__('Dokumentet har ingen läsbar text, till exempel en skannad sida. Fyll i uppgifterna själv under Lön → Avtal.')]]);
        }

        return CurrentCompany::run($company, function () use ($user, $company, $employee, $type, $originalName, $mime, $contents): AgreementDocument {
            if ($employee !== null) {
                $employee = Employee::query()->findOrFail($employee->id);
            }

            $sha = hash('sha256', $contents);

            if (AgreementDocument::query()->where('employee_id', $employee?->id)->where('sha256', $sha)->exists()) {
                throw ValidationException::withMessages(['document' => [__('Dokumentet är redan uppladdat.')]]);
            }

            $path = "agreement-documents/{$company->id}/{$sha}.".($mime === 'application/pdf' ? 'pdf' : 'txt');
            // The private disk is encrypted at rest; the document holds personal data.
            Storage::disk(self::DISK)->put($path, $contents);

            $document = AgreementDocument::create([
                'employee_id' => $employee?->id,
                'document_type' => $type,
                'original_name' => mb_substr(basename($originalName), 0, 255),
                'file_path' => $path,
                'mime' => $mime,
                'size' => strlen($contents),
                'sha256' => $sha,
                'uploaded_by' => $user->id,
            ]);

            Audit::record('agreements.document_uploaded', $document, ['document_type' => $type, 'employee_id' => $employee?->id, 'sha256' => $sha], $company, $user);

            return $document;
        });
    }

    public function delete(User $user, Company $company, AgreementDocument $document): void
    {
        $this->authorize($user, $company);

        CurrentCompany::run($company, function () use ($user, $company, $document): void {
            $document = AgreementDocument::query()->findOrFail($document->id);

            DB::transaction(function () use ($document): void {
                AgreementProposal::query()->where('agreement_document_id', $document->id)->delete();
                $document->delete();
            });

            if (! AgreementDocument::query()->where('file_path', $document->file_path)->exists()) {
                Storage::disk(self::DISK)->delete($document->file_path);
            }

            Audit::record('agreements.document_deleted', null, ['document_id' => $document->id, 'employee_id' => $document->employee_id], $company, $user);
        });
    }

    /**
     * Which agreement the company is bound by, from one of its documents.
     *
     * @return int proposals made
     *
     * @throws AiAnalysisException
     */
    public function identify(User $user, Company $company, AgreementDocument $document): int
    {
        $this->authorize($user, $company);

        return CurrentCompany::run($company, function () use ($user, $company, $document): int {
            $document = AgreementDocument::query()->findOrFail($document->id);

            if ($document->employee_id !== null) {
                throw ValidationException::withMessages(['document' => [__('Det här är en anställds dokument. Analysera det under Analysera all personal.')]]);
            }

            [$text, $masked] = $this->text($document);
            $answer = $this->analyze->text($user, $company, new AgreementIdentificationAgent, 'agreement_identification', $this->catalogContext($company), $text);
            $keys = AgreementVersions::agreements()->keys()->all();
            $proposals = [];

            foreach ((array) ($answer['agreements'] ?? []) as $found) {
                $found = (array) $found;
                $key = (string) ($found['agreement_key'] ?? '');

                if (! in_array($key, $keys, true)) {
                    continue;
                }

                $proposals[] = [
                    'field' => 'binding',
                    'value' => [
                        'agreement_key' => $key,
                        'variant' => in_array($found['variant'] ?? null, AgreementRuleVersion::VARIANTS, true) ? $found['variant'] : null,
                        'bound_from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($found['bound_from'] ?? '')) === 1 ? (string) $found['bound_from'] : null,
                    ],
                    'quote' => (string) ($found['quote'] ?? ''),
                    'page' => (int) ($found['page'] ?? 0),
                ];
            }

            return $this->store($user, $company, $document, AgreementProposal::IDENTIFICATION, null, null, $proposals, $text, $masked);
        });
    }

    /**
     * One employee's agreement facts, from their employment contract or a
     * payslip.
     *
     * @return int proposals made
     *
     * @throws AiAnalysisException
     * @throws ValidationException when the document has no readable text or belongs to no employee
     */
    public function analyzeEmployee(User $user, Company $company, AgreementDocument $document): int
    {
        $this->authorize($user, $company);

        return CurrentCompany::run($company, function () use ($user, $company, $document): int {
            $document = AgreementDocument::query()->with('employee')->findOrFail($document->id);
            $employee = $document->employee ?? throw ValidationException::withMessages(['document' => [__('Dokumentet hör inte till någon anställd.')]]);
            $employment = Employment::query()->where('employee_id', $employee->id)->orderByDesc('start_date')->orderByDesc('id')->first();

            [$text, $masked] = $this->text($document);
            $answer = $this->analyze->text($user, $company, new EmploymentTermsAgent, 'employment_terms', $this->bindingsContext(), $text);
            $proposals = [];

            foreach ((array) ($answer['facts'] ?? []) as $fact) {
                $fact = (array) $fact;
                $field = (string) ($fact['field'] ?? '');
                $value = trim((string) ($fact['value'] ?? ''));

                if (! in_array($field, EmploymentTermsAgent::FIELDS, true) || $value === '') {
                    continue;
                }

                $proposals[] = ['field' => $field, 'value' => mb_substr($value, 0, 500), 'quote' => (string) ($fact['quote'] ?? ''), 'page' => (int) ($fact['page'] ?? 0)];
            }

            return $this->store($user, $company, $document, AgreementProposal::EMPLOYMENT_TERMS, $employee->id, $employment?->id, $proposals, $text, $masked);
        });
    }

    /**
     * "Analysera all personal": every employee's newest document that has
     * not been analysed. An employee whose analysis fails is reported and
     * the others go on.
     *
     * @return array{analysed: int, proposals: int, failed: list<string>}
     */
    public function analyzeAll(User $user, Company $company): array
    {
        $this->authorize($user, $company);

        /** @var Collection<int, AgreementDocument> $documents */
        $documents = CurrentCompany::run($company, fn (): Collection => AgreementDocument::query()
            ->with('employee')
            ->whereNotNull('employee_id')
            ->whereNull('analyzed_at')
            ->orderByDesc('id')
            ->get()
            ->unique('employee_id')
            ->values());
        $result = ['analysed' => 0, 'proposals' => 0, 'failed' => []];

        foreach ($documents as $document) {
            try {
                $result['proposals'] += $this->analyzeEmployee($user, $company, $document);
                $result['analysed']++;
            } catch (AiAnalysisException|ValidationException $exception) {
                $result['failed'][] = trim("{$document->employee?->first_name} {$document->employee?->last_name}").': '.$exception->getMessage();
            }
        }

        return $result;
    }

    /**
     * Accepts a proposal as read, or with the person's own value, and
     * records the fact. Proposals that only compare (salary, start date,
     * deviations) change nothing when accepted.
     */
    public function accept(User $user, Company $company, AgreementProposal $proposal, mixed $value = null): AgreementProposal
    {
        $this->authorize($user, $company);

        return CurrentCompany::run($company, fn (): AgreementProposal => DB::transaction(function () use ($user, $company, $proposal, $value): AgreementProposal {
            $proposal = AgreementProposal::query()->lockForUpdate()->findOrFail($proposal->id);

            if (! $proposal->isPending()) {
                throw ValidationException::withMessages(['proposal' => [__('Förslaget är redan hanterat.')]]);
            }

            $decided = $value ?? $proposal->value;
            $this->apply($user, $company, $proposal, $decided);
            $proposal->forceFill(['status' => AgreementProposal::ACCEPTED, 'decided_value' => $decided, 'decided_by' => $user->id, 'decided_at' => now()])->save();

            Audit::record('agreements.proposal_accepted', $proposal, ['field' => $proposal->field, 'employee_id' => $proposal->employee_id, 'edited' => $value !== null], $company, $user);

            return $proposal;
        }));
    }

    public function reject(User $user, Company $company, AgreementProposal $proposal): void
    {
        $this->authorize($user, $company);

        CurrentCompany::run($company, function () use ($user, $company, $proposal): void {
            $proposal = AgreementProposal::query()->findOrFail($proposal->id);

            if (! $proposal->isPending()) {
                throw ValidationException::withMessages(['proposal' => [__('Förslaget är redan hanterat.')]]);
            }

            $proposal->forceFill(['status' => AgreementProposal::REJECTED, 'decided_by' => $user->id, 'decided_at' => now()])->save();
            Audit::record('agreements.proposal_rejected', $proposal, ['field' => $proposal->field, 'employee_id' => $proposal->employee_id], $company, $user);
        });
    }

    /**
     * Accepts every pending proposal whose quote was found word for word in
     * the document, for one employee or for all. A proposal that cannot be
     * applied as it is (the agreement is not bound yet, say) is left for the
     * person.
     *
     * @return array{accepted: int, left: int}
     */
    public function acceptMatching(User $user, Company $company, ?int $employeeId = null): array
    {
        $this->authorize($user, $company);

        $proposals = CurrentCompany::run($company, fn (): Collection => AgreementProposal::query()
            ->where('status', AgreementProposal::PENDING)
            ->where('quote_found', true)
            ->when($employeeId !== null, fn ($query) => $query->where('employee_id', $employeeId))
            ->orderBy('field')
            ->orderBy('id')
            ->get()
            ->sortBy(fn (AgreementProposal $proposal): int => match ($proposal->field) {
                'binding' => 0,
                'agreement_key' => 1,
                default => 2,
            }));
        $accepted = 0;
        $left = 0;

        foreach ($proposals as $proposal) {
            try {
                $this->accept($user, $company, $proposal);
                $accepted++;
            } catch (ValidationException) {
                $left++;
            }
        }

        return ['accepted' => $accepted, 'left' => $left];
    }

    /** What is registered today for a proposal's field, to compare with. */
    public static function registered(AgreementProposal $proposal, ?Employment $employment): ?string
    {
        if ($employment === null) {
            return null;
        }

        return match ($proposal->field) {
            'agreement_key' => $employment->collectiveAgreement?->agreement_key,
            'agreement_group' => $employment->agreement_group,
            'position_title' => $employment->position_title,
            'employment_type' => $employment->employment_type,
            'weekly_hours' => $employment->weekly_hours === null ? null : (string) $employment->weekly_hours,
            'salary_form' => $employment->salary_form,
            'monthly_salary' => $employment->monthly_salary_ore?->format(withCurrency: false),
            'hourly_rate' => $employment->hourly_rate_ore?->format(withCurrency: false),
            'start_date' => $employment->start_date->toDateString(),
            'industry_experience_from' => $employment->industry_experience_from?->toDateString(),
            default => null,
        };
    }

    public static function fieldLabel(string $field): string
    {
        return match ($field) {
            'binding' => __('Avtal och variant för företaget'),
            'agreement_key' => __('Avtal'),
            'agreement_group' => __('Lönegrupp'),
            'position_title' => __('Befattning'),
            'employment_type' => __('Anställningsform'),
            'weekly_hours' => __('Veckoarbetstid'),
            'salary_form' => __('Löneform'),
            'monthly_salary' => __('Månadslön'),
            'hourly_rate' => __('Timlön'),
            'start_date' => __('Anställd från'),
            'industry_experience_from' => __('Branschvana från'),
            'deviation' => __('Avvikelse från avtalet'),
            default => $field,
        };
    }

    private function apply(User $user, Company $company, AgreementProposal $proposal, mixed $value): void
    {
        if ($proposal->field === 'binding') {
            $value = (array) $value;

            if (! in_array($value['variant'] ?? null, AgreementRuleVersion::VARIANTS, true) || ! filled($value['bound_from'] ?? null)) {
                throw ValidationException::withMessages(['proposal' => [__('Ange om företaget är bundet som medlem eller genom hängavtal, och från vilket datum, innan förslaget godkänns.')]]);
            }

            $existing = CollectiveAgreement::query()->where('agreement_key', $value['agreement_key'] ?? null)->first();
            $this->setup->saveBinding($user, $company, [
                'agreement_key' => $value['agreement_key'] ?? null,
                'variant' => $value['variant'],
                'bound_from' => $value['bound_from'],
                'bound_to' => $existing?->bound_to?->toDateString(),
            ], $existing);

            return;
        }

        if (in_array($proposal->field, self::INFORMATION_FIELDS, true)) {
            return;
        }

        $employment = $proposal->employment_id === null ? null : Employment::query()->find($proposal->employment_id);

        if (! $employment instanceof Employment) {
            throw ValidationException::withMessages(['proposal' => [__('Den anställda har ingen anställning att föra in uppgiften på.')]]);
        }

        $value = trim((string) $value);
        $terms = $employment->only(['collective_agreement_id', 'agreement_group', 'overtime_compensation', 'ob_compensation']);
        $terms['industry_experience_from'] = $employment->industry_experience_from?->toDateString();

        match ($proposal->field) {
            'agreement_key' => $this->setup->saveEmployment($user, $company, $employment, [...$terms, 'collective_agreement_id' => CollectiveAgreement::query()->where('agreement_key', $value)->value('id')
                ?? throw ValidationException::withMessages(['proposal' => [__('Företaget är inte bundet av avtalet ännu. Godkänn eller lägg till avtalet för företaget först.')]])]),
            'agreement_group' => $this->setup->saveEmployment($user, $company, $employment, [...$terms, 'agreement_group' => $terms['collective_agreement_id'] === null
                ? throw ValidationException::withMessages(['proposal' => [__('Knyt anställningen till ett avtal innan lönegruppen förs in.')]])
                : $value]),
            'industry_experience_from' => $this->setup->saveEmployment($user, $company, $employment, [...$terms, 'industry_experience_from' => $value]),
            'position_title' => $this->updateEmployment($user, $company, $employment, ['position_title' => mb_substr($value, 0, 255)]),
            'employment_type' => $this->updateEmployment($user, $company, $employment, ['employment_type' => in_array($value, self::EMPLOYMENT_TYPES, true)
                ? $value
                : throw ValidationException::withMessages(['proposal' => [__('Anställningsformen känns inte igen. Välj en av NONOX anställningsformer.')]])]),
            'weekly_hours' => $this->updateEmployment($user, $company, $employment, ['weekly_hours' => is_numeric(str_replace(',', '.', $value)) && (float) str_replace(',', '.', $value) > 0 && (float) str_replace(',', '.', $value) <= 168
                ? str_replace(',', '.', $value)
                : throw ValidationException::withMessages(['proposal' => [__('Veckoarbetstiden måste vara ett antal timmar.')]])]),
            default => throw ValidationException::withMessages(['proposal' => [__('Uppgiften kan inte föras in härifrån.')]]),
        };
    }

    /** @param  array<string, mixed>  $changes */
    private function updateEmployment(User $user, Company $company, Employment $employment, array $changes): Employment
    {
        $before = $employment->only(array_keys($changes));
        $employment->fill($changes)->save();
        Audit::record('agreements.employment_updated_from_proposal', $employment, ['employee_id' => $employment->employee_id, 'fields' => array_keys($changes), 'before' => $before, 'after' => $changes], $company, $user);

        return $employment;
    }

    /**
     * @param  list<array{field: string, value: mixed, quote: string, page: int}>  $proposals
     */
    private function store(User $user, Company $company, AgreementDocument $document, string $agent, ?int $employeeId, ?int $employmentId, array $proposals, string $text, int $masked): int
    {
        $haystack = PersonalDataMasker::normalise($text);

        DB::transaction(function () use ($document, $agent, $employeeId, $employmentId, $proposals, $haystack): void {
            AgreementProposal::query()->where('agreement_document_id', $document->id)->where('status', AgreementProposal::PENDING)->delete();

            foreach ($proposals as $proposal) {
                $quote = mb_substr(trim($proposal['quote']), 0, 2000);

                AgreementProposal::create([
                    'agreement_document_id' => $document->id,
                    'employee_id' => $employeeId,
                    'employment_id' => $employmentId,
                    'agent' => $agent,
                    'field' => $proposal['field'],
                    'value' => $proposal['value'],
                    'quote' => $quote === '' ? null : $quote,
                    'page' => $proposal['page'] > 0 ? $proposal['page'] : null,
                    'quote_found' => $quote !== '' && str_contains($haystack, PersonalDataMasker::normalise($quote)),
                    'status' => AgreementProposal::PENDING,
                ]);
            }

            $document->forceFill(['analyzed_at' => now()])->save();
        });

        Audit::record('agreements.ai_analysis', $document, ['agent' => $agent, 'employee_id' => $employeeId, 'proposals' => count($proposals), 'masked' => $masked], $company, $user);

        return count($proposals);
    }

    /** @return array{string, int} the masked text with page markers, and how much was masked */
    private function text(AgreementDocument $document): array
    {
        $pages = DocumentText::pages((string) Storage::disk(self::DISK)->get($document->file_path), $document->mime)
            ?? throw ValidationException::withMessages(['document' => [__('Dokumentet har ingen läsbar text.')]]);
        $result = DocumentText::forAi($pages);

        return [$result['text'], $result['masked']];
    }

    /** The agreements NONOX has data for, and what the company is, for the identification agent. */
    private function catalogContext(Company $company): string
    {
        $lines = AgreementVersions::agreements()->map(function (AgreementRuleVersion $version): string {
            $rules = $version->agreementRules();

            return "{$version->agreement_key} | {$rules->name()} | ".implode(' – ', $rules->parties())." | {$rules->collective()}";
        })->implode("\n");

        $legalForm = (string) $company->legal_form->value;

        return "Agreements (key | name | parties | collective):\n{$lines}\n\nCompany legal form: {$legalForm}";
    }

    /** The company's bound agreements with their pay groups, for the employment agent. */
    private function bindingsContext(): string
    {
        $versions = AgreementVersions::catalog();
        $lines = CollectiveAgreement::query()->whereNotNull('agreement_key')->get()->map(function (CollectiveAgreement $binding) use ($versions): string {
            $version = $versions->get("{$binding->agreement_key}|{$binding->variant}");
            $groups = $version === null ? '' : collect($version->agreementRules()->groups())
                ->map(fn (array $group): string => "  - {$group['code']}: {$group['name']} ({$group['description']})")
                ->implode("\n");

            return "{$binding->agreement_key} ({$binding->name})\n{$groups}";
        })->implode("\n");

        return "Agreements the company is bound by, with pay groups:\n".($lines === '' ? '(none recorded yet)' : $lines);
    }

    private function authorize(User $user, Company $company): void
    {
        $this->modules->ensureEnabled(AgreementsModule::KEY, $company);
        $this->modules->ensureEnabled(AiModule::KEY, $company);

        if (! $user->hasTeamPermission($company, 'agreements:manage') || ! $user->hasTeamPermission($company, 'ai:use')) {
            throw new AuthorizationException;
        }
    }
}
