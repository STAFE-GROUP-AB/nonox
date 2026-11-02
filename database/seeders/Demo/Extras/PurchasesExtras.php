<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Extras;

use App\Actions\Payables\ApprovePaymentBatch;
use App\Actions\Payables\ApproveSupplierInvoice;
use App\Actions\Payables\CreatePaymentBatch;
use App\Actions\Payables\OffsetSupplierCredit;
use App\Actions\Payables\PaySupplierInvoice;
use App\Actions\Payables\RegisterSupplierInvoice;
use App\Actions\Payables\SavePaymentBankSettings;
use App\Actions\Payables\SaveSupplier;
use App\Actions\Payables\SaveSupplierInvoice;
use App\Domain\Payables\SupplierInvoiceStatus;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoExtra;
use Illuminate\Validation\ValidationException;

/**
 * The supplier pages of the manual, in the consultancy: a purchase of
 * services from a German supplier in euro (the buyer accounts for the VAT),
 * paid at another rate than it was booked at; extra licences and a credit
 * note for part of them, offset against each other; and an approved payment
 * file for the approved invoices still to pay. The supplier, its VAT number
 * (the standard example number) and its IBAN are made up.
 */
final class PurchasesExtras extends DemoExtra
{
    public function industries(): array
    {
        return ['bokforing-for-konsulter'];
    }

    public function schedule(DemoContext $c): void
    {
        $german = app(SaveSupplier::class)($c->user, $c->company, [
            'name' => 'Exempel Software GmbH',
            'vat_number' => 'DE123456789',
            'address_line1' => 'Beispielstraße 1',
            'postal_code' => '10115',
            'city' => 'Berlin',
            'iban' => 'DE89370400440532013000',
            'bic' => 'COBADEFFXXX',
            'payment_terms_days' => 30,
            'default_account' => 5420,
        ]);

        // 1 450,00 EUR at 11,0250 kr is 15 986,25 kr; the bank took 16 020,50 kr when it was paid.
        $bought = CarbonImmutable::parse('2026-10-05');
        $c->on($bought, 30, function () use ($c, $german, $bought): void {
            $invoice = $this->register($c, [
                'supplier_id' => $german->id,
                'invoice_number' => 'RE-2026-4471',
                'invoice_date' => $bought->toDateString(),
                'description' => 'Projektverktyg, årslicens',
                'total' => '15986.25',
                'vat' => '0',
                'vat_treatment' => 'EU_SERVICES',
                'currency' => 'EUR',
                'foreign_total' => '1450.00',
                'exchange_rate' => '11.0250',
                'rows' => [['amount' => '15986.25', 'vat_rate' => 2500, 'description' => 'Årslicens, 5 användare', 'project' => 'P-101']],
            ]);

            $paidOn = CarbonImmutable::parse('2026-10-26');
            $c->on($paidOn, 50, fn () => app(PaySupplierInvoice::class)($c->user, $invoice->fresh() ?? $invoice, $paidOn, Money::ofOre(1_602_050)));
        });

        // Five extra licences, of which two were cancelled: a credit note offset against the invoice.
        $licences = CarbonImmutable::parse('2026-10-12');
        $c->on($licences, 30, function () use ($c, $licences): void {
            $supplier = Supplier::query()->where('name', 'Molntjänster Demo AB')->firstOrFail();
            $invoice = $this->register($c, [
                'supplier_id' => $supplier->id,
                'invoice_number' => '88123',
                'invoice_date' => $licences->toDateString(),
                'description' => 'Extra licenser, 5 st',
                'total' => '3000.00',
                'vat' => '600.00',
                'vat_treatment' => 'DOMESTIC',
                'rows' => [['account' => '5420', 'amount' => '2400.00', 'description' => 'Licenser oktober–december', 'cost_center' => 'RADG']],
            ]);

            $credited = $licences->addWeek();
            $c->on($credited, 30, function () use ($c, $supplier, $invoice, $credited): void {
                $credit = $this->register($c, [
                    'supplier_id' => $supplier->id,
                    'invoice_number' => 'K-88123',
                    'invoice_date' => $credited->toDateString(),
                    'description' => 'Kredit, 2 licenser avslutade',
                    'total' => '1200.00',
                    'vat' => '240.00',
                    'credit' => true,
                    'vat_treatment' => 'DOMESTIC',
                    'rows' => [['account' => '5420', 'amount' => '960.00', 'description' => 'Licenser oktober–december', 'cost_center' => 'RADG']],
                ]);

                app(OffsetSupplierCredit::class)($c->user, $credit, $invoice->fresh() ?? $invoice, $credited);
            });
        });
    }

    public function afterAll(DemoContext $c): void
    {
        try {
            app(SavePaymentBankSettings::class)($c->user, $c->company, [
                'payment_bank' => 'SEB',
                'iban' => 'SE3550000000054910000003',
                'bank_agreement_id' => 'DEMO-AVTAL-1',
            ]);

            $ids = array_values(SupplierInvoice::query()
                ->where('kind', SupplierInvoice::INVOICE)
                ->where('status', SupplierInvoiceStatus::Approved->value)
                ->where('invoice_currency', 'SEK')
                ->orderBy('due_date')
                ->limit(4)
                ->pluck('id')
                ->map(fn (mixed $id): string => (string) $id)
                ->all());

            $batch = app(CreatePaymentBatch::class)($c->user, $c->company, $ids, $c->today->addDay());
            app(ApprovePaymentBatch::class)($c->user, $batch);
        } catch (ValidationException $exception) {
            $c->warn('betalfil: '.collect($exception->errors())->flatten()->implode(' '));
        }
    }

    /** @param  array<string, mixed>  $input */
    private function register(DemoContext $c, array $input): SupplierInvoice
    {
        $draft = app(SaveSupplierInvoice::class)($c->user, $c->company, $input);

        return app(ApproveSupplierInvoice::class)($c->user, app(RegisterSupplierInvoice::class)($c->user, $draft));
    }
}
