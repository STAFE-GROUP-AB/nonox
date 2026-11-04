<?php

declare(strict_types=1);

namespace App\Domain\Deadlines;

use Carbon\CarbonImmutable;

/**
 * One date on Mina datum (ADR-051, stage 2): what is due, by when, for
 * which period, and why it applies to this company. The key is stable for
 * the same deadline (type and period), so calendar events and reminders
 * can refer to it. A recommendation is NONOX's advice, not a legal
 * deadline; legalVerify marks a date whose rule is not yet verified.
 *
 * @phpstan-type DeadlineData array{key: string, type: string, title: string, date: string, period: string, applies_because: string, route: ?string, article: ?string, source_url: ?string, recommendation: bool, legal_verify: bool, period_start?: ?string}
 */
final readonly class Deadline
{
    public function __construct(
        public string $key,
        public DeadlineType $type,
        public string $title,
        public CarbonImmutable $date,
        public string $period,
        public string $appliesBecause,
        public ?string $route = null,
        public ?string $article = null,
        public ?string $sourceUrl = null,
        public bool $isRecommendation = false,
        public bool $legalVerify = false,
        public ?CarbonImmutable $periodStart = null,
    ) {}

    /** The authority whose rule decides the date, to check it with. */
    public function checkWith(): string
    {
        return in_array($this->type, [DeadlineType::AnnualReport, DeadlineType::AnnualMeeting], true) ? 'Bolagsverket' : 'Skatteverket';
    }

    /** The kind and the title, for a calendar event or a reminder: "Moms: Deklarera och betala moms för kvartal 4 2026". */
    public function summary(): string
    {
        $label = DeadlineCategory::of($this)->label();

        // "F-skatt: Betala F-skatt …" would say it twice.
        return str_contains($this->title, $label) ? $this->title : "{$label}: {$this->title}";
    }

    /** Whole days from today to the date: negative when it has passed. */
    public function daysLeft(CarbonImmutable $today): int
    {
        return (int) $today->startOfDay()->diffInDays($this->date->startOfDay(), false);
    }

    /** @return DeadlineData */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'type' => $this->type->value,
            'title' => $this->title,
            'date' => $this->date->toDateString(),
            'period' => $this->period,
            'applies_because' => $this->appliesBecause,
            'route' => $this->route,
            'article' => $this->article,
            'source_url' => $this->sourceUrl,
            'recommendation' => $this->isRecommendation,
            'legal_verify' => $this->legalVerify,
            'period_start' => $this->periodStart?->toDateString(),
        ];
    }

    /** @param  DeadlineData  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['key'],
            DeadlineType::from($data['type']),
            $data['title'],
            CarbonImmutable::parse($data['date']),
            $data['period'],
            $data['applies_because'],
            $data['route'],
            $data['article'],
            $data['source_url'],
            $data['recommendation'],
            $data['legal_verify'],
            isset($data['period_start']) ? CarbonImmutable::parse($data['period_start']) : null,
        );
    }
}
