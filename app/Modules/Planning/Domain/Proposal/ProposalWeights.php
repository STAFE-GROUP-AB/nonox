<?php

declare(strict_types=1);

namespace App\Modules\Planning\Domain\Proposal;

/**
 * "Vad väger mest": how much each soft criterion of the proposal counts,
 * as a level per criterion (ADR-037 amendment, gap 3). The company sets
 * them; the defaults are the ADR's. Base pay is never a criterion.
 */
final readonly class ProposalWeights
{
    public const string FAIRNESS = 'fairness';

    public const string CONTRACTED = 'contracted';

    public const string PREFERENCE = 'preference';

    public const string CONTINUITY = 'continuity';

    public const string EXTRA_COST = 'extra_cost';

    public const array CRITERIA = [self::FAIRNESS, self::CONTRACTED, self::PREFERENCE, self::CONTINUITY, self::EXTRA_COST];

    /** Level => factor. */
    public const array LEVELS = ['HIGH' => 3, 'MEDIUM' => 2, 'LOW' => 1, 'OFF' => 0];

    /** @var array<string, string> */
    public const array DEFAULTS = [
        self::FAIRNESS => 'HIGH',
        self::CONTRACTED => 'HIGH',
        self::PREFERENCE => 'MEDIUM',
        self::CONTINUITY => 'LOW',
        self::EXTRA_COST => 'LOW',
    ];

    /** @param  array<string, string>  $levels  criterion => level */
    public function __construct(public array $levels = self::DEFAULTS) {}

    /** Stored or submitted levels, unknown keys dropped and missing or invalid ones from the defaults. */
    public static function from(mixed $levels): self
    {
        $levels = is_array($levels) ? $levels : [];
        $clean = [];

        foreach (self::DEFAULTS as $criterion => $default) {
            $level = $levels[$criterion] ?? null;
            $clean[$criterion] = is_string($level) && isset(self::LEVELS[$level]) ? $level : $default;
        }

        return new self($clean);
    }

    public function factor(string $criterion): int
    {
        return self::LEVELS[$this->levels[$criterion] ?? 'OFF'] ?? 0;
    }

    public static function levelLabel(string $level): string
    {
        return match ($level) {
            'HIGH' => __('Mycket'),
            'MEDIUM' => __('Något'),
            'LOW' => __('Lite'),
            default => __('Inte alls'),
        };
    }

    public static function criterionLabel(string $criterion): string
    {
        return match ($criterion) {
            self::FAIRNESS => __('Jämn fördelning'),
            self::CONTRACTED => __('Avtalad tid först'),
            self::PREFERENCE => __('Önskemål'),
            self::CONTINUITY => __('Samma avdelning som vanligt'),
            default => __('Undvik mertid'),
        };
    }
}
