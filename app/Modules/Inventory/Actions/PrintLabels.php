<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Models\Article;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Actions\Concerns\GuardsInventory;
use App\Modules\Inventory\Labels\LabelData;
use App\Modules\Inventory\Labels\LabelElement;
use App\Modules\Inventory\Labels\LabelKind;
use App\Modules\Inventory\Labels\LabelRenderer;
use App\Modules\Inventory\Models\LabelTemplate;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Inventory\Models\StockMovement;
use App\Support\Audit\Audit;
use App\Support\Branding\Brand;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Prints labels as a PDF (ADR-041). Needs inventory:view and handling goods
 * (inventory:count or inventory:manage). Prices are printed only for those
 * who manage articles; anyone else gets article labels without price, VAT
 * note and comparison price. Article labels can mark the articles as
 * printed (labels_printed_at and labels_printed_count, without touching
 * updated_at). At most 500 copies per article and 5 000 labels per PDF.
 */
class PrintLabels
{
    use GuardsInventory;

    public const int MAX_COPIES = 500;

    /**
     * @param  array<int|string, int|string>  $copies  article id => number of labels, in print order
     * @return array{pdf: string, pages: int, labels: int}
     */
    public function articles(User $user, Company $company, LabelTemplate $template, array $copies, int $startAt = 1, bool $markPrinted = true): array
    {
        $this->guard($user, $company, 'printLabels');
        $this->checkKind($template, LabelKind::Article);
        $counts = $this->counts($copies);

        return CurrentCompany::run($company, function () use ($user, $company, $template, $counts, $startAt, $markPrinted): array {
            $articles = Article::query()->whereKey(array_keys($counts))->get()->keyBy('id');

            if ($articles->count() !== count($counts)) {
                abort(404);
            }

            $withPrices = Gate::forUser($user)->allows('printPrices', [StockMovement::class, $company]);
            $labels = [];

            foreach ($counts as $id => $count) {
                /** @var Article $article */
                $article = $articles[$id];
                $data = LabelData::forArticle($article, $withPrices, $template->property_keys ?? []);
                array_push($labels, ...array_fill(0, $count, $data));
            }

            $result = app(LabelRenderer::class)->pdf($template, $labels, $startAt, $this->logo($company, $template));

            DB::transaction(function () use ($markPrinted, $counts, $user, $company, $template, $withPrices, $labels): void {
                if ($markPrinted) {
                    $this->markPrinted($counts);
                }

                Audit::record('inventory.labels_printed', $template, ['kind' => LabelKind::Article->value, 'template' => $template->name, 'labels' => count($labels), 'articles' => array_keys($counts), 'prices' => $withPrices, 'marked' => $markPrinted], $company, $user);
            });

            return [...$result, 'labels' => count($labels)];
        });
    }

    /**
     * @param  list<int>  $locationIds  one label each, printed in walking order (sort_key)
     * @return array{pdf: string, pages: int, labels: int}
     */
    public function locations(User $user, Company $company, LabelTemplate $template, array $locationIds, int $startAt = 1): array
    {
        $this->guard($user, $company, 'printLabels');
        $this->checkKind($template, LabelKind::Location);
        $ids = array_values(array_unique(array_map('intval', $locationIds)));

        if ($ids === []) {
            throw ValidationException::withMessages(['ids' => __('Välj minst ett lagerställe.')]);
        }

        if (count($ids) > LabelRenderer::MAX_LABELS) {
            throw ValidationException::withMessages(['ids' => __('Högst :max etiketter går att skriva ut på en gång.', ['max' => LabelRenderer::MAX_LABELS])]);
        }

        return CurrentCompany::run($company, function () use ($user, $company, $template, $ids, $startAt): array {
            $locations = StockLocation::query()->whereKey($ids)->orderBy('sort_key')->get();

            if ($locations->count() !== count($ids)) {
                abort(404);
            }

            $labels = array_values($locations->map(fn (StockLocation $location): LabelData => LabelData::forLocation($location))->all());
            $result = app(LabelRenderer::class)->pdf($template, $labels, $startAt, $this->logo($company, $template));
            Audit::record('inventory.labels_printed', $template, ['kind' => LabelKind::Location->value, 'template' => $template->name, 'labels' => count($labels), 'locations' => $ids], $company, $user);

            return [...$result, 'labels' => count($labels)];
        });
    }

    /**
     * @param  array<int|string, int|string>  $copies
     * @return array<int, int>
     */
    private function counts(array $copies): array
    {
        $counts = [];

        foreach ($copies as $id => $count) {
            $count = (int) $count;

            if ((int) $id > 0 && $count > 0) {
                $counts[(int) $id] = min(self::MAX_COPIES, $count);
            }
        }

        if ($counts === []) {
            throw ValidationException::withMessages(['ids' => __('Välj minst en artikel att skriva ut etiketter för.')]);
        }

        if (array_sum($counts) > LabelRenderer::MAX_LABELS) {
            throw ValidationException::withMessages(['ids' => __('Det blir :count etiketter. Högst :max går att skriva ut på en gång; dela upp utskriften.', ['count' => array_sum($counts), 'max' => LabelRenderer::MAX_LABELS])]);
        }

        return $counts;
    }

    private function checkKind(LabelTemplate $template, LabelKind $kind): void
    {
        if ($template->kind !== $kind) {
            throw ValidationException::withMessages(['template' => $kind === LabelKind::Article ? __('Välj en mall för artikeletiketter.') : __('Välj en mall för platsetiketter.')]);
        }
    }

    private function logo(Company $company, LabelTemplate $template): ?string
    {
        return $template->shows(LabelElement::Logo) ? Brand::for($company)->logoDataUri() : null;
    }

    /**
     * Stamps the articles as printed with one statement per distinct count,
     * through the query builder so updated_at stays as it was.
     *
     * @param  array<int, int>  $counts
     */
    private function markPrinted(array $counts): void
    {
        $now = CarbonImmutable::now();
        $byCount = [];

        foreach ($counts as $id => $count) {
            $byCount[$count][] = $id;
        }

        foreach ($byCount as $count => $ids) {
            Article::query()->whereKey($ids)->toBase()->increment('labels_printed_count', $count, ['labels_printed_at' => $now]);
        }
    }
}
