<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Extras;

use App\Actions\Pricing\ActivatePriceAgreement;
use App\Actions\Pricing\AssignCustomerPrices;
use App\Actions\Pricing\SaveCustomerPriceGroup;
use App\Actions\Pricing\SavePriceAgreement;
use App\Actions\Pricing\SavePriceAgreementItem;
use App\Actions\Pricing\SavePriceList;
use App\Actions\Pricing\SavePriceListItem;
use App\Actions\Pricing\SetPriceFloor;
use App\Domain\Pricing\PriceFloorPolicy;
use App\Models\Article;
use App\Models\Customer;
use App\Models\PriceList;
use App\Support\Tenancy\CurrentCompany;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoExtra;

/**
 * Price lists and customer agreements (ADR-082 P10) in the demo companies
 * that PricesExtras, PriceAgreementsExtras and PricingMarginExtras do not
 * cover (the joinery and the accounting firm have their own), so every
 * industry's demo shows the feature: a list for business customers that
 * follows the base price minus 10 % with a quantity step, a campaign running
 * now, a customer group with the first customer in it, an active price
 * agreement for that customer, and a lowest contribution margin that only
 * warns. Made-up customers and prices; everything goes through the price
 * Actions.
 */
final class PriceListsIndustryExtras extends DemoExtra
{
    /** Industry slug => the title of the agreement the demo customer has. */
    private const array AGREEMENTS = [
        'bokforing-for-restauranger' => 'Cateringavtal',
        'bokforing-for-cafeer' => 'Leveransavtal fika',
        'bokforing-for-butiker' => 'Inredningsavtal hotell',
        'bokforing-for-frisorer' => 'Avtal föreställningar',
        'bokforing-for-tillverkande-foretag' => 'Ramavtal inredning',
        'bokforing-for-konsulter' => 'Ramavtal konsulttid',
        'for-ekonomikonsulter' => 'Ramavtal löpande uppdrag',
    ];

    public function industries(): array
    {
        return array_keys(self::AGREEMENTS);
    }

    public function afterAll(DemoContext $c): void
    {
        $this->atomically($c, 'Prislistor och prisavtal', fn () => CurrentCompany::run($c->company, function () use ($c): void {
            $articles = Article::query()->orderBy('id')->limit(2)->get();
            $customer = Customer::query()->orderBy('id')->first();

            if ($articles->isEmpty() || ! $customer instanceof Customer) {
                $c->warn('Prislistor och prisavtal: artiklar eller kunder saknas.');

                return;
            }

            $first = $articles->first();
            $second = $articles->get(1) ?? $first;
            $today = $c->today;
            $kronor = fn (Article $article, int $percent): string => number_format(intdiv($article->price_ore->ore * $percent, 100) / 100, 2, ',', '');

            $saveList = app(SavePriceList::class);
            $saveItem = app(SavePriceListItem::class);

            $business = $saveList($c->user, $c->company, [
                'code' => 'FORETAG', 'name' => 'Företagskunder', 'kind' => 'STANDARD', 'currency' => 'SEK',
                'derived' => true, 'derived_percent' => '-10', 'rounding_rule' => 'WHOLE',
            ]);
            $saveItem($c->user, $c->company, $business, ['article_id' => $first->id, 'mode' => 'price', 'price' => $kronor($first, 85), 'min_quantity' => '10']);

            $campaign = $saveList($c->user, $c->company, [
                'code' => 'KAMPANJ', 'name' => 'Månadens kampanj', 'kind' => 'CAMPAIGN', 'currency' => 'SEK',
                'valid_from' => $today->subDays(7)->toDateString(), 'valid_to' => $today->addDays(21)->toDateString(),
            ]);
            $saveItem($c->user, $c->company, PriceList::query()->findOrFail($campaign->id), ['article_id' => $second->id, 'mode' => 'price', 'price' => $kronor($second, 90)]);

            $group = app(SaveCustomerPriceGroup::class)($c->user, $c->company, ['code' => 'FORETAG', 'name' => 'Företagskunder', 'price_list_id' => $business->id]);
            app(AssignCustomerPrices::class)($c->user, $c->company, $customer, $group->id, null);

            $agreement = app(SavePriceAgreement::class)($c->user, $c->company, [
                'customer_id' => $customer->id,
                'title' => self::AGREEMENTS[$c->slug] ?? 'Prisavtal',
                'currency' => 'SEK',
                'valid_from' => $today->subDays(30)->toDateString(),
                'valid_to' => $today->addMonths(11)->toDateString(),
            ]);
            app(SavePriceAgreementItem::class)($c->user, $c->company, $agreement, ['target' => 'article', 'article_id' => $first->id, 'mode' => 'discount', 'discount' => '8']);
            app(ActivatePriceAgreement::class)($c->user, $c->company, $agreement, 'Påhittat demoavtal, underskrivet på papper.');

            // A margin floor that only warns: small companies are never stopped by it (decision 12).
            app(SetPriceFloor::class)($c->user, $c->company, '10', PriceFloorPolicy::Warn);
        }));
    }
}
