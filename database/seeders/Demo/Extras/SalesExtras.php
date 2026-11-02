<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Extras;

use App\Actions\Invoicing\CreditInvoice;
use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\ProgressSalesDocument;
use App\Actions\Invoicing\RunRecurringInvoice;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Actions\Invoicing\SaveRecurringInvoice;
use App\Actions\Invoicing\SaveSalesDocument;
use App\Actions\Invoicing\SendReminder;
use App\Actions\Invoicing\SendSalesDocument;
use App\Domain\Invoicing\InvoiceStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\RecurringInvoice;
use App\Models\SalesDocument;
use Carbon\CarbonImmutable;
use Closure;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoExtra;
use Illuminate\Validation\ValidationException;

/**
 * The invoicing pages of the manual. In the consultancy: a sale of services
 * to a German company (no VAT, in the EU sales list), a monthly agreement,
 * quotes and an order, a credit invoice and a reminder for overdue invoices,
 * and a draft. In the shop: goods to a Danish company and a distance sale to
 * a consumer in Finland under OSS. Customers and VAT numbers are made up;
 * nothing is checked against VIES and every mail goes to the log.
 */
final class SalesExtras extends DemoExtra
{
    public function industries(): array
    {
        return ['bokforing-for-konsulter', 'bokforing-for-butiker'];
    }

    public function schedule(DemoContext $c): void
    {
        $c->slug === 'bokforing-for-konsulter'
            ? $this->consultancy($c)
            : $this->shop($c);
    }

    public function afterAll(DemoContext $c): void
    {
        if ($c->slug !== 'bokforing-for-konsulter') {
            return;
        }

        config(['mail.default' => 'log']);

        $overdue = Invoice::query()
            ->where('kind', Invoice::INVOICE)
            ->where('status', InvoiceStatus::Issued->value)
            ->where('paid_ore', 0)
            ->where('due_date', '<', $c->today->subWeeks(2)->toDateString())
            ->with('customer')
            ->orderBy('due_date')
            ->get();

        $this->attempt($c, function () use ($c, $overdue): void {
            $first = $overdue->first() ?? throw ValidationException::withMessages(['invoice' => 'Ingen förfallen faktura att kreditera.']);
            app(IssueInvoice::class)($c->user, app(CreditInvoice::class)($c->user, $first));
        });

        $this->attempt($c, function () use ($c, $overdue): void {
            $last = $overdue->count() > 1 ? $overdue->last() : null;
            $last ?? throw ValidationException::withMessages(['invoice' => 'Ingen förfallen faktura att påminna om.']);
            app(SendReminder::class)($c->user, $last, $last->customer->email ?? 'ekonomi@exempel.test');
        });

        $this->attempt($c, function () use ($c): void {
            $client = Customer::query()->where('name', 'Kunden Exempel AB')->firstOrFail();
            $software = Customer::query()->where('name', 'Mjukvaruhuset Exempel AB')->firstOrFail();
            $progress = app(ProgressSalesDocument::class);

            // A quote that was accepted, its order, and the order invoiced as a draft.
            $accepted = $this->document($c, SalesDocument::QUOTE, $software, '2026-10-06', 'Workshopserie, tre halvdagar', [
                ['article' => 'WS', 'description' => 'Workshop, halvdag', 'quantity' => '3', 'unit' => 'st', 'price' => '9500', 'vat_rate' => 2500],
                ['article' => 'KONS', 'description' => 'Förberedelser och dokumentation', 'quantity' => '12', 'unit' => 'tim', 'price' => '1150', 'vat_rate' => 2500],
            ]);
            $order = $progress->toOrder($c->user, $accepted);
            $progress->toInvoice($c->user, $order);

            // An order for later, and a quote sent and waiting for an answer.
            $this->document($c, SalesDocument::ORDER, $client, '2026-10-27', 'Uppföljning efter systembytet', [
                ['article' => 'KONS', 'description' => 'Konsulttjänster', 'quantity' => '24', 'unit' => 'tim', 'price' => '1150', 'vat_rate' => 2500],
            ]);
            $open = $this->document($c, SalesDocument::QUOTE, $client, '2026-10-29', 'Utbildning i det nya systemet', [
                ['article' => 'WS', 'description' => 'Workshop, halvdag', 'quantity' => '2', 'unit' => 'st', 'price' => '9500', 'vat_rate' => 2500],
            ]);
            app(SendSalesDocument::class)($c->user, $open, $client->email ?? 'faktura@kunden.test');
        });
    }

