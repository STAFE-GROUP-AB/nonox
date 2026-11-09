<?php

declare(strict_types=1);

namespace App\Modules\Saas\Domain;

/**
 * One known state of a contract from a date (ADR-080 §3.1), as the pure
 * calculation sees it. `id` is the order in which versions became known:
 * at an instant, the version that applies is the one known last among those
 * effective on or before it, because a later version describes the contract
 * from its date onward (the agreement in NONOX is overwritten, so what was
 * known before about later dates no longer holds).
 */
final readonly class ContractVersion
{
    /**
     * @param  list<RevenueLine>  $lines
     */
    public function __construct(
        public int $id,
        public SourceType $sourceType,
        public string $sourceId,
        public ?int $customerId,
        public string $effectiveFrom,
        public ContractStatus $status,
        public array $lines,
        public ?string $startsOn = null,
        /** The first day the contract no longer counts: the day after the last paid or bound period (SA-R15). */
        public ?string $cancelEffectiveOn = null,
        public ?ExclusionReason $excluded = null,
        public VersionCause $cause = VersionCause::Changed,
    ) {}

    public function contractKey(): string
    {
        return $this->sourceType->value.':'.$this->sourceId;
    }

    public function counts(): bool
    {
        return $this->excluded === null && $this->customerId !== null;
    }

    /** In force at the instant (Y-m-d): started and not yet cancelled. */
    public function inForceAt(string $instant): bool
    {
        if ($this->startsOn !== null && $instant < $this->startsOn) {
            return false;
        }

        return $this->cancelEffectiveOn === null || $instant < $this->cancelEffectiveOn;
    }
}
