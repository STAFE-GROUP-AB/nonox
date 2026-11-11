<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Support;

/**
 * The words on the workshop terminal (ADR-071 §7), handed to its script as
 * JSON. Swedish, like every user-facing text; written with __() so each
 * string has its English counterpart in lang/en.json.
 */
final class TerminalStrings
{
    /** @return array<string, string> */
    public static function all(): array
    {
        return [
            'title' => __('Verkstadsterminal'),
            'pair_title' => __('Koppla den här surfplattan'),
            'pair_help' => __('Skriv in kopplingskoden som visas under Tillverkning, Verkstadsterminal i NONOX. Koden gäller i 15 minuter.'),
            'pair_label' => __('Kopplingskod'),
            'pair_button' => __('Koppla'),
            'pair_invalid' => __('Koden stämmer inte eller har gått ut. Hämta en ny kod i NONOX.'),
            'people_title' => __('Vem är du?'),
            'people_help' => __('Tryck på din bild och skriv sedan din kod.'),
            'people_empty' => __('Ingen har lagts till ännu. Lägg till personer under Tillverkning, Verkstadsterminal i NONOX.'),
            'code_title' => __('Hej :name'),
            'code_help' => __('Skriv din kod.'),
            'code_wrong' => __('Fel kod. Försök igen.'),
            'code_locked' => __('För många felaktiga koder. Vänta fem minuter eller be arbetsledaren om en ny kod.'),
            'code_blocked' => __('Din kod är spärrad efter för många felaktiga försök. Be arbetsledaren välja en ny kod.'),
            'device_paused' => __('Surfplattan är pausad en stund efter för många felaktiga koder. Försök igen om en timme eller be arbetsledaren.'),
            'back' => __('Tillbaka'),
            'delete' => __('Radera'),
            'sign_out' => __('Logga ut'),
            'production_time_note' => __('Det här är produktionstid: tid du lägger på en tillverkningsorder. Det är inte närvaro (personalliggaren) och inte lönetid.'),
            'what_is_saved' => __('Vad sparas? Ditt namn, vilken order och vilket arbetssteg du rapporterat på, dagen, minuterna och vilken surfplatta. Det används för vad ordern kostade och för efterkalkylen per produkt och arbetssteg. Ditt namn tas bort efter 24 månader. Be arbetsgivaren om ett utdrag med dina uppgifter.'),
            'orders_title' => __('Öppna order'),
            'orders_empty' => __('Det finns inga öppna order just nu.'),
            'due' => __('Klar :date'),
            'quantity' => __('Antal: :quantity'),
            'customer' => __('Kund: :customer'),
            'operations' => __('Arbetssteg'),
            'planned' => __('Planerat :time'),
            'reported' => __('Rapporterat :time'),
            'mine_today' => __('Du i dag :time'),
            'report_title' => __('Rapportera tid'),
            'report_help' => __('Tid för i dag på :operation.'),
            'hours' => __('Timmar'),
            'minutes' => __('Minuter'),
            'total' => __('Totalt :time'),
            'reset' => __('Nollställ'),
            'report_button' => __('Rapportera'),
            'reported_ok' => __('Tiden är sparad.'),
            'my_times' => __('Mina tider'),
            'my_times_help' => __('Dina rapporter de senaste två veckorna. Ser något fel ut? Rätta det och rapportera rätt tid igen.'),
            'my_times_empty' => __('Du har inte rapporterat något de senaste två veckorna.'),
            'correct' => __('Rätta'),
            'correct_confirm' => __('Ta bort den här tiden? Då kan du rapportera rätt tid i stället.'),
            'corrected_ok' => __('Tiden är rättad.'),
            'correction' => __('Rättelse'),
            'corrected' => __('Rättad'),
            'waiting_button' => __('Väntar'),
            'waiting_title' => __('Vad väntar arbetssteget på?'),
            'waiting_material' => __('Väntar på material'),
            'waiting_work' => __('Väntar på arbete'),
            'waiting_reason' => __('Orsak'),
            'waiting_reason_help' => __('Kort, till exempel "ekskivorna är inte levererade". Skriv inga namn.'),
            'waiting_save' => __('Markera som väntande'),
            'waiting_clear' => __('Det väntar inte längre'),
            'waiting_ok' => __('Markerat.'),
            'waiting_cleared_ok' => __('Markeringen är borttagen.'),
            'waiting_since' => __('sedan :time'),
            'no_connection' => __('Ingen anslutning. Inget har sparats.'),
            'session_ended' => __('Du loggades ut. Tryck på din bild igen.'),
            'device_unknown' => __('Den här surfplattan är inte längre kopplad. Koppla den på nytt.'),
            'terminal_off' => __('Verkstadsterminalen är avstängd för företaget.'),
            'something_wrong' => __('Något gick fel. Försök igen.'),
            'loading' => __('Hämtar …'),
            'minutes_short' => __(':min min'),
            'hours_short' => __(':h h'),
            'hours_minutes_short' => __(':h h :min min'),
        ];
    }
}
