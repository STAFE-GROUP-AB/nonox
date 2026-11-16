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
use App\Modules\Saas\Stripe\Models\StripeConnection;
use App\Modules\Saas\Stripe\Models\StripeSyncRun;
use App\Modules\Saas\Stripe\Support\StripeSync;
use App\Modules\Saas\Support\SaasLedger;
use App\Support\Connect\ConnectAddons;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoExtra;
use Illuminate\Support\Str;

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

            $this->stripe($c);

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

    /**
     * Stripe in NONOX Connect (ADR-080 step 2) with made-up data: the add-on is
     * on and a connection is shown as active, but it holds no key, so nothing
     * ever calls Stripe (the sync and the webhook need a key). The objects are
     * stored the way a sync stores them: one Stripe customer linked by its VAT
     * number, one suggested by e-mail, two not linked, and subscriptions that
     * start, have a trial, are yearly and end.
     */
    private function stripe(DemoContext $c): void
    {
        $year = $c->today->year;
        $at = fn (string $monthDay): int => CarbonImmutable::parse("{$year}-{$monthDay} 00:00:00", 'Europe/Stockholm')->getTimestamp();

        app(SaveCustomer::class)($c->user, $c->company, ['name' => 'Appstudion Exempel AB', 'org_number' => '556677-8899', 'vat_number' => 'SE556677889901', 'email' => 'ekonomi@appstudion-exempel-ab.test', 'payment_terms_days' => 30]);
        app(SaveCustomer::class)($c->user, $c->company, ['name' => 'Sagoboken Demo AB', 'email' => 'faktura@sagoboken-demo.test', 'payment_terms_days' => 30]);
        ConnectAddons::set($c->company, $c->user, ConnectAddons::STRIPE, true);

        $connection = StripeConnection::query()->create([
            'company_id' => $c->company->id,
            'status' => StripeConnection::ACTIVE,
            'account_id' => 'acct_1DemoExempel0000',
            'livemode' => true,
            'secret_key' => null,
            'key_last4' => 'DEMO',
            'webhook_token' => Str::random(48),
            'connected_by' => $c->user->id,
            'connected_at' => $c->today->setDate($year, 1, 3)->setTime(9, 12),
            'initial_import_done_at' => $c->today->setDate($year, 1, 3)->setTime(9, 14),
            'last_synced_at' => $c->today->setTime(8, 17),
        ]);

        $customer = fn (string $id, string $name, ?string $email, array $taxIds = [], string $country = 'SE'): array => [
            'id' => $id, 'object' => 'customer', 'name' => $name, 'email' => $email, 'metadata' => [],
            'address' => ['line1' => 'Exempelgatan 1', 'line2' => null, 'postal_code' => '111 22', 'city' => 'Teststad', 'state' => null, 'country' => $country],
            'tax_ids' => ['object' => 'list', 'data' => $taxIds],
        ];
        $subscription = fn (string $id, string $customer, int $amount, string $interval, string $start, array $extra = []): array => [
            'id' => $id, 'object' => 'subscription', 'customer' => $customer, 'currency' => 'sek', 'status' => 'active',
            'start_date' => $at($start), 'created' => $at($start), 'trial_end' => null, 'cancel_at' => null, 'cancel_at_period_end' => false,
            'canceled_at' => null, 'ended_at' => null, 'pause_collection' => null, 'discounts' => [],
            'items' => ['object' => 'list', 'data' => [[
                'id' => "si_{$id}", 'object' => 'subscription_item', 'quantity' => 1, 'discounts' => [],
                'current_period_end' => $at($start), 'current_period_start' => $at($start),
                'price' => ['id' => "price_{$id}", 'object' => 'price', 'currency' => 'sek', 'product' => 'prod_DemoAppen', 'billing_scheme' => 'per_unit', 'type' => 'recurring', 'unit_amount' => $amount, 'unit_amount_decimal' => (string) $amount, 'recurring' => ['interval' => $interval, 'interval_count' => 1, 'usage_type' => 'licensed']],
            ]]],
            ...$extra,
        ];

        $counts = app(StripeSync::class)->storeFetched($connection, [
            'products' => [['id' => 'prod_DemoAppen', 'object' => 'product', 'name' => 'Appen, abonnemang', 'active' => true]],
            'customers' => [
                $customer('cus_DemoAppstudion', 'Appstudion Exempel AB', 'ekonomi@appstudion-exempel-ab.test', [['type' => 'eu_vat', 'value' => 'SE556677889901', 'country' => 'SE']]),
                $customer('cus_DemoSagoboken', 'Sagoboken AB', 'faktura@sagoboken-demo.test'),
                $customer('cus_DemoKafferosteriet', 'Kafferosteriet Exempel', 'hej@kafferosteriet-exempel.test'),
                $customer('cus_DemoNordlys', 'Nordlys Eksempel AS', 'post@nordlys-eksempel.test', [], 'NO'),
            ],
            'subscriptions' => [
                $subscription('sub_DemoAppstudion', 'cus_DemoAppstudion', 129_000, 'month', '01-10'),
                $subscription('sub_DemoSagoboken', 'cus_DemoSagoboken', 990_000, 'year', '03-01'),
                $subscription('sub_DemoKafferosteriet', 'cus_DemoKafferosteriet', 49_000, 'month', '05-15', ['trial_end' => $at('06-15')]),
                $subscription('sub_DemoNordlys', 'cus_DemoNordlys', 69_000, 'month', '02-01', ['status' => 'canceled', 'ended_at' => $at('09-01')]),
            ],
        ]);

        StripeSyncRun::query()->create([
            'company_id' => $c->company->id,
            'connection_id' => $connection->id,
            'kind' => StripeSyncRun::INITIAL,
            'status' => StripeSyncRun::OK,
            'started_at' => $connection->connected_at,
            'finished_at' => $connection->initial_import_done_at,
            'requests' => 14,
            'counts' => $counts,
        ]);
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
