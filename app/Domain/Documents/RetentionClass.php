<?php

declare(strict_types=1);

namespace App\Domain\Documents;

/**
 * How long a company document is kept (ADR-081 gap 9, E19).
 *
 * - ACCOUNTING: räkenskapsinformation, kept until seven years after the end
 *   of the calendar year in which its fiscal year ended (BFL 7 kap. 2 §);
 *   deletion is blocked before then (AGENTS.md §10).
 * - CONTRACT: kept while the contract runs and a chosen number of years after
 *   it ends.
 * - PERSONNEL: documents about employees; only people with
 *   payroll:view-sensitive see them (owner decision 21).
 * - OTHER: deletable, never deleted automatically (owner decision 20).
 */
enum RetentionClass: string
{
    case Accounting = 'ACCOUNTING';
    case Contract = 'CONTRACT';
    case Personnel = 'PERSONNEL';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::Accounting => __('Räkenskapsinformation'),
            self::Contract => __('Avtal'),
            self::Personnel => __('Personal'),
            self::Other => __('Övrigt'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Accounting => __('Sparas i sju år efter utgången av det kalenderår då räkenskapsåret avslutades. Kan inte raderas före det.'),
            self::Contract => __('Sparas medan avtalet löper och det antal år du väljer efter att det har upphört.'),
            self::Personnel => __('Dokument om anställda. Bara den som får se känsliga löneuppgifter ser dem.'),
            self::Other => __('Kan raderas när som helst av den som får hantera dokument. Raderas aldrig automatiskt.'),
        };
    }
}
