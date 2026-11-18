<?php

declare(strict_types=1);

namespace App\Domain\Approval;

/** Whether a person may decide the current step, and for whom. */
final readonly class Eligibility
{
    public const string NOT_APPROVER = 'not_approver';

    public const string PREPARER = 'preparer';

    public const string ALREADY_DECIDED = 'already_decided';

    public const string CLOSED = 'closed';

    private function __construct(
        public bool $allowed,
        public ?int $onBehalfOf,
        public ?string $reason,
    ) {}

    public static function allow(?int $onBehalfOf = null): self
    {
        return new self(true, $onBehalfOf, null);
    }

    public static function deny(string $reason): self
    {
        return new self(false, null, $reason);
    }

    /** The Swedish explanation of a refusal, or null when the person may decide. */
    public function message(): ?string
    {
        if ($this->reason === null) {
            return null;
        }

        return match ($this->reason) {
            self::PREPARER => __('Du förberedde underlaget och kan därför inte godkänna det här steget. Någon annan behöver göra det.'),
            self::ALREADY_DECIDED => __('Du har redan godkänt det här steget. Nästa godkännande måste komma från någon annan.'),
            self::CLOSED => __('Attesten är redan avslutad.'),
            default => __('Du är inte attestant i det här steget.'),
        };
    }
}
