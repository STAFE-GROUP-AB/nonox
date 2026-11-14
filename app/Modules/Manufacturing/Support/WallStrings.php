<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Support;

/**
 * The words on the workshop wall screen (ADR-071, M7), handed to its script
 * as JSON. Swedish, like every user-facing text; written with __() so each
 * string has its English counterpart in lang/en.json.
 */
final class WallStrings
{
    /** @return array<string, string> */
    public static function all(): array
    {
        return [
            'title' => __('Storskärm i verkstaden'),
            'pair_title' => __('Koppla den här skärmen'),
            'pair_help' => __('Skriv in kopplingskoden som visas under Tillverkning, Storskärm i NONOX. Koden gäller i 15 minuter.'),
            'pair_label' => __('Kopplingskod'),
            'pair_button' => __('Koppla'),
            'pair_invalid' => __('Koden stämmer inte eller har gått ut. Hämta en ny kod i NONOX.'),
            'loading' => __('Hämtar dagens tillverkning …'),
            'off' => __('Storskärmen är avstängd eftersom Tillverkning inte är påslagen för företaget.'),
            'no_plan' => __('Det finns ingen plan ännu. Planen beräknas under Tillverkning, Planering.'),
            'updated' => __('Planen beräknad :time'),
            'stale' => __('Ingen anslutning. Visar läget från :time.'),
            'preview' => __('Förhandsvisning. Så här ser storskärmen ut just nu.'),
            'today' => __('I dag'),
            'today_empty' => __('Inget arbete är planerat i dag.'),
            'now' => __('Pågår'),
            'done' => __('Klart enligt plan'),
            'late' => __('Försenat'),
            'late_empty' => __('Inget är försenat enligt planen.'),
            'late_days' => __(':days arbetsdagar sent'),
            'late_day' => __('1 arbetsdag sent'),
            'promised' => __('Lovat :date'),
            'forecast' => __('Klart :date'),
            'after_horizon' => __('efter horisonten'),
            'waits_for' => __('Väntar på :what'),
            'deliveries' => __('Ska levereras'),
            'deliveries_empty' => __('Inget ska levereras de närmaste arbetsdagarna.'),
            'waiting' => __('Väntar och stopp'),
            'waiting_empty' => __('Inget arbetssteg väntar och ingen arbetsstation står still.'),
            'since' => __('sedan :time'),
            'stopped' => __(':station står still'),
            'until' => __('till :time'),
            'until_further' => __('tills vidare'),
            'more' => __('och :count till'),
            'day_today' => __('i dag'),
            'day_tomorrow' => __('i morgon'),
            'day_yesterday' => __('i går'),
            'no_connection' => __('Ingen anslutning. Försöker igen.'),
        ];
    }
}
