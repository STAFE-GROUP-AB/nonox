<?php

declare(strict_types=1);

namespace App\Support\Search;

use JsonSerializable;

/**
 * One hit in the spotlight search. `type` is the key of the provider that
 * found it; `type` and `id` together name the record for "Senast öppnade".
 */
final readonly class SearchResult implements JsonSerializable
{
    public const string OPEN = 'open';

    public const string SWITCH_COMPANY = 'switch';

    /**
     * @param  array<string, int|string>  $payload  extra data the action needs (the company id when switching)
     */
    public function __construct(
        public string $type,
        public string $id,
        public string $title,
        public string $url,
        public float $score = 0.0,
        public ?string $subtitle = null,
        public ?string $meta = null,
        public ?string $badge = null,
        public string $icon = 'document',
        public string $action = self::OPEN,
        public array $payload = [],
        public bool $remember = true,
    ) {}

    public function withScore(float $score): self
    {
        return new self($this->type, $this->id, $this->title, $this->url, $score, $this->subtitle, $this->meta, $this->badge, $this->icon, $this->action, $this->payload, $this->remember);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'uid' => "{$this->type}:{$this->id}",
            'type' => $this->type,
            'id' => $this->id,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'meta' => $this->meta,
            'badge' => $this->badge,
            'icon' => $this->icon,
            'url' => $this->url,
            'score' => round($this->score, 1),
            'action' => $this->action,
            'payload' => $this->payload,
            'remember' => $this->remember,
        ];
    }
}
