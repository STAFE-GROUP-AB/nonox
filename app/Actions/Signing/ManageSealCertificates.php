<?php

declare(strict_types=1);

namespace App\Actions\Signing;

use App\Actions\Tasks\SaveTask;
use App\Domain\Signing\Seal\SealCertificates;
use App\Domain\Tasks\TaskPriority;
use App\Domain\Tasks\TaskType;
use App\Models\Company;
use App\Models\SigningSealCertificate;
use App\Models\Task;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * A company's own seal certificate (ADR-046 amendment, gap 5, package S10),
 * under Inställningar för företaget → Signering. Needs signing:settings.
 *
 * - upload: a PKCS#12 and its password are checked (SealCertificates::read),
 *   then stored encrypted; the company's earlier certificate is retired in
 *   the same transaction (its key and password emptied).
 * - retire: the certificate stops being used and loses its key; the company
 *   then seals with the instance seal.
 * - raiseExpiryTasks: a to-do for the certificate's uploader (else the
 *   owner) 60 days before it expires, once per certificate.
 *
 * The audit entries hold ids and dates only; never the file, the password,
 * the key or the certificate's names.
 */
class ManageSealCertificates
{
    public function upload(User $actor, Company $company, #[SensitiveParameter] string $pkcs12, #[SensitiveParameter] string $password): SigningSealCertificate
    {
        $this->authorize($actor, $company);
        $info = SealCertificates::read($pkcs12, $password);

        return CurrentCompany::run($company, fn (): SigningSealCertificate => DB::transaction(function () use ($actor, $company, $pkcs12, $password, $info): SigningSealCertificate {
            $retired = $this->retireActive($actor, $company);

            $certificate = SigningSealCertificate::query()->create([
                'company_id' => $company->id,
                'subject' => $info->subject,
                'issuer' => $info->issuer,
                'serial' => $info->serial,
                'not_before' => $info->notBefore,
                'not_after' => $info->notAfter,
                'fingerprint_sha256' => $info->fingerprintSha256,
                'self_signed' => $info->selfSigned,
                'pkcs12' => base64_encode($pkcs12),
                'password' => $password,
                'uploaded_by' => $actor->id,
            ]);

            Audit::record('signing.seal_certificate_uploaded', $certificate, [
                'seal_certificate_id' => $certificate->id,
                'replaced_id' => $retired,
                'not_after' => $info->notAfter->toDateString(),
                'self_signed' => $info->selfSigned,
            ], $company, $actor);

            return $certificate;
        }));
    }

    public function retire(User $actor, Company $company, int $certificateId): void
    {
        $this->authorize($actor, $company);

        CurrentCompany::run($company, fn () => DB::transaction(function () use ($actor, $company, $certificateId): void {
            // Another company's id is not found here (the company scope): 404.
            $certificate = SigningSealCertificate::query()->whereKey($certificateId)->lockForUpdate()->firstOrFail();

            if ($certificate->retired_at !== null) {
                return;
            }

            $this->retireOne($certificate, $actor);
            Audit::record('signing.seal_certificate_retired', $certificate, ['seal_certificate_id' => $certificate->id], $company, $actor);
        }));
    }

    /**
     * Raises the expiry to-do for every active certificate of every company that expires within 60 days and has
     * none yet. Returns how many were raised.
     */
    public function raiseExpiryTasks(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $raised = 0;

        // Across companies on purpose (ADR-046 S10, the expiry to-do): each row is then handled inside its own company.
        $due = CompanyScope::bypass(fn () => SigningSealCertificate::query()
            ->whereNull('retired_at')
            ->whereNull('expiry_task_id')
            ->where('not_after', '<=', $now->addDays(SealCertificates::EXPIRY_WARNING_DAYS))
            ->orderBy('id')
            ->get(['id', 'company_id']));

        foreach ($due as $row) {
            $company = Company::query()->find($row->company_id);

            if (! $company instanceof Company || $company->archived_at !== null) {
                continue;
            }

            $raised += CurrentCompany::run($company, fn (): int => $this->raiseExpiryTask($company, $row->id, $now));
        }

        return $raised;
    }

    private function raiseExpiryTask(Company $company, int $certificateId, CarbonImmutable $now): int
    {
        return DB::transaction(function () use ($company, $certificateId, $now): int {
            $certificate = SigningSealCertificate::query()->whereKey($certificateId)->lockForUpdate()->first();

            if (! $certificate instanceof SigningSealCertificate || $certificate->retired_at !== null || $certificate->expiry_task_id !== null) {
                return 0;
            }

            $assignee = $this->assignee($company, $certificate);

            if (! $assignee instanceof User) {
                return 0;
            }

            $zone = (string) config('app.timezone');
            $expired = $certificate->isExpired($now);

            try {
                $task = app(SaveTask::class)($assignee, $company, [
                    'title' => $expired
                        ? __('Förseglingscertifikatet har gått ut: ladda upp ett nytt')
                        : __('Förseglingscertifikatet går ut :date: ladda upp ett nytt', ['date' => $certificate->not_after->setTimezone($zone)->format('Y-m-d')]),
                    'description' => __('Under Inställningar för företaget → Signering laddar du upp företagets nya förseglingscertifikat. När det gamla har gått ut förseglas dokumenten med installationens eget certifikat i stället.'),
                    'type' => TaskType::Documents->value,
                    'priority' => TaskPriority::High->value,
                    'due_date' => $certificate->not_after->setTimezone($zone)->subDays(14)->max($now->setTimezone($zone))->format('Y-m-d'),
                    'assignee_id' => $assignee->id,
                ]);
            } catch (AuthorizationException) {
                return 0;
            }

            $certificate->forceFill(['expiry_task_id' => $task->id])->save();
            Audit::record('signing.seal_certificate_expiry_task', $certificate, ['seal_certificate_id' => $certificate->id, 'task_id' => $task->id], $company);

            return 1;
        });
    }

    /** Who gets the to-do: the uploader while they may still manage the setting, else the owner. */
    private function assignee(Company $company, SigningSealCertificate $certificate): ?User
    {
        foreach ([$certificate->uploaded_by, $company->user_id] as $id) {
            $user = $id === null ? null : User::query()->find($id);

            if ($user instanceof User && $user->hasTeamPermission($company, 'signing:settings') && $user->can('create', [Task::class, $company])) {
                return $user;
            }
        }

        return null;
    }

    /** Retires the company's active certificate, if any; returns its id. */
    private function retireActive(User $actor, Company $company): ?int
    {
        $active = SigningSealCertificate::query()->active()->lockForUpdate()->first();

        if (! $active instanceof SigningSealCertificate) {
            return null;
        }

        $this->retireOne($active, $actor);
        Audit::record('signing.seal_certificate_retired', $active, ['seal_certificate_id' => $active->id], $company, $actor);

        return $active->id;
    }

    private function retireOne(SigningSealCertificate $certificate, User $actor): void
    {
        // The key and its password go with the retirement; the public facts stay for the envelopes it sealed.
        $certificate->forceFill(['retired_at' => now(), 'retired_by' => $actor->id, 'pkcs12' => null, 'password' => null])->save();
    }

    private function authorize(User $actor, Company $company): void
    {
        if (! $actor->hasTeamPermission($company, 'signing:settings')) {
            throw new AuthorizationException;
        }
    }
}
