<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http;

use App\Models\Article;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\ArticleImage;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\ArticlePhoto;
use App\Support\Files\StoredFiles;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * An article's picture, for those who see the article register or Lager.
 * The file is private and encrypted at rest, so it is served through here
 * and never from a public URL. ?storlek=liten gives a small copy for lists.
 * Another company's picture answers 404.
 */
class ArticleImageController
{
    public function __invoke(Request $request, int $article, int $image): Response
    {
        /** @var User $user */
        $user = Auth::user();
        $model = ArticleImage::query()->where('article_id', $article)->findOrFail($image);
        $company = Company::query()->findOrFail($model->company_id);

        abort_unless(
            Gate::forUser($user)->allows('viewAny', [Article::class, $company]) || Gate::forUser($user)->allows('viewAny', [StockMovement::class, $company]),
            404,
        );

        $jpeg = (string) Storage::disk(StoredFiles::DISK)->get($model->path);
        abort_if($jpeg === '', 404);

        if ($request->query('storlek') === 'liten') {
            $jpeg = ArticlePhoto::thumbnail($jpeg) ?? abort(404);
        }

        return response($jpeg, 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
