<?php

declare(strict_types=1);

namespace App\Modules\Banking\Console;

use App\Models\Company;
use App\Models\User;
use App\Modules\Banking\Actions\ExpireBankConsents;
use App\Modules\Banking\BankingModule;
use App\Modules\Banking\Domain\ConnectionStatus;
use App\Modules\Banking\Mail\BankConsentExpiringMail;
use App\Modules\Banking\Models\BankConnection;
use App\Modules\Banking\Support\BankDataProviders;
use App\Modules\ModuleManager;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Once a day: reminds the people who manage the bank connections that a
 * consent runs out soon, once per consent, by the company's setting
 * (default 14 days before). Renewing takes a new BankID approval in the
 * bank; NONOX cannot extend a consent by itself. Consents past their end
 * are marked as expired first. No reminder goes out while the operator has
 * paused the provider (renewing is not possible then); it goes once the
 * provider is back, since reminded_at is still empty.
 */
class RemindBankConsentCommand extends Command
{
    protected $signature = 'bank:remind-consent';

    protected $description = 'Påminner om bankkopplingar vars medgivande snart går ut (NONOX Bank)';

    public function handle(ModuleManager $modules, ExpireBankConsents $expire): int
    {
        $sent = 0;
        $paused = BankDataProviders::isPaused();

        foreach ($modules->companiesWith(BankingModule::KEY) as $companyId) {
            $company = Company::query()->find($companyId);

            if (! $company instanceof Company || $company->isArchived()) {
                continue;
            }

            $expire($company);
            $settings = $modules->settings(BankingModule::KEY, $company);

            if ($paused || ! BankingModule::setting($settings, 'reconsent_reminders')) {
                continue;
            }

            $days = (int) BankingModule::setting($settings, 'reconsent_reminder_days');
            $this->info("Kontrollerar företag {$company->id}...");
            $sent += CurrentCompany::run($company, fn (): int => $this->remind($company, $days));
        }

        $this->comment("Skickade {$sent} påminnelser.");

        return self::SUCCESS;
    }

    private function remind(Company $company, int $days): int
    {
        $expiring = BankConnection::query()
            ->where('status', ConnectionStatus::Active->value)
            ->whereNull('reminded_at')
            ->whereNotNull('valid_until')
            ->where('valid_until', '<=', CarbonImmutable::now()->addDays($days))
            ->orderBy('valid_until')
            ->get();

        if ($expiring->isEmpty()) {
            return 0;
        }

        $banks = array_values($expiring->map(fn (BankConnection $connection): array => [
            'bank' => $connection->institution_name,
            'date' => $connection->valid_until?->toDateString() ?? '',
        ])->all());

        $managers = $company->allUsers()->filter(fn (User $user): bool => $user->hasTeamPermission($company, 'banking:manage'));
        $sent = 0;

        foreach ($managers as $manager) {
            Mail::to($manager->email)->send(new BankConsentExpiringMail($banks, $company->name));
            $sent++;
        }

        foreach ($expiring as $connection) {
            $connection->forceFill(['reminded_at' => now()])->save();
            Audit::record('banking.reconsent_reminder', $connection, ['bank' => $connection->institution_name, 'valid_until' => $connection->valid_until?->toDateString(), 'recipients' => $sent], $company);
        }

        return $sent;
    }
}
