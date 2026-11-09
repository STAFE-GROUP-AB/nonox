<?php

declare(strict_types=1);

namespace App\Modules\Saas\Domain;

/**
 * The movement categories of a customer's MRR in one month (SA-R14).
 * Valutaeffekt and Rättelse are kept beside the movement (fx, correction),
 * so a customer can have a currency effect and, say, an expansion in the
 * same month.
 */
enum Movement: string
{
    case New = 'NEW';
    case Reactivation = 'REACTIVATION';
    case Resumed = 'RESUMED';
    case Expansion = 'EXPANSION';
    case Contraction = 'CONTRACTION';
    case Pause = 'PAUSE';
    case Churn = 'CHURN';

    public function label(): string
    {
        return match ($this) {
            self::New => __('Ny'),
            self::Reactivation => __('Återaktivering'),
            self::Resumed => __('Återupptagen'),
            self::Expansion => __('Utökning'),
            self::Contraction => __('Minskning'),
            self::Pause => __('Paus'),
            self::Churn => __('Churn'),
        };
    }

    /** The category in a URL (AGENTS.md §11.4). */
    public function slug(): string
    {
        return match ($this) {
            self::New => 'ny',
            self::Reactivation => 'ateraktivering',
            self::Resumed => 'aterupptagen',
            self::Expansion => 'utokning',
            self::Contraction => 'minskning',
            self::Pause => 'paus',
            self::Churn => 'churn',
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

    /** The column on saas_months that sums the category. */
    public function column(): string
    {
        return match ($this) {
            self::New => 'new_ore',
            self::Reactivation => 'reactivation_ore',
            self::Resumed => 'resumed_ore',
            self::Expansion => 'expansion_ore',
            self::Contraction => 'contraction_ore',
            self::Pause => 'pause_ore',
            self::Churn => 'churn_ore',
        };
    }
}
