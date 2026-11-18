<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Mrp;

/**
 * The advice on an existing purchase or production order (ADR-081 gap 5):
 * it is needed earlier than it is expected, so moving it forward covers a
 * shortage instead of a new order; or part of it is not needed within the
 * horizon.
 */
enum SupplyMessageKind: string
{
    case MoveIn = 'MOVE_IN';
    case Excess = 'EXCESS';

    public function label(): string
    {
        return match ($this) {
            self::MoveIn => __('Flytta fram'),
            self::Excess => __('Överskott'),
        };
    }
}
