<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Extras;

use App\Actions\Invoicing\SaveDraftInvoice;
use App\Actions\Pricing\SetPriceFloor;
use App\Domain\Pricing\PriceFloorPolicy;
use App\Models\Article;
use App\Models\Customer;
use App\Support\Tenancy\CurrentCompany;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoExtra;

/**
 * Margin and floor warnings (ADR-082 P7) for the manual's invoice pages: in
 * the joinery, made-up purchase prices on labour and material, a lowest
 * contribution margin of 15 % that requires approval, and a draft invoice
 * with one row priced from the building companies' list, one typed price
 * under the floor and one typed price under cost. Made-up articles,
 * customers and prices.
 */
final class PricingMarginExtras extends DemoExtra
{
    public const string INVOICE_REFERENCE = 'Marginalvarning, kök';

    public function industries(): array
    {
        return ['bokforing-for-hantverkare'];
    }

    public function afterAll(DemoContext $c): void
    {
        $this->atomically($c, 'Marginal och lägsta täckningsgrad', fn () => CurrentCompany::run($c->company, function () use ($c): void {
            $customer = Customer::query()->where('name', 'Byggbolaget Exempel AB')->first();
            $articles = Article::query()->whereIn('number', ['ARB', 'MAT'])->get()->keyBy('number');
            $labour = $articles->get('ARB');
            $material = $articles->get('MAT');

            if (! $customer instanceof Customer || ! $labour instanceof Article || ! $material instanceof Article) {
                $c->warn('Marginal och lägsta täckningsgrad: kunden eller artiklarna saknas.');

                return;
            }

            // What labour and material cost the joinery: 380 kr an hour, 1 900 kr a piece.
            $labour->forceFill(['purchase_price_ore' => 38_000])->save();
            $material->forceFill(['purchase_price_ore' => 190_000])->save();

            app(SetPriceFloor::class)($c->user, $c->company, '15', PriceFloorPolicy::RequireApproval);

            app(SaveDraftInvoice::class)($c->user, $c->company, [
                'customer_id' => $customer->id,
                'invoice_date' => $c->today->toDateString(),
                'your_reference' => self::INVOICE_REFERENCE,
                'rows' => [
                    ['article' => 'ARB', 'description' => 'Arbete snickare', 'quantity' => '8', 'vat_rate' => 2500],
                    ['article' => 'MAT', 'description' => 'Material, köksluckor', 'quantity' => '3', 'price' => '2 100', 'price_source' => 'MANUAL', 'vat_rate' => 2500],
                    ['article' => 'ARB', 'description' => 'Arbete, extra hänsyn', 'quantity' => '1', 'price' => '350', 'price_source' => 'MANUAL', 'vat_rate' => 2500],
                ],
            ]);
        }));
    }
}
