<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Actions\Accounting\AttachToVoucher;
use App\Domain\Company\LegalForm;
use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Domain\Rules\MissingPayrollRuleException;
use App\Modules\Payroll\Domain\Rules\RuleSet;
use App\Modules\Payroll\Models\AgiSubmission;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\PayrollModule;
use App\Modules\Payroll\Support\Agi\AgiDeclaration;
use App\Modules\Payroll\Support\Agi\AgiIndividual;
use App\Modules\Payroll\Support\Agi\AgiPayee;
use App\Modules\Payroll\Support\Agi\AgiXml;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Arbetsgivardeklarationen per payment month (PAYROLL_MODULE.md B.13), v1
 * as a file the company uploads in Skatteverket's e-service:
 *
 * - generate: the file from every approved run paid in the month. When an
 *   earlier file for the month was submitted, the new one corrects it: each
 *   individuppgift is resent with the same specification number and a payee
 *   no longer in the month is removed.
 * - submitted / decided: what happened at Skatteverket, recorded by hand.
 *
 * Files are validated against Skatteverket's schema when its files are
 * installed (services.skatteverket.agi_schema_dir).
 *
 * Reading the file into Skatteverket's own space through its API is
 * AgiSkatteverketUploads (ADR-054, stage 2).
 *
 * TODO(legal-verify): the declaration of an enskild firma, whose employer id
 * is the owner's personal number.
 */
class AgiSubmissions
{
    public const string PROGRAM = 'NONOX Ekonomisystem';

    public function __construct(private readonly ModuleManager $modules) {}

    public function generate(User $user, Company $company, string $period): AgiSubmission
    {
        $this->authorize($user, $company);

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
            throw ValidationException::withMessages(['period' => [__('Välj en månad.')]]);
        }

        $employerId = $this->employerId($company);
        $contact = $this->contact($user, $company);

