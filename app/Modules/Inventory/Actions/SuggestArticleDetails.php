<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Models\Article;
use App\Models\Company;
use App\Models\User;
use App\Modules\Ai\Actions\AnalyzeDocument;
use App\Modules\Ai\Support\AiAnalysisException;
use App\Modules\Inventory\Agents\ArticleAgent;
use App\Modules\Inventory\Domain\Gtin;
use App\Modules\Inventory\InventoryModule;
use App\Modules\ModuleManager;
use Illuminate\Support\Facades\Gate;

/**
 * "Fyll i med NONOX AI" on an article (ADR-039, ADR-018): the article's
 * photos go to the company's own AI provider, and the answer comes back as
 * a cleaned proposal for the form. Nothing is saved here: the person ticks
 * the fields to use and saves the article as usual. Prices and accounts are
 * never proposed, and a GTIN is kept only when its check digit is right.
 */
final class SuggestArticleDetails
{
    /** Photos sent per analysis; the first ones are the main pictures. */
    public const int MAX_PHOTOS = 4;

    public function __construct(private readonly AnalyzeDocument $analyze) {}

    /** Whether the button is shown: Lager and NONOX AI on, a key given, and the right to change articles and use AI. */
    public static function availableFor(User $user, Company $company): bool
    {
        return app(ModuleManager::class)->isEnabled(InventoryModule::KEY, $company)
            && AnalyzeDocument::availableFor($user, $company)
            && AnalyzeDocument::configuredFor($company)
            && Gate::forUser($user)->allows('create', [Article::class, $company]);
    }

    /**
     * @param  ?Article  $article  null for an article not saved yet
     * @param  list<string>  $jpegs  the photos, main picture first
     * @return array{name: string, description: string, brand: string, model: string, colours: list<string>, materials: list<string>, properties: list<array{key: string, value: string}>, weight_grams: ?int, length_mm: ?int, width_mm: ?int, height_mm: ?int, gtin: string, notes: string}
     *
     * @throws AiAnalysisException with a message for the person
     */
    public function __invoke(User $user, Company $company, ?Article $article, array $jpegs): array
    {
        app(ModuleManager::class)->ensureEnabled(InventoryModule::KEY, $company);

        if ($article instanceof Article) {
            abort_unless($article->company_id === $company->id, 404);
            Gate::forUser($user)->authorize('update', $article);
        } else {
            Gate::forUser($user)->authorize('create', [Article::class, $company]);
        }

        if ($jpegs === []) {
            throw new AiAnalysisException(__('Lägg till minst en bild på artikeln först, eller ta ett foto.'));
        }

        $answer = $this->analyze->images($user, $company, new ArticleAgent, 'article', array_slice($jpegs, 0, self::MAX_PHOTOS), 'Photos of one article follow. Propose its article register fields.');

        return self::clean($answer);
    }

    /**
     * @param  array<string, mixed>  $answer
     * @return array{name: string, description: string, brand: string, model: string, colours: list<string>, materials: list<string>, properties: list<array{key: string, value: string}>, weight_grams: ?int, length_mm: ?int, width_mm: ?int, height_mm: ?int, gtin: string, notes: string}
     */
    public static function clean(array $answer): array
    {
        $text = fn (string $key, int $max): string => mb_substr(trim((string) (is_scalar($answer[$key] ?? null) ? $answer[$key] : '')), 0, $max);
        $list = fn (string $key): array => array_values(array_unique(array_filter(array_map(
            fn (mixed $value): string => mb_substr(trim(is_scalar($value) ? (string) $value : ''), 0, 60),
            (array) ($answer[$key] ?? []),
        ), fn (string $value): bool => $value !== '')));
        $measure = function (string $key) use ($answer): ?int {
            $value = $answer[$key] ?? null;

            return is_numeric($value) && (int) $value > 0 && (int) $value < 10_000_000 ? (int) $value : null;
        };

        $properties = [];

        foreach ((array) ($answer['properties'] ?? []) as $property) {
            $property = (array) $property;
            $key = mb_substr(trim((string) (is_scalar($property['key'] ?? null) ? $property['key'] : '')), 0, 60);
            $value = mb_substr(trim((string) (is_scalar($property['value'] ?? null) ? $property['value'] : '')), 0, 255);

            if ($key !== '' && $value !== '') {
                $properties[] = ['key' => $key, 'value' => $value];
            }
        }

        $gtin = (string) preg_replace('/\D/', '', $text('gtin', 20));

        return [
            'name' => $text('name', 255),
            'description' => $text('description', 5000),
            'brand' => $text('brand', 100),
            'model' => $text('model', 100),
            'colours' => array_slice($list('colours'), 0, 10),
            'materials' => array_slice($list('materials'), 0, 10),
            'properties' => array_slice($properties, 0, 20),
            'weight_grams' => $measure('weight_grams'),
            'length_mm' => $measure('length_mm'),
            'width_mm' => $measure('width_mm'),
            'height_mm' => $measure('height_mm'),
            // A barcode read wrongly is worse than none: only a valid check digit is kept.
            'gtin' => Gtin::isValid($gtin) ? $gtin : '',
            'notes' => $text('notes', 1000),
        ];
    }
}
