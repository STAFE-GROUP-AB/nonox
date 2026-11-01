<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/** Where a task is (ADR-028). */
enum TaskStatus: string
{
    case Todo = 'TODO';
    case InProgress = 'IN_PROGRESS';
    case WaitingForClient = 'WAITING_FOR_CLIENT';
    case Done = 'DONE';

    public function label(): string
    {
        return match ($this) {
            self::Todo => __('Att göra'),
            self::InProgress => __('Pågår'),
            self::WaitingForClient => __('Väntar på kund'),
            self::Done => __('Klar'),
        };
    }

    public function isOpen(): bool
    {
        return $this !== self::Done;
    }

    /** @return list<string> */
    public static function openValues(): array
    {
        return [self::Todo->value, self::InProgress->value, self::WaitingForClient->value];
    }

    /** The Swedish word used in URLs and filters. */
    public function slug(): string
    {
        return match ($this) {
            self::Todo => 'att-gora',
            self::InProgress => 'pagar',
            self::WaitingForClient => 'vantar-pa-kund',
            self::Done => 'klar',
        };
    }

    public static function fromSlug(string $slug): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->slug() === $slug) {
                return $case;
            }
        }

        return null;
    }
}
