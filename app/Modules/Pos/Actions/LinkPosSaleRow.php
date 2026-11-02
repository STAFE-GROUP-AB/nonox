<?php

declare(strict_types=1);

namespace App\Modules\Pos\Actions;

use App\Models\Article;
use App\Models\Company;
use App\Models\User;
use App\Modules\Pos\Models\PosConnection;
use App\Modules\Pos\Models\PosProductLink;
use App\Modules\Pos\Models\PosSale;
use App\Modules\Pos\Models\PosSaleRow;
use App\Modules\Pos\Support\PosGate;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A person says which article an unmatched till line is (ADR-042). The
 * till's product is linked to the article for the future (when it has an id
 * and is not linked yet), and the stock movement is posted for this line
 * and for every other waiting line of the same product. A line can also be
 * set aside: it then never moves stock.
 */
class LinkPosSaleRow
{
    public function __construct(private readonly RecordPosSale $record) {}

    /** @return int lines that moved stock */
    public function __invoke(User $user, Company $company, PosSaleRow $row, int $articleId): int
    {
        PosGate::link($user, $company);

        return CurrentCompany::run($company, fn (): int => DB::transaction(function () use ($user, $company, $row, $articleId): int {
            $row = PosSaleRow::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();

            if ($row->status !== PosSaleRow::UNMATCHED) {
                throw ValidationException::withMessages(['row' => __('Raden är redan hanterad.')]);
            }

            $article = Article::query()->whereKey($articleId)->where('is_active', true)->first()
                ?? throw ValidationException::withMessages(['article' => __('Välj en aktiv artikel.')]);

            if (! $article->is_stock_item) {
                throw ValidationException::withMessages(['article' => __('Artikeln :number är inte lagerförd. Markera den som lagerförd först.', ['number' => $article->number])]);
            }

            $sale = PosSale::query()->findOrFail($row->pos_sale_id);
            $connection = PosConnection::query()->findOrFail($sale->pos_connection_id);

            if ($row->external_product_id !== null
                && ! PosProductLink::query()->where('pos_connection_id', $connection->id)->where(fn ($query) => $query->where('external_product_id', $row->external_product_id)->orWhere('article_id', $article->id))->exists()) {
                PosProductLink::create([
                    'pos_connection_id' => $connection->id,
                    'article_id' => $article->id,
                    'external_product_id' => $row->external_product_id,
                    'external_variant_id' => $row->external_variant_id,
                ]);
            }

            $lines = PosSaleRow::query()
                ->where('status', PosSaleRow::UNMATCHED)
                ->where(fn ($query) => $query->whereKey($row->id)->when(
                    $row->external_product_id !== null,
                    fn ($same) => $same->orWhere(fn ($other) => $other->where('external_product_id', $row->external_product_id)->whereIn('pos_sale_id', PosSale::query()->where('pos_connection_id', $connection->id)->select('id'))),
                ))
                ->with('sale')
                ->orderBy('id')
                ->get();

            $posted = 0;

            foreach ($lines as $line) {
                $lineSale = $line->sale;

                if ($lineSale instanceof PosSale && $this->record->post($company, $connection, $lineSale, $line, $article)) {
                    $posted++;
                    $lineSale->forceFill([
                        'matched_count' => $lineSale->matched_count + 1,
                        'unmatched_count' => max(0, $lineSale->unmatched_count - 1),
                    ])->save();
                }
            }

            if ($posted === 0) {
                throw ValidationException::withMessages(['article' => $connection->location_id === null && $article->default_location_id === null
                    ? __('Artikeln har inget standardlagerställe och kassan inget lagerställe. Välj ett under Kassa eller på artikeln.')
                    : __('Det finns inte tillräckligt i lager för raden. Registrera inleveransen först, eller tillåt negativt lager.')]);
            }

            Audit::record('pos.row_linked', $row, ['article_id' => $article->id, 'lines' => $posted], $company, $user);

            return $posted;
        }));
    }

    public function ignore(User $user, Company $company, PosSaleRow $row): void
    {
        PosGate::link($user, $company);

        CurrentCompany::run($company, function () use ($user, $company, $row): void {
            $updated = PosSaleRow::query()->whereKey($row->id)->where('status', PosSaleRow::UNMATCHED)->update(['status' => PosSaleRow::SKIPPED, 'updated_at' => now()]);

            if ($updated === 0) {
                throw ValidationException::withMessages(['row' => __('Raden är redan hanterad.')]);
            }

            PosSale::query()->whereKey($row->pos_sale_id)->where('unmatched_count', '>', 0)->decrement('unmatched_count');
            Audit::record('pos.row_ignored', $row, [], $company, $user);
        });
    }
}