        return CurrentCompany::run($company, fn (): AgiSubmission => DB::transaction(function () use ($user, $company, $period, $employerId, $contact): AgiSubmission {
            try {
                RuleSet::for(CarbonImmutable::parse("{$period}-01"))->get('agi.schema_version');
            } catch (MissingPayrollRuleException) {
                throw ValidationException::withMessages(['period' => [__('Regeln för arbetsgivardeklarationens format är inte kontrollerad för :period. Kontrollera den under Lön → Regler.', ['period' => $period])]]);
            }

            $existing = AgiSubmission::query()->where('period', $period)->orderByDesc('sequence')->lockForUpdate()->get();
            $declaration = AgiDeclaration::for($period);
            $reported = $existing->first(fn (AgiSubmission $submission): bool => in_array($submission->status, AgiSubmission::REPORTED, true));
            $removed = $reported === null ? [] : array_values(array_diff(array_map('intval', array_keys($reported->individuals)), array_keys($declaration->individuals)));

            if ($declaration->individuals === [] && $removed === []) {
                throw ValidationException::withMessages(['period' => [__('Inga löner är utbetalda i :period.', ['period' => $period])]]);
            }

            $employees = Employee::query()->whereIn('id', [...array_keys($declaration->individuals), ...$removed])->get()->keyBy('id');
            $xml = app(AgiXml::class)->write(
                $employerId,
                $period,
                $contact,
                $declaration->contributionsKronor(),
                $declaration->taxKronor(),
                array_values(array_map(fn (AgiIndividual $individual): array => ['payee' => AgiPayee::for($employees->get($individual->employeeId)), 'individual' => $individual], $declaration->individuals)),
                array_map(fn (int $employeeId): array => ['payee' => AgiPayee::for($employees->get($employeeId)), 'specification' => (int) ($reported?->individuals[$employeeId]['specification'] ?? $employeeId)], $removed),
                CarbonImmutable::now(),
                $declaration->regionalSupportKronor(),
            );
            $this->validateSchema($xml);

            $draft = $existing->first(fn (AgiSubmission $submission): bool => $submission->status === AgiSubmission::GENERATED);
            $submission = $draft ?? new AgiSubmission(['period' => $period, 'sequence' => ((int) $existing->max('sequence')) + 1]);
            $previousPath = $draft?->xml_path;
            $path = "agi/{$company->id}/{$period}-{$submission->sequence}-".substr(hash('sha256', $xml), 0, 12).'.xml';

            Storage::disk(AttachToVoucher::DISK)->put($path, $xml);

            $submission->fill([
                'xml_path' => $path,
                'sha256' => hash('sha256', $xml),
                'tax_ore' => $declaration->taxOre,
                'contributions_ore' => $declaration->contributionsOre,
                'individuals' => array_map(fn (AgiIndividual $individual): array => $individual->toArray(), array_combine(array_map('strval', array_keys($declaration->individuals)), array_values($declaration->individuals))),
                'removals' => count($removed),
                'generated_by' => $user->id,
            ])->save();

            if ($previousPath !== null && $previousPath !== $path) {
                Storage::disk(AttachToVoucher::DISK)->delete($previousPath);
            }

            Audit::record('payroll.agi_generated', $submission, [
                'period' => $period,
                'sequence' => $submission->sequence,
                'individuals' => count($declaration->individuals),
                'removals' => count($removed),
                'tax_ore' => $declaration->taxOre,
                'contributions_ore' => $declaration->contributionsOre,
                'sha256' => $submission->sha256,
            ], $company, $user);

            return $submission;
        }));
    }

    /** Marks a file as uploaded to Skatteverket, with the receipt number when there is one. */
    public function submitted(User $user, AgiSubmission $submission, ?string $receipt): AgiSubmission
    {
        $company = Company::query()->findOrFail($submission->company_id);
        $this->authorize($user, $company);

        return CurrentCompany::run($company, fn (): AgiSubmission => DB::transaction(function () use ($user, $company, $submission, $receipt): AgiSubmission {
            /** @var AgiSubmission $submission */
            $submission = AgiSubmission::query()->whereKey($submission->id)->lockForUpdate()->firstOrFail();

            if ($submission->status !== AgiSubmission::GENERATED) {
                throw ValidationException::withMessages(['submission' => [__('Filen är redan markerad som inlämnad.')]]);
            }

            $submission->forceFill([
                'status' => AgiSubmission::SUBMITTED,
                'receipt_reference' => filled($receipt) ? mb_substr(trim((string) $receipt), 0, 100) : null,
                'submitted_by' => $user->id,
                'submitted_at' => CarbonImmutable::now(),
            ])->save();

            $month = CarbonImmutable::parse("{$submission->period}-01");
            PayrollRun::query()
                ->whereBetween('payment_date', [$month->startOfMonth()->toDateString(), $month->endOfMonth()->toDateString()])
                ->whereIn('status', AgiDeclaration::DECLARED_STATUSES)
                ->update(['agi_submission_id' => $submission->id]);

            Audit::record('payroll.agi_submitted', $submission, ['period' => $submission->period, 'sequence' => $submission->sequence, 'receipt' => $submission->receipt_reference], $company, $user);

            return $submission;
        }));
    }

    /**
     * Skatteverket's answer. An accepted file replaces the earlier ones for
     * the month; a rejected one leaves them as they were.
     */
    public function decided(User $user, AgiSubmission $submission, bool $accepted): AgiSubmission
    {
        $company = Company::query()->findOrFail($submission->company_id);
        $this->authorize($user, $company);

        return CurrentCompany::run($company, fn (): AgiSubmission => DB::transaction(function () use ($user, $company, $submission, $accepted): AgiSubmission {
            /** @var AgiSubmission $submission */
            $submission = AgiSubmission::query()->whereKey($submission->id)->lockForUpdate()->firstOrFail();

            if ($submission->status !== AgiSubmission::SUBMITTED) {
                throw ValidationException::withMessages(['submission' => [__('Bara en inlämnad fil kan godkännas eller avvisas.')]]);
            }

            $submission->forceFill(['status' => $accepted ? AgiSubmission::ACCEPTED : AgiSubmission::REJECTED, 'decided_at' => CarbonImmutable::now()])->save();

            if ($accepted) {
                AgiSubmission::query()
                    ->where('period', $submission->period)
                    ->where('sequence', '<', $submission->sequence)
                    ->whereIn('status', AgiSubmission::REPORTED)
                    ->update(['status' => AgiSubmission::CORRECTED]);
            }

            Audit::record($accepted ? 'payroll.agi_accepted' : 'payroll.agi_rejected', $submission, ['period' => $submission->period, 'sequence' => $submission->sequence], $company, $user);

            return $submission;
        }));
    }

    private function authorize(User $user, Company $company): void
    {
        $this->modules->ensureEnabled(PayrollModule::KEY, $company);

        if (! $user->hasTeamPermission($company, 'payroll:agi-submit')) {
            throw new AuthorizationException;
        }
    }

    /** Skatteverket's twelve-digit id for a legal person: 16 and the organisation number. */
    private function employerId(Company $company): string
    {
        $digits = (string) preg_replace('/\D/', '', (string) $company->org_number);

        if ($company->legal_form === LegalForm::Ef) {
            throw ValidationException::withMessages(['period' => [__('Arbetsgivardeklaration för enskild firma görs med ägarens personnummer och stöds inte ännu.')]]);
        }

        if (strlen($digits) !== 10) {
            throw ValidationException::withMessages(['period' => [__('Fyll i företagets organisationsnummer först.')]]);
        }

        return '16'.$digits;
    }

    /** @return array{program: string, name: string, phone: string, email: string} */
    private function contact(User $user, Company $company): array
    {
        if (blank($company->phone)) {
            throw ValidationException::withMessages(['period' => [__('Skatteverket kräver ett telefonnummer till kontaktpersonen. Fyll i företagets telefonnummer under Företagsinställningar.')]]);
        }

        return [
            'program' => self::PROGRAM,
            'name' => mb_substr($user->name, 0, 50),
            'phone' => mb_substr((string) $company->phone, 0, 20),
            'email' => $user->email,
        ];
    }

    private function validateSchema(string $xml): void
    {
        $directory = config('services.skatteverket.agi_schema_dir');

        if (! is_string($directory) || ! is_file(rtrim($directory, '/').'/arbetsgivardeklaration_1.1.xsd')) {
            return;
        }

        $errors = AgiXml::validate($xml, $directory);

        if ($errors !== []) {
            throw ValidationException::withMessages(['period' => [__('Filen följer inte Skatteverkets schema: :error', ['error' => $errors[0]])]]);
        }
    }
}
