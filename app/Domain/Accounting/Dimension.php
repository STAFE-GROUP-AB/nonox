<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use App\Models\CostCenter;
use App\Models\Project;

/**
 * The two dimensions a voucher row can carry (AGENTS.md §6.5), with their
 * reserved SIE dimension numbers (SIE 4C §8).
 */
enum Dimension: string
{
    case CostCenter = 'kostnadsstallen';
    case Project = 'projekt';

    /** @return class-string<CostCenter|Project> */
    public function model(): string
    {
        return match ($this) {
            self::CostCenter => CostCenter::class,
            self::Project => Project::class,
        };
    }

    public function sieNumber(): int
    {
        return match ($this) {
            self::CostCenter => 1,
            self::Project => 6,
        };
    }

    public static function fromSieNumber(int $number): ?self
    {
        return match ($number) {
            1 => self::CostCenter,
            6 => self::Project,
            default => null,
        };
    }

    /** The column on voucher_rows. */
    public function column(): string
    {
        return match ($this) {
            self::CostCenter => 'cost_center_id',
            self::Project => 'project_id',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::CostCenter => __('Kostnadsställe'),
            self::Project => __('Projekt'),
        };
    }

    public function pluralLabel(): string
    {
        return match ($this) {
            self::CostCenter => __('Kostnadsställen'),
            self::Project => __('Projekt'),
        };
    }

    public function routeName(): string
    {
        return match ($this) {
            self::CostCenter => 'accounting.costCenters',
            self::Project => 'accounting.projects',
        };
    }
}
