<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Domain;

/** What started a recomputation of the plan (ADR-071 §13.4). */
enum PlanTrigger: string
{
    case Manual = 'MANUAL';
    case Nightly = 'NIGHTLY';
    case Absence = 'ABSENCE';
    case Shift = 'SHIFT';
    case Downtime = 'DOWNTIME';
    case Purchase = 'PURCHASE';
    case Receipt = 'RECEIPT';
    case Terminal = 'TERMINAL';
    case ProductionOrder = 'PRODUCTION_ORDER';
    case SalesOrder = 'SALES_ORDER';
    case Lock = 'LOCK';
    case Scenario = 'SCENARIO';
    case Resources = 'RESOURCES';

    public function label(): string
    {
        return match ($this) {
            self::Manual => __('Beräknad på begäran'),
            self::Nightly => __('Nattlig beräkning'),
            self::Absence => __('Frånvaro'),
            self::Shift => __('Publicerat schema'),
            self::Downtime => __('Stopp i arbetsstation'),
            self::Purchase => __('Ändrad inköpsorder'),
            self::Receipt => __('Inleverans'),
            self::Terminal => __('Rapport från verkstaden'),
            self::ProductionOrder => __('Ändrad tillverkningsorder'),
            self::SalesOrder => __('Ändrad kundorder'),
            self::Lock => __('Låsning eller prioritet'),
            self::Scenario => __('Tillämpat Tänk om'),
            self::Resources => __('Ändrade resurser'),
        };
    }
}
