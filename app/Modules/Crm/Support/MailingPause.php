<?php

declare(strict_types=1);

namespace App\Modules\Crm\Support;

use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\OperatorLink;
use App\Models\User;
use App\Modules\Crm\CrmModule;
use App\Modules\Crm\Mail\MailingsPausedMail;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Mailing;
use App\Support\Audit\Audit;
use App\Support\Instance\InstanceSettings;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Pauses a company's mailings when its recipients complain or its
 * addresses bounce too often (ADR-048, second review round): more than
 * 0.3 % spam complaints or more than 5 % hard bounces of the messages sent
 * in the last 30 days. Those are the limits the large mailbox providers
 * hold a sender to; past them the sending domain's reputation, shared by
 * every company on the hosted service, is at risk.
 *
 * While paused no batch is sent (a mailing that is sending waits) and no
 * mailing can start. The owner and the administrators get an e-mail, and
 * the operator company (ADR-044) gets a note on the company's account in
 * its CRM. Someone with crm:mailings resumes after dealing with the cause;
 * that is audited and noted for the operator too.
 *
 * Kept in the CRM module's settings (mailings_paused_at, _reason).
 */
final class MailingPause
{
    public const float COMPLAINT_RATE = 0.003;

    public const float BOUNCE_RATE = 0.05;

    public const int WINDOW_DAYS = 30;

    public static function pausedAt(Company $company): ?CarbonImmutable
    {
        $value = self::settings($company)['mailings_paused_at'] ?? null;

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }

    public static function reason(Company $company): ?string
    {
        $value = self::settings($company)['mailings_paused_reason'] ?? null;

        return is_string($value) ? $value : null;
    }

    /** Pauses the company's mailings when the last 30 days are past a limit. Returns whether it paused now. */
    public function check(Company $company): bool
    {
        if (self::pausedAt($company) !== null) {
            return false;
        }

        $totals = CurrentCompany::run($company, fn () => Mailing::query()
            ->where('started_at', '>=', CarbonImmutable::now()->subDays(self::WINDOW_DAYS))
            ->toBase()
            ->selectRaw('COALESCE(SUM(sent_count), 0) AS sent, COALESCE(SUM(complaint_count), 0) AS complaints, COALESCE(SUM(bounced_count), 0) AS bounces')
            ->first());

        $sent = (int) ($totals->sent ?? 0);

        if ($sent === 0) {
            return false;
        }

        $complaints = (int) ($totals->complaints ?? 0) / $sent;
        $bounces = (int) ($totals->bounces ?? 0) / $sent;
        $reason = match (true) {
            $complaints > self::COMPLAINT_RATE => 'COMPLAINTS',
            $bounces > self::BOUNCE_RATE => 'BOUNCES',
            default => null,
        };

        if ($reason === null) {
            return false;
        }

        $this->write($company, ['mailings_paused_at' => CarbonImmutable::now()->toIso8601String(), 'mailings_paused_reason' => $reason]);
        Audit::record('crm.mailings_paused', null, ['reason' => $reason, 'sent' => $sent, 'complaints_per_mille' => (int) round($complaints * 1000), 'bounces_per_mille' => (int) round($bounces * 1000)], $company);
        Log::warning('CRM mailings paused for a company.', ['company_id' => $company->id, 'reason' => $reason]);

        $this->tellCompany($company, $reason);
        $this->noteForOperator($company, $reason === 'COMPLAINTS'
            ? __('Utskicken pausades automatiskt: för många anmälningar om skräppost de senaste 30 dagarna.')
            : __('Utskicken pausades automatiskt: för många adresser studsade de senaste 30 dagarna.'));

        return true;
    }

    public function resume(User $user, Company $company): void
    {
        Gate::forUser($user)->authorize('mailings', [Mailing::class, $company]);

        if (self::pausedAt($company) === null) {
            return;
        }

        $this->write($company, ['mailings_paused_at' => null, 'mailings_paused_reason' => null]);
        Audit::record('crm.mailings_resumed', null, [], $company, $user);
        $this->noteForOperator($company, __('Utskicken återupptogs av företaget efter en automatisk paus.'));
    }

    public static function message(Company $company): string
    {
        return self::reason($company) === 'COMPLAINTS'
            ? __('Utskicken är pausade: fler än 0,3 procent av mottagarna har anmält utskick som skräppost de senaste 30 dagarna. Gå igenom segmenten och samtyckena innan ni återupptar.')
            : __('Utskicken är pausade: fler än 5 procent av adresserna studsade de senaste 30 dagarna. Rensa gamla adresser innan ni återupptar.');
    }

    /** @return array<string, mixed> */
    private static function settings(Company $company): array
    {
        return CurrentCompany::run($company, fn (): array => (array) CompanyModule::query()->where('module_key', CrmModule::KEY)->first()?->settings);
    }

    /** @param  array<string, ?string>  $values */
    private function write(Company $company, array $values): void
    {
        CurrentCompany::run($company, function () use ($values): void {
            $row = CompanyModule::query()->where('module_key', CrmModule::KEY)->first();
            $row?->forceFill(['settings' => [...(array) $row->settings, ...$values]])->save();
        });
    }

    private function tellCompany(Company $company, string $reason): void
    {
        $ids = [$company->user_id, ...array_map('intval', $company->users()->wherePivot('role', 'admin')->pluck('users.id')->all())];

        foreach (User::query()->whereIn('id', array_unique($ids))->get() as $user) {
            try {
                Mail::to((string) $user->email)->send(new MailingsPausedMail($company->name, $reason));
            } catch (Throwable $exception) {
                Log::warning('CRM: the notice about paused mailings could not be sent.', ['company_id' => $company->id, 'error' => $exception->getMessage()]);
            }
        }
    }

    /** A note on the company's account in the operator company's CRM (ADR-044), when there is one. */
    private function noteForOperator(Company $company, string $text): void
    {
        $operatorId = InstanceSettings::current()->operatorCompanyId();

        if ($operatorId === null || $operatorId === $company->id) {
            return;
        }

        // Instance data that points into the operator company: read across companies, written inside it.
        $accountId = CompanyScope::bypass(fn (): mixed => OperatorLink::query()->where('target_company_id', $operatorId)->where('source_company_id', $company->id)->value('crm_account_id'));

        if ($accountId === null) {
            return;
        }

        CurrentCompany::run($operatorId, fn () => Activity::query()->create([
            'account_id' => (int) $accountId,
            'type' => 'NOTE',
            'subject' => __('Utskick: automatisk paus'),
            'body' => $text,
            'done_at' => now(),
        ]));
    }
}
