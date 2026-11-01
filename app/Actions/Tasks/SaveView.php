<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Models\SavedView;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * A user's named filters on a page that spans companies. Only known filter
 * keys with short string values are kept; the filters narrow what the
 * user's memberships allow and never widen it.
 */
class SaveView
{
    /**
     * @param  array<string, mixed>  $filters
     * @param  list<string>  $keys  the filters the page knows
     */
    public function __invoke(User $user, string $page, string $name, array $filters, array $keys): SavedView
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 60) {
            throw ValidationException::withMessages(['viewName' => __('Ge vyn ett namn på högst 60 tecken.')]);
        }

        $clean = [];

        foreach ($keys as $key) {
            $value = $filters[$key] ?? '';

            if (is_scalar($value) && (string) $value !== '' && mb_strlen((string) $value) <= 40) {
                $clean[$key] = (string) $value;
            }
        }

        $existing = SavedView::query()->where('user_id', $user->id)->where('page', $page)->where('name', $name)->first();

        if (! $existing instanceof SavedView && SavedView::query()->where('user_id', $user->id)->where('page', $page)->count() >= SavedView::MAX_PER_PAGE) {
            throw ValidationException::withMessages(['viewName' => __('Du kan spara högst :max vyer.', ['max' => SavedView::MAX_PER_PAGE])]);
        }

        $view = $existing ?? new SavedView(['user_id' => $user->id, 'page' => $page, 'name' => $name]);
        $view->filters = $clean;
        $view->save();

        return $view;
    }

    public function delete(User $user, int $viewId): void
    {
        SavedView::query()->where('user_id', $user->id)->whereKey($viewId)->delete();
    }
}
