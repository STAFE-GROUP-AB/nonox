<?php

declare(strict_types=1);

namespace App\Modules\Crm\Console;

use App\Models\Company;
use App\Modules\Crm\Actions\SendMailing;
use App\Modules\Crm\CrmModule;
use App\Modules\Crm\Domain\MailingStatus;
use App\Modules\Crm\Jobs\SendMailingBatch;
use App\Modules\Crm\Models\Mailing;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Console\Command;

/**
 * Every minute (ADR-048): scheduled mailings whose time has come start
 * sending, and every mailing that is sending gets one batch on the queue.
 * The batch itself keeps to the caps per company, hour and day.
 */
class SendMailingsCommand extends Command
{
    protected $signature = 'crm:send-mailings';

    protected $description = 'Start scheduled CRM mailings and queue the next batch of each mailing that is sending';

    public function handle(ModuleManager $modules, SendMailing $send): int
    {
        // Mailings of every company are looked at; each batch then runs inside its own company.
        $companyIds = CompanyScope::bypass(fn () => Mailing::query()
            ->whereIn('status', [MailingStatus::Scheduled->value, MailingStatus::Sending->value])
            ->distinct()
            ->pluck('company_id'));
        $queued = 0;

        foreach ($companyIds as $companyId) {
            $company = Company::query()->find((int) $companyId);

            if (! $company instanceof Company || $company->isArchived() || ! $modules->isEnabled(CrmModule::KEY, $company)) {
                continue;
            }

            $this->info("Checking company `{$company->id}`...");
            $send->release($company);

            $sending = CompanyScope::bypass(fn () => Mailing::query()->where('company_id', $company->id)->where('status', MailingStatus::Sending->value)->pluck('id'));

            foreach ($sending as $mailingId) {
                SendMailingBatch::dispatch($company->id, (int) $mailingId);
                $queued++;
            }
        }

        $this->comment("Queued {$queued} mailing batches.");

        return self::SUCCESS;
    }
}
