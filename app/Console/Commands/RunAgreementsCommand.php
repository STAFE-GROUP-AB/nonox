<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Invoicing\RunRecurringInvoice;
use App\Domain\Invoicing\AgreementStatus;
use App\Models\Company;
use App\Models\RecurringInvoice;
use App\Models\User;
use App\Support\Tenancy\CompanyScope;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Makes the invoices that invoicing agreements are due for, every morning.
 * Each invoice is made as the person who created the agreement, with that
 * person's rights; an agreement whose creator may no longer invoice is
 * skipped and reported. A late run catches up, one invoice per missed date.
 */
class RunAgreementsCommand extends Command
{
    protected $signature = 'invoices:run-agreements';

    protected $description = 'Skapar fakturorna för avtal som ska faktureras i dag';

    /** Never make more than a year of monthly invoices for one agreement in one run. */
    private const int MAX_PER_AGREEMENT = 12;

    public function handle(RunRecurringInvoice $run): int
    {
        $today = CarbonImmutable::today();

        // Every company's agreements are read here; each one is then run inside its own company.
        // An archived company is read-only (ADR-024): its agreements wait until it is taken out of the archive.
        $agreements = CompanyScope::bypass(fn () => RecurringInvoice::query()
            ->where('status', AgreementStatus::Active->value)
            ->whereIn('company_id', Company::query()->whereNull('archived_at')->select('id'))
            ->whereDate('next_invoice_date', '<=', $today->toDateString())
            ->orderBy('company_id')
            ->get());

        $made = 0;
        $failed = 0;

        foreach ($agreements as $agreement) {
            $this->line("Avtal {$agreement->number} (företag {$agreement->company_id})...");
            $creator = User::query()->find($agreement->created_by);

            for ($i = 0; $i < self::MAX_PER_AGREEMENT; $i++) {
                $current = CompanyScope::bypass(fn () => RecurringInvoice::query()->find($agreement->id));

                if (! $current instanceof RecurringInvoice || ! $current->isDue($today)) {
                    break;
                }

                try {
                    if (! $creator instanceof User) {
                        throw new RuntimeException('Den som skapade avtalet finns inte längre.');
                    }

                    $invoice = $run($creator, $current, $today);
                    $made++;
                    $this->info("  Faktura skapad för {$invoice->invoice_date->toDateString()}.");
                } catch (Throwable $exception) {
                    $failed++;
                    $this->error("  Kunde inte fakturera: {$exception->getMessage()}");

                    break;
                }
            }
        }

        $this->comment("{$made} fakturor skapade, {$failed} avtal kunde inte faktureras.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
