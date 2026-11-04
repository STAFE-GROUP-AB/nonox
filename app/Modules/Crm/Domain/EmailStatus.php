<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

/**
 * What became of an e-mail sent to the company's +crm address (ADR-048).
 *
 * - LOGGED: on the timeline of every account it matched.
 * - UNMATCHED: no account or person had an address in it; waits in
 *   "Ej kopplade" for someone to link it or create a prospect.
 * - HELD: sent by us to someone marked Kontakta inte (an objection). A
 *   contact activity is not logged for them (stage 1), so it waits in
 *   "Ej kopplade" with that reason, to be logged as a note or removed.
 * - RESTRICTED: the person's processing is restricted (art. 18): the
 *   e-mail is stored only, on no timeline.
 */
enum EmailStatus: string
{
    case Logged = 'LOGGED';
    case Unmatched = 'UNMATCHED';
    case Held = 'HELD';
    case Restricted = 'RESTRICTED';

    /** Waits in "Ej kopplade" for a person to decide. */
    public function waits(): bool
    {
        return $this === self::Unmatched || $this === self::Held;
    }
}
