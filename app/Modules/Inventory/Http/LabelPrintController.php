<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http;

use App\Models\Article;
use App\Models\User;
use App\Modules\Inventory\Actions\LabelTemplates;
use App\Modules\Inventory\Actions\PrintLabels;
use App\Modules\Inventory\Labels\LabelData;
use App\Modules\Inventory\Labels\LabelKind;
use App\Modules\Inventory\Labels\LabelRenderer;
use App\Modules\Inventory\Models\LabelTemplate;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Inventory\Models\StockMovement;
use App\Support\Branding\Brand;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Label PDFs (ADR-041). print() takes the selection as a POST (a long list
 * of ids never ends up in a URL) and streams the PDF; sample() prints a test
 * page of a template for the studio with a few of the company's own
 * articles or locations, or example data. A template, article or location of
 * another company is not found (the company scope).
 */
final class LabelPrintController
{
    public function print(Request $request, PrintLabels $print): Response
    {
        /** @var User $user */
        $user = $request->user();
        $company = $user->currentCompany();
        abort_if($company === null, 404);

        $validated = $request->validate([
            'template_id' => ['required', 'integer'],
            'kind' => ['required', 'in:articles,locations'],
            'copies' => ['array'],
            'copies.*' => ['integer', 'min:0', 'max:'.PrintLabels::MAX_COPIES],
            'ids' => ['array'],
            'ids.*' => ['integer'],
            'start' => ['nullable', 'integer', 'min:1', 'max:800'],
            'mark_printed' => ['nullable', 'boolean'],
        ]);

        $template = LabelTemplate::query()->findOrFail((int) $validated['template_id']);
        $start = (int) ($validated['start'] ?? 1);

        $result = $validated['kind'] === 'articles'
            ? $print->articles($user, $company, $template, (array) ($validated['copies'] ?? []), $start, (bool) ($validated['mark_printed'] ?? false))
            : $print->locations($user, $company, $template, array_values(array_map('intval', (array) ($validated['ids'] ?? []))), $start);

        return $this->pdf($result['pdf'], 'etiketter-'.CarbonImmutable::now()->format('Ymd-Hi').'.pdf');
    }

    public function sample(Request $request, int $template): Response
    {
        /** @var User $user */
        $user = $request->user();
        $company = $user->currentCompany();
        abort_unless($company !== null && Gate::forUser($user)->allows('settings', [StockMovement::class, $company]), 403);
        app(LabelTemplates::class)->ensureDefaults($company, $user);

        $labelTemplate = LabelTemplate::query()->findOrFail($template);
        $withPrices = Gate::forUser($user)->allows('printPrices', [StockMovement::class, $company]);

        $labels = $labelTemplate->kind === LabelKind::Location
            ? StockLocation::query()->where('is_active', true)->orderBy('sort_key')->limit(3)->get()->map(fn (StockLocation $location): LabelData => LabelData::forLocation($location))->all()
            : Article::query()->where('is_active', true)->orderByDesc('is_stock_item')->orderBy('number')->limit(3)->get()->map(fn (Article $article): LabelData => LabelData::forArticle($article, $withPrices, $labelTemplate->property_keys ?? []))->all();

        if ($labels === []) {
            $labels = [LabelData::sample($labelTemplate->kind)];
        }

        $logo = Brand::for($company)->logoDataUri();
        $result = app(LabelRenderer::class)->pdf($labelTemplate, array_values($labels), 1, $logo);

        return $this->pdf($result['pdf'], 'provsida-etiketter.pdf');
    }

    private function pdf(string $pdf, string $filename): Response
    {
        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
            'Cache-Control' => 'no-store',
        ]);
    }
}
