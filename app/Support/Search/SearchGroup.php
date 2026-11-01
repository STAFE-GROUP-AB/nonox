<?php

declare(strict_types=1);

namespace App\Support\Search;

use JsonSerializable;

/**
 * The hits of one provider: at most a handful, with the number of matches
 * (counted up to a cap) and a link to the list page that shows them all,
 * where such a page exists.
 */
final readonly class SearchGroup implements JsonSerializable
{
    /** Matches are counted up to this many; more is shown as "99+". */
    public const int COUNT_CAP = 99;

    /**
     * @param  list<SearchResult>  $results
     * @param  int  $total  number of matches, at most COUNT_CAP + 1
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $results,
        public int $total,
        public int $order = 100,
        public ?string $moreUrl = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->results === [];
    }

    public function bestScore(): float
    {
        $best = 0.0;

        foreach ($this->results as $result) {
            $best = max($best, $result->score);
        }

        return $best;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'type' => 'group',
            'key' => $this->key,
            'label' => $this->label,
            'order' => $this->order,
            'total' => $this->total,
            'totalLabel' => $this->total > self::COUNT_CAP ? self::COUNT_CAP.'+' : (string) $this->total,
            'more' => $this->total > count($this->results) ? $this->moreUrl : null,
            'best' => round($this->bestScore(), 1),
            'results' => $this->results,
        ];
    }
}
