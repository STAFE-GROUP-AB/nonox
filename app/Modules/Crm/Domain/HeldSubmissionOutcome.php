<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

/**
 * What accepting a held web form submission created, so the toast says
 * only what actually happened.
 *
 * - NEW_LEAD: a new person, a prospect and a task.
 * - EXISTING_PERSON: the address belonged to a known person; a task on
 *   their prospect (created if they had none).
 * - DO_NOT_CONTACT: the person is marked Kontakta inte; only the
 *   submission is kept, nothing goes on their timeline.
 */
enum HeldSubmissionOutcome: string
{
    case NewLead = 'NEW_LEAD';
    case ExistingPerson = 'EXISTING_PERSON';
    case DoNotContact = 'DO_NOT_CONTACT';

    public function message(): string
    {
        return match ($this) {
            self::NewLead => __('Anmälan är mottagen: prospekt, kontaktperson och uppgift är skapade.'),
            self::ExistingPerson => __('Anmälan är mottagen. Personen fanns redan, så en uppgift är skapad på den befintliga kontakten.'),
            self::DoNotContact => __('Anmälan är mottagen. Personen har bett att inte bli kontaktad, så ingen uppgift är skapad.'),
        };
    }
}
