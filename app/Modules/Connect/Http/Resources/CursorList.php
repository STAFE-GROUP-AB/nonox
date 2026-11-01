<?php

declare(strict_types=1);

namespace App\Modules\Connect\Http\Resources;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Lists in the API (ADR-032): cursor pagination ordered by updated_at and
 * id, so a sync can ask for everything changed since its last run with
 * updated_since and walk the pages without missing or repeating a row,
 * even while rows change. `limit` is 1–200 (default 50).
 */
final class CursorList
{
    public const int DEFAULT_LIMIT = 50;

    public const int MAX_LIMIT = 200;

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  Closure(TModel): array<string, mixed>  $present
     */
    public static function respond(Request $request, Builder $query, Closure $present): JsonResponse
    {
        $input = Validator::make($request->query(), [
            'limit' => ['nullable', 'integer', 'between:1,'.self::MAX_LIMIT],
            'cursor' => ['nullable', 'string', 'max:2000'],
            'updated_since' => ['nullable', 'string', 'max:40'],
        ])->validate();

        if (filled($input['updated_since'] ?? null)) {
            $query->where($query->getModel()->qualifyColumn('updated_at'), '>', self::time((string) $input['updated_since']));
        }

        $limit = (int) ($input['limit'] ?? self::DEFAULT_LIMIT);
        $page = $query
            ->orderBy($query->getModel()->qualifyColumn('updated_at'))
            ->orderBy($query->getModel()->qualifyColumn($query->getModel()->getKeyName()))
            ->cursorPaginate($limit, ['*'], 'cursor', $input['cursor'] ?? null);

        return new JsonResponse([
            'data' => array_map($present, $page->items()),
            'meta' => [
                'limit' => $limit,
                'next_cursor' => $page->nextCursor()?->encode(),
                'has_more' => $page->hasMorePages(),
            ],
        ], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function one(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse(['data' => $data], $status, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function time(string $value): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            throw ValidationException::withMessages(['updated_since' => __('Skriv tiden enligt ISO 8601, till exempel 2026-11-01T12:00:00+01:00.')]);
        }
    }
}
