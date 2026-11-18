<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Mrp;

use Carbon\CarbonImmutable;

/**
 * Everything one planning run reads (ADR-081 gap 5), gathered by
 * MrpSnapshot inside one company: the articles planned by MRP, their
 * dated demand, their weekly forecast and their scheduled receipts. The
 * engine reads nothing else, so a run can be recomputed by hand from this.
 */
final readonly class MrpInput
{
    /**
     * @param  array<int, MrpArticle>  $articles  article id => article
     * @param  list<MrpDemand>  $demands
     * @param  array<int, array<string, int>>  $forecast  article id => (Monday => milli)
     * @param  list<MrpReceipt>  $receipts
     */
    public function __construct(
        public CarbonImmutable $today,
        public CarbonImmutable $firstMonday,
        public int $weeks,
        public array $articles,
        public array $demands,
        public array $forecast,
        public array $receipts,
    ) {}

    /** SHA-256 of the inputs as canonical JSON: two runs on the same data have the same hash. */
    public function hash(): string
    {
        $articles = array_map(fn (MrpArticle $article): array => $article->toArray(), $this->articles);
        ksort($articles);

        $forecast = $this->forecast;
        ksort($forecast);

        return hash('sha256', (string) json_encode([
            'today' => $this->today->toDateString(),
            'starts_on' => $this->firstMonday->toDateString(),
            'weeks' => $this->weeks,
            'articles' => array_values($articles),
            'demands' => array_map(fn (MrpDemand $demand): array => $demand->toArray(), $this->demands),
            'forecast' => $forecast,
            'receipts' => array_map(fn (MrpReceipt $receipt): array => $receipt->toArray(), $this->receipts),
        ], JSON_THROW_ON_ERROR));
    }
}
