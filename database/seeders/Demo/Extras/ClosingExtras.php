<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Extras;

use App\Actions\Accounting\CreateAccount;
use App\Actions\Accruals\Accruals;
use App\Actions\Assets\FixedAssets;
use App\Actions\Payables\ApproveSupplierInvoice;
use App\Actions\Payables\PaySupplierInvoice;
use App\Actions\Payables\RegisterSupplierInvoice;
use App\Actions\Payables\SaveSupplier;
use App\Actions\Payables\SaveSupplierInvoice;
use App\Models\FixedAsset;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoExtra;
use Database\Seeders\Demo\DemoIndustries;

/**
 * The closing pages of the manual, in the consultancy: a printer and a
 * laptop bought on supplier invoices that create fixed assets, the
 * meeting room's furniture bought on three invoices that build one asset
 * (taken into use in May, the last invoice after depreciation started),
 * their monthly depreciation, the printer scrapped in October, and a year's
 * insurance paid in January and spread over the year as an accrual.
 */
final class ClosingExtras extends DemoExtra
{
    public function industries(): array
    {
        return ['bokforing-for-konsulter'];
    }

    public function schedule(DemoContext $c): void
    {
        app(FixedAssets::class)->addAccounts($c->user, $c->company);
        app(CreateAccount::class)($c->user, $c->company, ['number' => 1730, 'name' => 'Förutbetalda försäkringspremier']);

        $computers = app(SaveSupplier::class)($c->user, $c->company, [
            'name' => 'Datorbutiken Demo AB', 'org_number' => DemoIndustries::orgNumber('72'), 'bankgiro' => '5050-1055', 'payment_terms_days' => 30, 'default_account' => 5410,
        ]);
        $insurer = app(SaveSupplier::class)($c->user, $c->company, [
            'name' => 'Försäkringsbolaget Demo AB', 'org_number' => DemoIndustries::orgNumber('65', '516'), 'bankgiro' => '5050-1055', 'payment_terms_days' => 30, 'default_account' => 6310,
        ]);

        $this->purchase($c, $insurer, CarbonImmutable::parse('2026-01-08'), 'F-2026-0113', 'Företagsförsäkring 2026', 960_000, 0, [
            ['account' => '1730', 'amount' => '9600.00', 'description' => 'Premie 2026-01-01 – 2026-12-31'],
        ]);

        app(Accruals::class)->save($c->user, $c->company, [
            'name' => 'Företagsförsäkring 2026',
            'kind' => 'PREPAID_EXPENSE',
            'total' => '9600',
            'balance_account' => 1730,
            'result_account' => 6310,
            'start_month' => '2026-01',
            'months' => 12,
            'move_from_result' => false,
            'notes' => 'Årspremien betalades i januari och kostnadsförs med en tolftedel per månad.',
        ]);

        $this->purchase($c, $computers, CarbonImmutable::parse('2026-01-20'), '40017', 'Skrivare och skanner', 1_750_000, 350_000, [
            ['account' => '1220', 'amount' => '14000.00', 'description' => 'Multifunktionsskrivare', 'asset' => ['name' => 'Skrivare och skanner', 'category' => 'EQUIPMENT', 'life_months' => 60]],
        ]);

        $this->purchase($c, $computers, CarbonImmutable::parse('2026-02-16'), '40188', 'Bärbar dator', 3_000_000, 600_000, [
            ['account' => '1250', 'amount' => '24000.00', 'description' => 'Bärbar dator 14 tum', 'asset' => ['name' => 'Bärbar dator, Anna', 'category' => 'COMPUTERS', 'life_months' => 36]],
        ]);

        $furniture = app(SaveSupplier::class)($c->user, $c->company, [
            'name' => 'Kontorsinredning Demo AB', 'org_number' => DemoIndustries::orgNumber('73'), 'bankgiro' => '5050-1055', 'payment_terms_days' => 30, 'default_account' => 5410,
        ]);

        $this->purchase($c, $furniture, CarbonImmutable::parse('2026-03-10'), '9031', 'Mötesrum, bord och stolar', 3_750_000, 750_000, [
            ['account' => '1220', 'amount' => '30000.00', 'description' => 'Konferensbord och tolv stolar', 'asset' => ['name' => 'Inredning mötesrummet', 'category' => 'EQUIPMENT', 'life_months' => 60, 'depreciation_start' => '2026-05']],
        ]);

        $this->purchase($c, $furniture, CarbonImmutable::parse('2026-04-14'), '9057', 'Mötesrum, akustikpaneler', 1_500_000, 300_000, [
            ['account' => '1220', 'amount' => '12000.00', 'description' => 'Akustikpaneler', 'asset' => ['add_to_name' => 'Inredning mötesrummet']],
        ]);

        $this->purchase($c, $furniture, CarbonImmutable::parse('2026-08-18'), '9112', 'Mötesrum, skärm', 750_000, 150_000, [
            ['account' => '1220', 'amount' => '6000.00', 'description' => 'Väggskärm 75 tum', 'asset' => ['add_to_name' => 'Inredning mötesrummet']],
        ]);

        for ($month = $c->start; $month <= $c->end; $month = $month->addMonthNoOverflow()) {
            $last = $month->endOfMonth()->startOfDay();
            $period = $month->format('Y-m');

            if ($last > $c->end) {
                continue;
            }

            $c->on($last, 80, function () use ($c, $period): void {
                app(Accruals::class)->run($c->user, $c->company, $period);
                app(FixedAssets::class)->depreciate($c->user, $c->company, $period);
            });
        }

        // The printer broke in October and was thrown away: scrapped, no price.
        $scrapped = CarbonImmutable::parse('2026-10-15');
        $c->on($scrapped, 80, function () use ($c, $scrapped): void {
            $printer = FixedAsset::query()->where('name', 'Skrivare och skanner')->firstOrFail();
            app(FixedAssets::class)->dispose($c->user, $printer, $scrapped->toDateString(), '0');
        });
    }