    private function consultancy(DemoContext $c): void
    {
        $german = app(SaveCustomer::class)($c->user, $c->company, [
            'name' => 'Exempel Beratung GmbH',
            'vat_number' => 'DE123456789',
            'email' => 'rechnung@beratung.test',
            'address_line1' => 'Beispielweg 5',
            'postal_code' => '20095',
            'city' => 'Hamburg',
            'country' => 'DE',
            'foreign_sale' => 'EU_SERVICES',
            'payment_terms_days' => 30,
        ]);

        $sold = CarbonImmutable::parse('2026-10-09');
        $c->on($sold, 30, fn () => $this->issue($c, $german, $sold, [
            ['article' => 'KONS', 'description' => 'Konsulttjänster, analys av ekonomiprocesser', 'quantity' => '22', 'unit' => 'tim', 'price' => '1150', 'vat_rate' => 2500],
        ]));

        // A monthly agreement: October's invoice was issued from it, November's waits as a draft.
        $client = Customer::query()->where('name', 'Kunden Exempel AB')->firstOrFail();
        $agreement = app(SaveRecurringInvoice::class)($c->user, $c->company, [
            'customer_id' => $client->id,
            'name' => 'Löpande rådgivning, månadsavtal',
            'interval_months' => 1,
            'next_invoice_date' => '2026-10-01',
            'send_automatically' => false,
            'our_reference' => $c->user->name,
            'notes' => 'Fast månadsarvode enligt avtal.',
            'rows' => [['article' => 'KONS', 'description' => 'Rådgivning enligt avtal, månadsarvode', 'quantity' => '8', 'unit' => 'tim', 'price' => '1150', 'vat_rate' => 2500]],
        ]);

        foreach (['2026-10-01' => true, '2026-11-01' => false] as $day => $issue) {
            $date = CarbonImmutable::parse($day);
            $c->on($date, 30, function () use ($c, $agreement, $date, $issue): void {
                $invoice = app(RunRecurringInvoice::class)($c->user, RecurringInvoice::query()->findOrFail($agreement->id), $date);

                if ($issue) {
                    app(IssueInvoice::class)($c->user, $invoice);
                }
            });
        }
    }

    private function shop(DemoContext $c): void
    {
        $danish = app(SaveCustomer::class)($c->user, $c->company, [
            'name' => 'Exempel Interiør ApS',
            'vat_number' => 'DK12345678',
            'address_line1' => 'Eksempelvej 3',
            'postal_code' => '2100',
            'city' => 'København',
            'country' => 'DK',
            'foreign_sale' => 'EU_GOODS',
            'payment_terms_days' => 30,
        ]);
        $finnish = app(SaveCustomer::class)($c->user, $c->company, [
            'name' => 'Matti Esimerkki',
            'address_line1' => 'Esimerkkikatu 7',
            'postal_code' => '00100',
            'city' => 'Helsingfors',
            'country' => 'FI',
            'foreign_sale' => 'EU_CONSUMER_OSS',
            'payment_terms_days' => 14,
        ]);

        $goods = CarbonImmutable::parse('2026-10-14');
        $c->on($goods, 30, fn () => $this->issue($c, $danish, $goods, [
            ['description' => 'Linnegardiner, 2-pack', 'quantity' => '12', 'unit' => 'st', 'price' => '640', 'vat_rate' => 2500],
            ['description' => 'Kuddfodral, ull', 'quantity' => '20', 'unit' => 'st', 'price' => '180', 'vat_rate' => 2500],
        ]));

        $distance = CarbonImmutable::parse('2026-10-21');
        $c->on($distance, 30, fn () => $this->issue($c, $finnish, $distance, [
            ['description' => 'Pläd i ull, postorder', 'quantity' => '2', 'unit' => 'st', 'price' => '720', 'vat_rate' => 2500],
        ]));
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function issue(DemoContext $c, Customer $customer, CarbonImmutable $date, array $rows): Invoice
    {
        $draft = app(SaveDraftInvoice::class)($c->user, $c->company, [
            'customer_id' => $customer->id,
            'invoice_date' => $date->toDateString(),
            'our_reference' => $c->user->name,
            'rows' => $rows,
        ]);

        return app(IssueInvoice::class)($c->user, $draft);
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function document(DemoContext $c, string $kind, Customer $customer, string $date, string $notes, array $rows): SalesDocument
    {
        return app(SaveSalesDocument::class)($c->user, $c->company, $kind, [
            'customer_id' => $customer->id,
            'document_date' => $date,
            'until_date' => CarbonImmutable::parse($date)->addDays(30)->toDateString(),
            'our_reference' => $c->user->name,
            'notes' => $notes,
            'rows' => $rows,
        ]);
    }

    private function attempt(DemoContext $c, Closure $step): void
    {
        try {
            $step();
        } catch (ValidationException $exception) {
            $c->warn(collect($exception->errors())->flatten()->implode(' '));
        }
    }
}
