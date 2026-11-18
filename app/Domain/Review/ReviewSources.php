<?php

declare(strict_types=1);

namespace App\Domain\Review;

use App\Domain\Review\Contracts\ReviewSource;
use App\Domain\Review\Sources\ApprovalRequestSource;
use App\Models\Company;
use App\Models\User;
use Illuminate\Contracts\Container\Container;

/**
 * The sources of the approval queue (ADR-067 §1). The approval engine's open
 * requests are the first (ADR-081 E21); the inbox, bank matches, incoming
 * payments, agreement and time proposals and assistant drafts join as their
 * own sources when ADR-067's phases are built.
 */
final class ReviewSources
{
    /** @var list<class-string<ReviewSource>> */
    private const array CORE = [ApprovalRequestSource::class];

    public function __construct(private readonly Container $container) {}

    /** @return list<ReviewSource> */
    public function all(): array
    {
        return array_map(fn (string $class): ReviewSource => $this->container->make($class), self::CORE);
    }

    /**
     * Every item the user may act on, oldest first.
     *
     * @return list<ReviewItem>
     */
    public function items(User $user, Company $company): array
    {
        $items = [];

        foreach ($this->all() as $source) {
            array_push($items, ...$source->items($user, $company));
        }

        usort($items, fn (ReviewItem $a, ReviewItem $b): int => [$a->date->getTimestamp(), $a->source, $a->id] <=> [$b->date->getTimestamp(), $b->source, $b->id]);

        return $items;
    }
}
