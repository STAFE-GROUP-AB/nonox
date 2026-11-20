<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Extras;

use App\Actions\Pricing\AssignCustomerPrices;
use App\Actions\Pricing\PlanPriceUpdate;
use App\Actions\Pricing\SaveCustomerPriceGroup;
use App\Actions\Pricing\SavePriceList;
use App\Actions\Pricing\SavePriceListItem;
use App\Models\Article;
use App\Models\Customer;
use App\Models\PriceList;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoExtra;

/**
 * Price lists, customer groups and a campaign (ADR-082 P4) for the manual's
 * pages: the joinery's price list for building companies that follows the
 * base price minus a percentage, a flat list for housing associations with a
 * quantity ladder and a coming price rise, a summer campaign and a list in
 * euro, and a price update of 4 % waiting in preview (P6). Made-up
 * articles, customers and prices; everything goes through the price Actions.
 */
final class PricesExtras extends DemoExtra
{
    public function industries(): array
    {
        return ['bokforing-for-hantverkare'];
    }

    public function afterAll(DemoContext $c): void
    {
        $this->atomically($c, 'Prislistor', fn () => CurrentCompany::run($c->company, function () use ($c): void {
            $today = CarbonImmutable::today();
            $saveList = app(SavePriceList::class);
            $saveItem = app(SavePriceListItem::class);
            $articles = Article::query()->whereIn('number', ['ARB', 'MAT', 'RES'])->pluck('id', 'number');

            if ($articles->count() < 3) {
                return;
            }

            $item = fn (PriceList $list, string $article, array $input): mixed => $saveItem($c->user, $c->company, $list, ['article_id' => $articles[$article], 'mode' => 'price', ...$input]);

            $building = $saveList($c->user, $c->company, [
                'code' => 'BYGG', 'name' => 'Byggföretag', 'kind' => 'STANDARD', 'currency' => 'SEK',
                'derived' => true, 'derived_percent' => '-10', 'rounding_rule' => 'WHOLE',
            ]);
            $item($building, 'ARB', ['price' => '560', 'min_quantity' => '40']);
            $item($building, 'ARB', ['price' => '530', 'min_quantity' => '100']);
            $item($building, 'MAT', ['mode' => 'discount', 'discount' => '8']);

            $associations = $saveList($c->user, $c->company, [
                'code' => 'BRF', 'name' => 'Bostadsrättsföreningar', 'kind' => 'STANDARD', 'currency' => 'SEK',
            ]);
            $nextJanuary = $today->addYear()->startOfYear();
            $item($associations, 'ARB', ['price' => '575', 'valid_to' => $nextJanuary->subDay()->toDateString()]);
            $item($associations, 'ARB', ['price' => '595', 'valid_from' => $nextJanuary->toDateString()]);
            $item($associations, 'RES', ['price' => '400']);

            $saveList($c->user, $c->company, [
                'code' => 'SOMMAR', 'name' => 'Sommarkampanj', 'kind' => 'CAMPAIGN', 'currency' => 'SEK',
                'valid_from' => $today->subDays(14)->toDateString(), 'valid_to' => $today->addDays(30)->toDateString(),
            ]);
            $campaign = PriceList::query()->where('code', 'SOMMAR')->firstOrFail();
            $item($campaign, 'RES', ['price' => '350']);

            $euro = $saveList($c->user, $c->company, [
                'code' => 'EUR', 'name' => 'Export i euro', 'kind' => 'STANDARD', 'currency' => 'EUR', 'is_default_for_currency' => true,
            ]);
            $item($euro, 'ARB', ['price' => '55,00']);
            $item($euro, 'MAT', ['price' => '215,00']);

            $saveGroup = app(SaveCustomerPriceGroup::class);
            $buildingGroup = $saveGroup($c->user, $c->company, ['code' => 'BYGG', 'name' => 'Byggföretag', 'price_list_id' => $building->id]);
            $associationGroup = $saveGroup($c->user, $c->company, ['code' => 'BRF', 'name' => 'Bostadsrättsföreningar', 'price_list_id' => $associations->id]);

            $assign = app(AssignCustomerPrices::class);

            foreach (['Byggbolaget Exempel AB' => $buildingGroup->id, 'Bostadsrättsföreningen Exemplet' => $associationGroup->id] as $name => $groupId) {
                $customer = Customer::query()->where('name', $name)->first();

                if ($customer !== null) {
                    $assign($c->user, $c->company, $customer, $groupId, null);
                }
            }

            // A price update waiting in preview (ADR-082 P6): 4 % from next January on the base prices and two
            // lists, rounded to whole kronor. Nothing is written, so the other price pages stay as they are.
            app(PlanPriceUpdate::class)($c->user, $c->company, [
                'kind' => 'PERCENT', 'percent' => '4', 'base' => true, 'list_ids' => [$building->id, $associations->id],
                'articles' => 'all', 'rounding' => 'WHOLE', 'effective_date' => $nextJanuary->toDateString(),
            ]);
        }));
    }
}
