<?php

declare(strict_types=1);

namespace App\Http\Controllers\Search;

use App\Models\User;
use App\Support\Search\RecentItems;
use App\Support\Search\SearchContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Remembers a record opened from the spotlight. Only its type and id are
 * sent; the title is looked up through the record's provider, inside the
 * user's company and with its permission checks.
 */
final class RecentItemController
{
    public function __invoke(Request $request, RecentItems $recent): Response
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'max:40'],
            'id' => ['required', 'string', 'max:64'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $company = $user->currentCompany();
        abort_if($company === null, 404);

        $recent->remember($validated['type'], $validated['id'], new SearchContext($user, $company));

        return response()->noContent();
    }
}
