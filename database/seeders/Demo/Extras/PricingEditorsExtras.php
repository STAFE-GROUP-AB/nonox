<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Extras;

use App\Actions\Invoicing\SaveDraftInvoice;
use App\Actions\Invoicing\SaveSalesDocument;
use App\Domain\Pricing\PriceAgreementStatus;
use App\Models\Article;
use App\Models\Customer;
use App\Models\PriceAgreement;
use App\Models\PriceAgreementItem;
use App\Models\SalesDocument;
use App\Support\Tenancy\CurrentCompany;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoExtra;

/**
 * Prices on the rows (ADR-082 P3) for the manual's invoice, quote and order
 * pages: in the accounting firm, a made-up price agreement with a client
 * (a monthly fee, payroll per employee with a quantity step from 5, and a
 * discount on advice), a draft invoice and a quote priced by it, and one row
 * priced by hand. The agreed monthly fee is then raised, so "Uppdatera
 * priser" on the draft has something to show. The screens for price lists
 * and agreements themselves come in their own packages (P4, P5).
 */
final class PricingEditorsExtras extends DemoExtra
{
    public const string INVOICE_REFERENCE = 'Månadsavtal 2026';

    public const string QUOTE_REFERENCE = 'Prisavtal, tillägg';

    public function industries(): array
    {
        return ['for-redovisningsbyraer'];
    }

    public function afterAll(DemoContext $c): void
    {
        $this->atomically($c, 'Priser på raderna', fn () => CurrentCompany::run($c->company, function () use ($c): void {
            $customer = Customer::query()->where('name', 'Bageriet Exempel AB')->first();
            $articles = Article::query()->whereIn('number', ['LB', 'LON', 'TIM'])->pluck('id', 'number');

            if (! $customer instanceof Customer || $articles->count() !== 3) {
                $c->warn('Priser på raderna: kunden eller artiklarna saknas.');

                return;
            }

            $agreement = PriceAgreement::create([
                'number' => (int) PriceAgreement::query()->max('number') + 1,
                'customer_id' => $customer->id,
                'title' => 'Ramavtal bokföring och lön',
                'currency' => 'SEK',
                'valid_from' => $c->today->startOfYear()->toDateString(),
                'status' => PriceAgreementStatus::Active,
                'created_by' => $c->user->id,
            ]);

            $monthly = PriceAgreementItem::create(['price_agreement_id' => $agreement->id, 'article_id' => $articles['LB'], 'min_quantity_milli' => 0, 'price_minor' => 490_000]);
            PriceAgreementItem::create(['price_agreement_id' => $agreement->id, 'article_id' => $articles['LON'], 'min_quantity_milli' => 0, 'price_minor' => 27_500]);
            PriceAgreementItem::create(['price_agreement_id' => $agreement->id, 'article_id' => $articles['LON'], 'min_quantity_milli' => 5_000, 'price_minor' => 24_500]);
            PriceAgreementItem::create(['price_agreement_id' => $agreement->id, 'article_id' => $articles['TIM'], 'min_quantity_milli' => 0, 'discount_bp' => 1_000]);

            $rows = [
                ['article' => 'LB', 'description' => 'Löpande bokföring, månadsarvode', 'quantity' => '1', 'vat_rate' => 2500],
                ['article' => 'LON', 'description' => 'Lönehantering per anställd', 'quantity' => '6', 'vat_rate' => 2500],
                ['article' => 'TIM', 'description' => 'Rådgivning', 'quantity' => '3', 'vat_rate' => 2500],
                ['article' => 'TIM', 'description' => 'Rådgivning, kvällstid', 'quantity' => '1', 'price' => '1 200', 'price_source' => 'MANUAL', 'vat_rate' => 2500],
            ];

            app(SaveDraftInvoice::class)($c->user, $c->company, [
                'customer_id' => $customer->id,
                'invoice_date' => $c->today->toDateString(),
                'your_reference' => self::INVOICE_REFERENCE,
                'rows' => $rows,
            ]);

            app(SaveSalesDocument::class)($c->user, $c->company, SalesDocument::QUOTE, [
                'customer_id' => $customer->id,
                'document_date' => $c->today->toDateString(),
                'until_date' => $c->today->addDays(30)->toDateString(),
                'your_reference' => self::QUOTE_REFERENCE,
                'rows' => array_slice($rows, 1, 2),
            ]);

            // The agreed fee goes up after the draft was written: the draft keeps its price until "Uppdatera priser".
            $monthly->forceFill(['price_minor' => 510_000])->save();
        }));
    }
}
