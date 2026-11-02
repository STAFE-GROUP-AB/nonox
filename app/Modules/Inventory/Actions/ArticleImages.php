<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Models\Article;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\InventoryModule;
use App\Modules\Inventory\Models\ArticleImage;
use App\Modules\Inventory\Support\ArticlePhoto;
use App\Modules\ModuleManager;
use App\Support\Audit\Audit;
use App\Support\Files\StoredFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The pictures of an article (ADR-039): added from a file or straight from
 * the phone's camera, put in order (the first is the main image), given an
 * alt text, and removed. Whoever may change the article may change its
 * pictures; Lager must be on. Files are stored as resized JPEGs with
 * random names on the private, encrypted disk.
 */
final class ArticleImages
{
    public const int MAX_IMAGES = 12;

    public const int ALT_MAX = 255;

    /**
     * @throws ValidationException when the picture cannot be read or the article is full
     */
    public function add(User $user, Company $company, Article $article, UploadedFile $file, ?string $alt = null): ArticleImage
    {
        $this->authorize($user, $company, $article);

        $jpeg = ArticlePhoto::fromUpload($file);
        $path = 'inventory/articles/'.$company->id.'/'.basename('article-'.Str::uuid()->toString().'.jpg');

        Storage::disk(StoredFiles::DISK)->put($path, $jpeg);

        try {
            return $this->insert($user, $company, $article, $path, $alt);
        } catch (Throwable $exception) {
            Storage::disk(StoredFiles::DISK)->delete($path);

            throw $exception;
        }
    }

    private function insert(User $user, Company $company, Article $article, string $path, ?string $alt): ArticleImage
    {
        return DB::transaction(function () use ($user, $company, $article, $path, $alt): ArticleImage {
            // One upload at a time per article, so the limit and the positions hold.
            Article::query()->whereKey($article->id)->lockForUpdate()->first();
            $count = ArticleImage::query()->where('article_id', $article->id)->count();

            if ($count >= self::MAX_IMAGES) {
                throw ValidationException::withMessages(['photo' => __('En artikel kan ha högst :max bilder. Ta bort en bild först.', ['max' => self::MAX_IMAGES])]);
            }

            $image = ArticleImage::query()->create([
                'company_id' => $company->id,
                'article_id' => $article->id,
                'path' => $path,
                'position' => (int) ArticleImage::query()->where('article_id', $article->id)->max('position') + ($count === 0 ? 0 : 1),
                'alt' => self::cleanAlt($alt),
            ]);

            Audit::record('inventory.article_image_added', $article, ['image_id' => $image->id], $company, $user);

            return $image;
        });
    }

    /**
     * Puts one image at a new place (0 = first, the main image) and numbers
     * the rest after it.
     */
    public function move(User $user, Company $company, Article $article, int $imageId, int $position): void
    {
        $this->authorize($user, $company, $article);

        DB::transaction(function () use ($user, $company, $article, $imageId, $position): void {
            $images = ArticleImage::query()->where('article_id', $article->id)->orderBy('position')->orderBy('id')->lockForUpdate()->get();
            $moved = $images->firstWhere('id', $imageId) ?? throw ValidationException::withMessages(['photo' => __('Bilden finns inte längre.')]);
            $order = $images->reject(fn (ArticleImage $image): bool => $image->id === $moved->id)->values()->all();
            array_splice($order, max(0, min($position, count($order))), 0, [$moved]);

            foreach ($order as $index => $image) {
                if ($image->position !== $index) {
                    $image->update(['position' => $index]);
                }
            }

            Audit::record('inventory.article_images_reordered', $article, ['order' => array_map(fn (ArticleImage $image): int => $image->id, $order)], $company, $user);
        });
    }

    public function describe(User $user, Company $company, ArticleImage $image, string $alt): void
    {
        $article = Article::query()->findOrFail($image->article_id);
        $this->authorize($user, $company, $article);

        if (mb_strlen(trim($alt)) > self::ALT_MAX) {
            throw ValidationException::withMessages(['alt' => __('Bildtexten får vara högst :max tecken.', ['max' => self::ALT_MAX])]);
        }

        $image->update(['alt' => self::cleanAlt($alt)]);

        Audit::record('inventory.article_image_described', $article, ['image_id' => $image->id], $company, $user);
    }

    public function delete(User $user, Company $company, ArticleImage $image): void
    {
        $article = Article::query()->findOrFail($image->article_id);
        $this->authorize($user, $company, $article);

        DB::transaction(function () use ($user, $company, $article, $image): void {
            $image->delete();

            ArticleImage::query()->where('article_id', $article->id)->orderBy('position')->orderBy('id')->get()
                ->each(function (ArticleImage $rest, int $index): void {
                    if ($rest->position !== $index) {
                        $rest->update(['position' => $index]);
                    }
                });

            StoredFiles::deleteUnreferenced([$image->path]);

            Audit::record('inventory.article_image_deleted', $article, ['image_id' => $image->id], $company, $user);
        });
    }

    /** Whether the company's articles can have pictures: Lager is on. */
    public static function enabledFor(Company $company): bool
    {
        return app(ModuleManager::class)->isEnabled(InventoryModule::KEY, $company);
    }

    private function authorize(User $user, Company $company, Article $article): void
    {
        app(ModuleManager::class)->ensureEnabled(InventoryModule::KEY, $company);

        // Another company's article is not found, not forbidden.
        abort_unless($article->company_id === $company->id, 404);
        Gate::forUser($user)->authorize('update', $article);
    }

    private static function cleanAlt(?string $alt): ?string
    {
        $alt = trim((string) $alt);

        return $alt === '' ? null : mb_substr($alt, 0, self::ALT_MAX);
    }
}
