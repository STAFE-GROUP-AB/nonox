<?php

declare(strict_types=1);

namespace App\Support\Search;

use App\Models\RecentItem;
use App\Support\Tenancy\CurrentCompany;

/**
 * "Senast öppnade": remembers what a user opened, per company, and lists it
 * back filtered by what the user may still see (ADR-026).
 */
final class RecentItems
{
    public const int SHOWN = 8;

    private const int KEPT = 50;

    public function __construct(private readonly SearchRegistry $registry) {}

    /** Looks the record up through its provider, so only what the user may see is remembered. */
    public function remember(string $type, string $id, SearchContext $context): ?RecentItem
    {
        return CurrentCompany::run($context->company, fn (): ?RecentItem => $this->store($type, $id, $context));
    }

    private function store(string $type, string $id, SearchContext $context): ?RecentItem
    {
        $provider = $this->registry->provider($type, $context);
        $result = $provider?->find($id, $context);

        if ($result === null || ! $result->remember) {
            return null;
        }

        $item = RecentItem::query()->updateOrCreate(
            ['company_id' => $context->company->id, 'user_id' => $context->user->id, 'type' => $type, 'record_id' => $id],
            ['title' => mb_substr($result->title, 0, 255), 'subtitle' => $result->subtitle === null ? null : mb_substr($result->subtitle, 0, 255), 'url' => $result->url, 'icon' => $result->icon, 'opened_at' => now()],
        );

        $this->prune($context);

        return $item;
    }

    /** @return list<SearchResult> */
    public function list(SearchContext $context): array
    {
        $available = $this->registry->for($context);
        $results = [];

        $items = RecentItem::query()
            ->where('company_id', $context->company->id)
            ->where('user_id', $context->user->id)
            ->latest('opened_at')
            ->limit(self::SHOWN * 3)
            ->get();

        foreach ($items as $item) {
            if (! isset($available[$item->type])) {
                continue;
            }

            $results[] = new SearchResult(
                type: $item->type,
                id: $item->record_id,
                title: $item->title,
                url: $item->url,
                score: 0,
                subtitle: $item->subtitle,
                meta: $item->opened_at->diffForHumans(),
                icon: $item->icon,
            );

            if (count($results) === self::SHOWN) {
                break;
            }
        }

        return $results;
    }

    private function prune(SearchContext $context): void
    {
        $keep = RecentItem::query()
            ->where('company_id', $context->company->id)
            ->where('user_id', $context->user->id)
            ->latest('opened_at')
            ->limit(self::KEPT)
            ->pluck('id');

        if ($keep->count() < self::KEPT) {
            return;
        }

        RecentItem::query()
            ->where('company_id', $context->company->id)
            ->where('user_id', $context->user->id)
            ->whereNotIn('id', $keep)
            ->delete();
    }
}
