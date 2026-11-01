<?php

declare(strict_types=1);

namespace App\Http\Controllers\Search;

use App\Models\User;
use App\Support\Search\GlobalSearch;
use App\Support\Search\QueryParser;
use App\Support\Search\SearchContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The spotlight's results (ADR-026), streamed as one JSON object per line:
 * first what was understood (the chips), then each group as soon as its
 * provider has answered, then a closing line with the totals.
 *
 * The query arrives in a POST body, never in the URL, so that what people
 * type (names, the end of a personal number) stays out of access logs. It
 * is not logged here either.
 */
final class SearchController
{
    public function __invoke(Request $request, GlobalSearch $search, QueryParser $parser): StreamedResponse
    {
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:200']]);

        /** @var User $user */
        $user = $request->user();
        $company = $user->currentCompany();
        abort_if($company === null, 404);

        $query = $parser->parse((string) ($validated['q'] ?? ''));
        $context = new SearchContext($user, $company);

        return response()->stream(function () use ($search, $query, $context): void {
            $started = hrtime(true);
            $results = 0;
            $groups = 0;

            self::line(['type' => 'meta', 'query' => $query->raw, 'text' => $query->text, 'chips' => $query->chips]);

            foreach ($search->stream($query, $context) as $group) {
                $groups++;
                $results += count($group->results);
                self::line($group);
            }

            self::line(['type' => 'done', 'groups' => $groups, 'results' => $results, 'ms' => (int) round((hrtime(true) - $started) / 1e6)]);
        }, 200, [
            'Content-Type' => 'application/x-ndjson; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private static function line(mixed $payload): void
    {
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }
}
