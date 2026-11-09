<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Extras;

use App\Actions\Invoicing\RunRecurringInvoice;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveRecurringInvoice;
use App\Domain\Invoicing\AgreementStatus;
use App\Models\Customer;
use App\Models\RecurringInvoice;
use App\Modules\ModuleManager;
use App\Modules\Saas\Actions\CloseSaasMonth;
use App\Modules\Saas\Domain\Months;
use App\Modules\Saas\SaasModule;
use App\Modules\Saas\Support\SaasLedger;
use App\Support\Tenancy\CurrentCompany;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoExtra;

/**
 * NONOX SaaS (ADR-080) in the consultancy, for the manual's pages on
 * Återkommande intäkter: the firm also sells a small subscription service to
 * made-up customers. During the year customers start, upgrade, downgrade,
 * pause and come back, one leaves, one gives notice on an annual plan, and
 * a price correction is registered after its month was closed, so every
 * page has something on it. The ledger starts in January (for the demo the
 * first month is moved back to the start of the year), the months up to the
 * one before last are closed, and the last two stay open.
 */
final class SaasExtras extends DemoExtra
{
    /** Made-up subscription customers: name => [price per period, months per period, effective from]. */
    private const array CUSTOMERS = [
        'Molnkontoret Exempel AB' => ['2990', 1, '01-01'],
        'Byggplan Demo AB' => ['4990', 1, '01-01'],
        'Ritverket Exempel AB' => ['29900', 12, '01-01'],
        'Fiktiva Fastigheter AB' => ['1490', 1, '01-01'],
        'Exempelskolan i Teststad AB' => ['3490', 1, '03-10'],
        'Påhittade Partner AB' => ['8970', 3, '06-01'],
        'Testlabbet Demo AB' => ['5990', 1, '08-01'],
        'Exempelbyrån Norr AB' => ['2490', 1, '09-15'],
    ];

    public function industries(): array
    {
        return ['bokforing-for-konsulter'];
    }

    public function afterAll(DemoContext $c): void
    {
        $this->atomically($c, 'Återkommande intäkter', fn () => CurrentCompany::run($c->company, function () use ($c): void {
            $year = $c->today->year;
            $date = fn (string $monthDay): string => "{$year}-{$monthDay}";
            $modules = app(ModuleManager::class);
            $modules->enable(SaasModule::KEY, $c->company, $c->user);
            $modules->updateSettings(SaasModule::KEY, $c->company, $c->user, ['history_from' => "{$year}-01-01"]);

            foreach (self::CUSTOMERS as $name => [$price, $months, $from]) {
                $customer = app(SaveCustomer::class)($c->user, $c->company, [
                    'name' => $name,
                    'email' => 'ekonomi@'.str($name)->slug().'.test',
                    'payment_terms_days' => 30,
                ]);
                $this->agreement($c, $customer, $price, $months, $date($from));
            }

            $this->reprice($c, $this->of('Molnkontoret Exempel AB'), '4990', $date('04-01'));
            $this->status($c, $this->of('Fiktiva Fastigheter AB'), AgreementStatus::Paused, $date('05-01'));
            $this->status($c, $this->of('Fiktiva Fastigheter AB'), AgreementStatus::Active, $date('08-01'));
            $this->reprice($c, $this->of('Byggplan Demo AB'), '3990', $date('07-01'));
            $this->status($c, $this->of('Exempelskolan i Teststad AB'), AgreementStatus::Ended, $date('10-01'));
            // Notice on the annual plan: it runs to the end of the paid year.
            $this->status($c, $this->of('Ritverket Exempel AB'), AgreementStatus::Ended, ($year + 1).'-01-01');

            $ledger = app(SaasLedger::class);
            $ledger->recompute($c->company, $c->today);

            $current = Months::of($c->today->toDateString());

            foreach (Months::range("{$year}-01-01", Months::add($current, -3)) as $month) {
                app(CloseSaasMonth::class)($c->user, $c->company, substr($month, 0, 7), $c->today);
            }

            // Registered now, effective in a closed month: a correction in the first open month.
            $this->reprice($c, $this->of('Påhittade Partner AB'), '8370', Months::add($current, -3));
            $ledger->recompute($c->company, $c->today);
        }));
    }

    /** The demo agreement of a customer, by the customer's made-up name. */
    private function of(string $customer): RecurringInvoice
    {
        return RecurringInvoice::query()->whereHas('customer', fn ($query) => $query->where('name', $customer))->firstOrFail();
    }

    private function agreement(DemoContext $c, Customer $customer, string $price, int $months, string $from): RecurringInvoice
    {
        return app(SaveRecurringInvoice::class)($c->user, $c->company, [
            'customer_id' => $customer->id,
            'name' => $months === 12 ? 'Tjänsten, årsabonnemang' : 'Tjänsten, abonnemang',
            'interval_months' => $months,
            'next_invoice_date' => Months::add(Months::of($c->today->toDateString()), 1),
            'send_automatically' => false,
            'rows' => [['description' => 'Abonnemang på tjänsten', 'quantity' => '1', 'unit' => 'st', 'price' => $price, 'vat_rate' => 2500]],
            'effective_from' => $from,
        ]);
    }

    private function reprice(DemoContext $c, RecurringInvoice $agreement, string $price, string $from): void
    {
        $agreement = RecurringInvoice::query()->findOrFail($agreement->id);

        app(SaveRecurringInvoice::class)($c->user, $c->company, [
            'customer_id' => $agreement->customer_id,
            'name' => $agreement->name,
            'interval_months' => $agreement->interval_months,
            'next_invoice_date' => $agreement->next_invoice_date->toDateString(),
            'send_automatically' => false,
            'rows' => [['description' => 'Abonnemang på tjänsten', 'quantity' => '1', 'unit' => 'st', 'price' => $price, 'vat_rate' => 2500]],
            'effective_from' => $from,
        ], $agreement);
    }

    private function status(DemoContext $c, RecurringInvoice $agreement, AgreementStatus $status, string $from): void
    {
        app(RunRecurringInvoice::class)->setStatus($c->user, RecurringInvoice::query()->findOrFail($agreement->id), $status, $from);
    }
}
