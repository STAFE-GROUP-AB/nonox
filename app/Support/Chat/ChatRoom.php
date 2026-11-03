<?php

declare(strict_types=1);

namespace App\Support\Chat;

/**
 * What the server knows about one encrypted chat (ADR-045): who sits in slot
 * A (the starter) and slot B (the one who joined), their display names, when
 * it started, when something last happened and the last sequence number.
 * Never the code, the key or anything that was written.
 */
final readonly class ChatRoom
{
    public function __construct(
        public string $id,
        public int $starterId,
        public string $starterName,
        public ?int $joinerId,
        public ?string $joinerName,
        public int $createdAt,
        public int $activeAt,
        public int $sequence,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(string $id, array $data): ?self
    {
        if (! is_int($data['a'] ?? null) || ! is_int($data['created'] ?? null) || ! is_int($data['active'] ?? null) || ! is_int($data['seq'] ?? null)) {
            return null;
        }

        return new self(
            id: $id,
            starterId: $data['a'],
            starterName: is_string($data['a_name'] ?? null) ? $data['a_name'] : '',
            joinerId: is_int($data['b'] ?? null) ? $data['b'] : null,
            joinerName: is_string($data['b_name'] ?? null) ? $data['b_name'] : null,
            createdAt: $data['created'],
            activeAt: $data['active'],
            sequence: $data['seq'],
        );
    }

    /**
     * @return array{a: int, a_name: string, b: ?int, b_name: ?string, created: int, active: int, seq: int}
     */
    public function toArray(): array
    {
        return [
            'a' => $this->starterId,
            'a_name' => $this->starterName,
            'b' => $this->joinerId,
            'b_name' => $this->joinerName,
            'created' => $this->createdAt,
            'active' => $this->activeAt,
            'seq' => $this->sequence,
        ];
    }

    public function slotOf(int $userId): ?string
    {
        if ($userId === $this->starterId) {
            return SecureChat::SLOT_STARTER;
        }

        if ($userId === $this->joinerId) {
            return SecureChat::SLOT_JOINER;
        }

        return null;
    }

    public function peerNameFor(int $userId): ?string
    {
        return $this->slotOf($userId) === SecureChat::SLOT_STARTER ? $this->joinerName : $this->starterName;
    }

    public function with(?int $joinerId = null, ?string $joinerName = null, ?int $activeAt = null, ?int $sequence = null): self
    {
        return new self(
            id: $this->id,
            starterId: $this->starterId,
            starterName: $this->starterName,
            joinerId: $joinerId ?? $this->joinerId,
            joinerName: $joinerName ?? $this->joinerName,
            createdAt: $this->createdAt,
            activeAt: $activeAt ?? $this->activeAt,
            sequence: $sequence ?? $this->sequence,
        );
    }
}
