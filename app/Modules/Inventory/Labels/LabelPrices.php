<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Labels;

use App\Domain\Pricing\ChannelPrices;
use App\Models\Article;
use App\Modules\ModuleManager;
use App\Modules\Pos\Domain\ConnectionStatus;
use App\Modules\Pos\Models\PosConnection;
use App\Modules\Pos\PosModule;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Collection;

/**
 * The price on a shelf label is the till's price (ADR-082 §10): the price
 * engine's for the POS channel today, from the CHANNEL list the company's
 * till names when there is one. Must run inside the company. Articles the
 * engine gives no price are left out and keep their base price on the label.
 *
 * TODO(legal-verify): P-L2 — a label does not show "ord. pris" or the lowest
 * price of the last 30 days for a campaign price (see ChannelPrices).
 */
final class LabelPrices
{
    /**
     * @param  iterable<Article>  $articles
     * @return array<int, int> gross price in öre by article id
     */
    public static function gross(iterable $articles): array
    {
        $articles = Collection::make($articles);

        if ($articles->isEmpty()) {
            return [];
        }

        return app(ChannelPrices::class)->gross($articles, self::channelListId());
    }

    private static function channelListId(): ?int
    {
        $companyId = (int) CurrentCompany::id();

        if (! app(ModuleManager::class)->isEnabled(PosModule::KEY, $companyId)) {
            return null;
        }

        foreach (PosConnection::query()->where('status', ConnectionStatus::Active->value)->orderBy('id')->get() as $connection) {
            if ($connection->priceListId() !== null) {
                return $connection->priceListId();
            }
        }

        return null;
    }
}
