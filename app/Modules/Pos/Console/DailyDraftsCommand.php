<?php

declare(strict_types=1);

namespace App\Modules\Pos\Console;

use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Pos\Actions\CreateDailyDraft;
use App\Modules\Pos\Models\PosConnection;
use App\Modules\Pos\Models\PosFee;
use App\Modules\Pos\Models\PosSale;
use App\Modules\Pos\PosModule;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Proposes the daily till voucher for every day before today that has
 * sales not yet in a voucher (ADR-042), for companies that keep the
 * setting on. The draft is created in the name of the person who connected
 * the till, if they may still create vouchers; it is never posted.
 */
class DailyDraftsCommand extends Command
{
    protected $signature = 'pos:daily-drafts {--company= : Only this company id}';

    protected $description = 'Create draft vouchers in series K for past days of till sales';

    public function handle(ModuleManager $modules, CreateDailyDraft $create): int
    {
        $today = CarbonImmutable::today('Europe/Stockholm')->toDateString();
        $companies = $this->option('company') !== null ? [(int) $this->option('company')] : $modules->companiesWith(PosModule::KEY);
        $created = 0;

        foreach ($companies as $companyId) {
            $company = Company::query()->find($companyId);

            if (! $company instanceof Company || $company->isArchived() || ! $modules->isEnabled(PosModule::KEY, $company)) {
                continue;
            }

            if (! (bool) ($modules->settings(PosModule::KEY, $company)['auto_daily_draft'] ?? true)) {
                continue;
            }

            [$days, $userId] = CurrentCompany::run($company, fn (): array => [
                PosSale::query()->whereNull('voucher_id')->where('business_date', '<', $today)->distinct()->pluck('business_date')
                    ->merge(PosFee::query()->whereNull('voucher_id')->where('business_date', '<', $today)->distinct()->pluck('business_date'))
                    ->map(fn (mixed $date): string => CarbonImmutable::parse($date)->toDateString())->unique()->sort()->values()->all(),
                PosConnection::query()->latest('connected_at')->value('connected_by'),
            ]);

            $user = $userId === null ? null : User::query()->find($userId);

            if (! $user instanceof User) {
                continue;
            }

            foreach ($days as $day) {
                $this->info("Company `{$companyId}`, {$day}...");

                try {
                    $create($user, $company, CarbonImmutable::parse($day));
                    $created++;
                } catch (ValidationException $exception) {
                    $this->warn('  '.collect($exception->errors())->flatten()->first());
                } catch (AuthorizationException) {
                    $this->warn('  the person who connected the till may no longer create vouchers.');

                    break;
                }
            }
        }

        $this->comment("Created {$created} draft vouchers.");

        return self::SUCCESS;
    }
}
