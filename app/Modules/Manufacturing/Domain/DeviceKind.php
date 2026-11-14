<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Domain;

/**
 * What a paired device in the workshop is (ADR-071 §7, M7): a terminal
 * tablet that workers report time on, or a wall screen that only shows the
 * day's production. Both are paired the same way, but a token answers only
 * for its own kind: a wall screen's token cannot list the terminal's
 * people, and a terminal's cannot read the wall screen's board.
 */
enum DeviceKind: string
{
    case Terminal = 'TERMINAL';
    case WallScreen = 'WALL';
}
