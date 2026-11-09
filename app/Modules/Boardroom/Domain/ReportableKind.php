<?php

declare(strict_types=1);

namespace App\Modules\Boardroom\Domain;

/**
 * A decision that must be reported to Bolagsverket for registration
 * (rule sheet AB-R37–AB-R42). NONOX only reminds; it never files.
 */
enum ReportableKind: string
{
    /** ABL 8 kap. 43–44 §§; the change takes effect when the notification reaches Bolagsverket (8 kap. 13 §). */
    case BoardChange = 'BOARD_CHANGE';
    /** ABL 9 kap. 47–48 §§. TODO(legal-verify): L7 – effect at the decision or on registration. */
    case AuditorChange = 'AUDITOR_CHANGE';
    /** ABL 3 kap. 5 §: may not be carried out until registered. */
    case ArticlesChange = 'ARTICLES_CHANGE';
    case OtherRegistration = 'OTHER_REGISTRATION';

    public function label(): string
    {
        return match ($this) {
            self::BoardChange => __('Ändring i styrelsen'),
            self::AuditorChange => __('Byte av revisor'),
            self::ArticlesChange => __('Ny bolagsordning'),
            self::OtherRegistration => __('Annan anmälan'),
        };
    }

    /** What the user should know about when the change takes effect. */
    public function effect(): string
    {
        return match ($this) {
            self::BoardChange => __('Ändringen gäller först från den dag anmälan kom in till Bolagsverket (aktiebolagslagen 8 kap. 13 §).'),
            self::AuditorChange => __('Anmäl genast. Lagen säger inte uttryckligen när bytet får verkan; kontrollera med Bolagsverket.'),
            self::ArticlesChange => __('Den nya bolagsordningen får inte tillämpas förrän den är registrerad (aktiebolagslagen 3 kap. 5 §).'),
            self::OtherRegistration => __('Anmäl genast till Bolagsverket.'),
        };
    }
}
