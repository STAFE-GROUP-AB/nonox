<?php

declare(strict_types=1);

namespace App\Domain\Group;

/**
 * Why a holding gives control over the held company (ÅRL 1 kap. 4 §, rule
 * S2-R1d). Entered by a person, never derived from the voting share: votes
 * alone cannot decide whether a company is a subsidiary.
 */
enum ControlBasis: string
{
    /** Point 1: more than half of the votes. */
    case Votes = 'VOTES';

    /** Point 2: owns shares and controls more than half of the votes by agreement with other owners. */
    case AgreementVotes = 'AGREEMENT_VOTES';

    /** Point 3: owns shares and may appoint or remove more than half of the board. */
    case Board = 'BOARD';

    /** Point 4: owns shares and may alone exercise a controlling influence. */
    case ControllingInfluence = 'CONTROLLING_INFLUENCE';

    /** No control: the held company is not a subsidiary through this holding. */
    case None = 'NONE';

    public function label(): string
    {
        return match ($this) {
            self::Votes => __('Mer än hälften av rösterna'),
            self::AgreementVotes => __('Mer än hälften av rösterna genom avtal med andra delägare'),
            self::Board => __('Rätt att utse eller avsätta mer än hälften av styrelsen'),
            self::ControllingInfluence => __('Rätt att ensam utöva ett bestämmande inflytande'),
            self::None => __('Inget bestämmande inflytande'),
        };
    }

    public function givesControl(): bool
    {
        return $this !== self::None;
    }
}