    /**
     * A supplier invoice registered, approved and paid on its due date, the
     * way the demo books every purchase; rows may carry an asset, or
     * add_to_name: the name of the asset the row is added to.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function purchase(DemoContext $c, Supplier $supplier, CarbonImmutable $date, string $number, string $description, int $total, int $vat, array $rows): void
    {
        $c->on($date, 30, function () use ($c, $supplier, $date, $number, $description, $total, $vat, $rows): void {
            // A row added to an asset made earlier: its id is known only once that purchase is booked.
            $rows = array_map(fn (array $row): array => isset($row['asset']['add_to_name'])
                ? [...$row, 'asset' => ['add_to' => (string) FixedAsset::query()->where('name', $row['asset']['add_to_name'])->value('id'), 'acquisition' => '']]
                : $row, $rows);

            $draft = app(SaveSupplierInvoice::class)($c->user, $c->company, [
                'supplier_id' => $supplier->id,
                'invoice_number' => $number,
                'invoice_date' => $date->toDateString(),
                'description' => $description,
                'total' => self::kronor($total),
                'vat' => self::kronor($vat),
                'vat_treatment' => 'DOMESTIC',
                'rows' => $rows,
                'with_assets' => true,
            ]);

            $invoice = app(ApproveSupplierInvoice::class)($c->user, app(RegisterSupplierInvoice::class)($c->user, $draft));
            $paidOn = CarbonImmutable::parse($invoice->due_date->toDateString());

            $c->on($paidOn, 50, function () use ($c, $invoice, $paidOn): void {
                $invoice = SupplierInvoice::query()->findOrFail($invoice->id);
                app(PaySupplierInvoice::class)($c->user, $invoice, $paidOn, $invoice->total_ore);
            });
        });
    }

    private static function kronor(int $ore): string
    {
        return sprintf('%d.%02d', intdiv($ore, 100), $ore % 100);
    }
}
