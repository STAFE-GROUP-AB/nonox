<?php

declare(strict_types=1);

namespace App\Modules\Boardroom\Domain;

/**
 * A range of shares in the register with everything ABL 5 kap. 5–6 §§ asks
 * for per share: who holds them, the class, whether a certificate has been
 * issued, whether they come from a new issue not yet registered, and any
 * rights-holder or förköp notes.
 */
final readonly class Holding
{
    /**
     * @param  list<array{event_id: int, kind: string, text: string, date: string}>  $notes
     */
    public function __construct(
        public int $from,
        public int $to,
        public int $holderId,
        public ?int $classId,
        public bool $certificate = false,
        public ?int $pendingIssueId = null,
        public array $notes = [],
    ) {}

    public function count(): int
    {
        return $this->to - $this->from + 1;
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function with(array $changes): self
    {
        return new self(
            (int) ($changes['from'] ?? $this->from),
            (int) ($changes['to'] ?? $this->to),
            (int) ($changes['holderId'] ?? $this->holderId),
            array_key_exists('classId', $changes) ? (is_int($changes['classId']) ? $changes['classId'] : null) : $this->classId,
            array_key_exists('certificate', $changes) ? (bool) $changes['certificate'] : $this->certificate,
            array_key_exists('pendingIssueId', $changes) ? (is_int($changes['pendingIssueId']) ? $changes['pendingIssueId'] : null) : $this->pendingIssueId,
            array_key_exists('notes', $changes) && is_array($changes['notes']) ? array_values($changes['notes']) : $this->notes,
        );
    }

    /** Whether two neighbouring ranges say the same and can be shown as one. */
    public function continues(self $previous): bool
    {
        return $previous->to + 1 === $this->from
            && $previous->holderId === $this->holderId
            && $previous->classId === $this->classId
            && $previous->certificate === $this->certificate
            && $previous->pendingIssueId === $this->pendingIssueId
            && $previous->notes === $this->notes;
    }

    public function hasNote(string ...$kinds): bool
    {
        foreach ($this->notes as $note) {
            if ($kinds === [] || in_array($note['kind'], $kinds, true)) {
                return true;
            }
        }

        return false;
    }
}
