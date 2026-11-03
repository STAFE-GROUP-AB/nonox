<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Hq\SyncCompanyToOperator;
use App\Actions\Hq\SyncWaitlistEntryToOperator;
use App\Domain\Waitlist\WaitlistStatus;
use App\Jobs\SyncToOperator;
use App\Models\Company;
use App\Models\OperatorLink;
use App\Models\WaitlistEntry;
use App\Modules\Crm\CrmModule;
use App\Modules\ModuleManager;
use App\Support\Hq\OperatorSync;
use App\Support\Instance\InstanceSettings;
use Illuminate\Console\Command;
use Throwable;

/**
 * Brings the operator company's registers up to date with the installation
 * (ADR-044): every company as a customer and every queue applicant as a CRM
 * prospect, for what existed before the operator company was chosen or
 * what failed to sync. Idempotent: the links in operator_links make a
 * second run change nothing. The queue first, so that an applicant's
 * prospect is there before their company is synced.
 *
 * --dry-run counts what would happen and writes nothing. The output is
 * counts and ids, never names, numbers or addresses.
 */
class SyncOperatorCustomersCommand extends Command
{
    protected $signature = 'hq:sync-operator-customers {--dry-run : Räkna bara, ändra ingenting}';

    protected $description = 'Gör installationens företag till kunder och köanmälningarna till prospekt hos driftbolaget.';

    public function handle(SyncCompanyToOperator $syncCompany, SyncWaitlistEntryToOperator $syncEntry, ModuleManager $modules): int
    {
        $settings = InstanceSettings::current();
        $customersTarget = $settings->customersCompanyId() === null ? null : Company::query()->find($settings->customersCompanyId());
        $waitlistTarget = $settings->waitlistCompanyId() === null ? null : Company::query()->find($settings->waitlistCompanyId());

        if ($customersTarget === null && $waitlistTarget === null) {
            $this->warn('Inget driftbolag är valt i HQ. Ingenting att göra.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $crmOn = $waitlistTarget !== null && $modules->isEnabled(CrmModule::KEY, $waitlistTarget);

        if ($waitlistTarget !== null && ! $crmOn) {
            $this->warn("CRM är inte påslaget i företag {$waitlistTarget->id}: köanmälningarna hoppas över.");
        }

        if ($waitlistTarget !== null && $crmOn) {
            $this->entries($waitlistTarget, $syncEntry, $dryRun);
        }

        if ($customersTarget !== null) {
            $this->companies($customersTarget, $syncCompany, $dryRun);
        }

        $this->comment($dryRun ? 'Provkörning klar. Ingenting ändrades.' : 'Klart.');

        return self::SUCCESS;
    }

    private function entries(Company $target, SyncWaitlistEntryToOperator $sync, bool $dryRun): void
    {
        $query = WaitlistEntry::query()->where('status', '!=', WaitlistStatus::Rejected->value)->orderBy('id');

        if ($dryRun) {
            $linked = (clone $query)->whereIn('id', OperatorLink::query()->where('target_company_id', $target->id)->whereIn('status', [OperatorLink::SYNCED, OperatorLink::SUPPRESSED])->select('waitlist_entry_id'))->count();
            $total = $query->count();

            $this->info("Kön: {$total} anmälningar, {$linked} redan synkade, ".($total - $linked).' att synka (anmälningar vars användare redan har ett företag hoppas över).');

            return;
        }

        $outcomes = [];

        foreach ($query->lazyById(200) as $entry) {
            $this->line("Synkar anmälan {$entry->id} …");

            try {
                $outcome = $sync($entry, $target);
            } catch (Throwable $exception) {
                SyncToOperator::fail(SyncToOperator::WAITLIST, $entry->id, $target->id, $exception);
                $outcome = 'failed';
            }

            $outcomes[$outcome] = ($outcomes[$outcome] ?? 0) + 1;
        }

        $this->info('Kön: '.self::summary($outcomes));
    }

    private function companies(Company $target, SyncCompanyToOperator $sync, bool $dryRun): void
    {
        $query = Company::query()->where('is_test_company', false)->orderBy('id');

        if ($dryRun) {
            $eligible = $query->get(['id', 'archived_at', 'is_test_company'])->reject(fn (Company $company): bool => OperatorSync::isExcluded($company, $target));
            $linked = OperatorLink::query()->where('target_company_id', $target->id)->whereNotNull('customer_id')->whereIn('source_company_id', $eligible->modelKeys())->count();
            $known = OperatorLink::query()->where('target_company_id', $target->id)->whereNotNull('source_company_id')->pluck('source_company_id')->all();
            $toCreate = $eligible->filter(fn (Company $company): bool => ! $company->isArchived() && ! in_array($company->id, $known, true))->count();

            $this->info("Företag: {$eligible->count()} i installationen, {$linked} redan kunder, {$toCreate} blir kunder (eller kopplas till en befintlig kund med samma organisationsnummer).");

            return;
        }

        $outcomes = [];

        foreach ($query->lazyById(200) as $company) {
            if (OperatorSync::isExcluded($company, $target)) {
                continue;
            }

            $this->line("Synkar företag {$company->id} …");

            try {
                $outcome = $sync($company, $target);
            } catch (Throwable $exception) {
                SyncToOperator::fail(SyncToOperator::COMPANY, $company->id, $target->id, $exception);
                $outcome = 'failed';
            }

            $outcomes[$outcome] = ($outcomes[$outcome] ?? 0) + 1;
        }

        $this->info('Företag: '.self::summary($outcomes));
    }

    /** @param  array<string, int>  $outcomes */
    private static function summary(array $outcomes): string
    {
        if ($outcomes === []) {
            return 'ingenting att synka.';
        }

        ksort($outcomes);

        return implode(', ', array_map(fn (string $outcome, int $count): string => "{$outcome} {$count}", array_keys($outcomes), $outcomes)).'.';
    }
}
