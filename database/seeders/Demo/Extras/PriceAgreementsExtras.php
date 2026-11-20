<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Extras;

use App\Actions\Pricing\ActivatePriceAgreement;
use App\Actions\Pricing\SavePriceAgreement;
use App\Actions\Pricing\SavePriceAgreementItem;
use App\Models\Article;
use App\Models\Customer;
use App\Models\PriceAgreement;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoExtra;

/**
 * Price agreements (ADR-082 P5) for the manual's pages: in the joinery, an
 * active framework agreement with a building company (a fixed hourly price
 * with a step from 100 hours and a discount on material) that runs out in
 * three weeks, so Översikt reminds about it, and a draft for a housing
 * association that starts next year. Made-up customers and prices;
 * everything goes through the price agreement Actions.
 */
final class PriceAgreementsExtras extends DemoExtra
{
    public const string ACTIVE_TITLE = 'Ramavtal snickeri';

    public function industries(): array
    {
        return ['bokforing-for-hantverkare'];
    }

    public function afterAll(DemoContext $c): void
    {
        $this->atomically($c, 'Prisavtal', fn () => CurrentCompany::run($c->company, function () use ($c): void {
            $today = CarbonImmutable::today();
            $save = app(SavePriceAgreement::class);
            $term = app(SavePriceAgreementItem::class);
            $articles = Article::query()->whereIn('number', ['ARB', 'MAT', 'RES'])->pluck('id', 'number');
            $building = Customer::query()->where('name', 'Byggbolaget Exempel AB')->first();
            $association = Customer::query()->where('name', 'Bostadsrättsföreningen Exemplet')->first();

            if ($articles->count() < 3 || ! $building instanceof Customer || ! $association instanceof Customer) {
                $c->warn('Prisavtal: kunderna eller artiklarna saknas.');

                return;
            }

            $framework = $save($c->user, $c->company, [
                'customer_id' => $building->id, 'title' => self::ACTIVE_TITLE, 'currency' => 'SEK',
                'valid_from' => $today->subMonths(11)->startOfMonth()->toDateString(), 'valid_to' => $today->addDays(21)->toDateString(),
            ]);
            $term($c->user, $c->company, $framework, ['target' => 'article', 'article_id' => $articles['ARB'], 'mode' => 'price', 'price' => '520']);
            $term($c->user, $c->company, $framework, ['target' => 'article', 'article_id' => $articles['ARB'], 'mode' => 'price', 'price' => '495', 'min_quantity' => '100']);
            $term($c->user, $c->company, $framework, ['target' => 'article', 'article_id' => $articles['MAT'], 'mode' => 'discount', 'discount' => '12']);
            $this->activate($c, $framework);

            $service = $save($c->user, $c->company, [
                'customer_id' => $association->id, 'title' => 'Serviceavtal fastighet', 'currency' => 'SEK',
                'valid_from' => $today->addYear()->startOfYear()->toDateString(), 'allow_campaigns' => true,
            ]);
            $term($c->user, $c->company, $service, ['target' => 'article', 'article_id' => $articles['RES'], 'mode' => 'price', 'price' => '380']);
            $term($c->user, $c->company, $service, ['target' => 'all', 'mode' => 'discount', 'discount' => '5']);
        }));
    }

    /** The building company signed on paper: activated by hand, with the reason a demo has. */
    private function activate(DemoContext $c, PriceAgreement $agreement): void
    {
        app(ActivatePriceAgreement::class)($c->user, $c->company, $agreement, 'Påhittat demoavtal, underskrivet på papper.');
    }
}
