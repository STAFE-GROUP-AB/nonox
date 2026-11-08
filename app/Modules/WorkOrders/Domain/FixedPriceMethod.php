<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Domain;

/**
 * The company's one method for all fixed-price jobs at year-end (K2 6.15,
 * K3 23.31, AO-R31, AO-R33). Stored in the module's settings; the
 * year-end step only proposes, it never books.
 *
 * TODO(legal-verify): L13 — which proposals are right per framework; K1 is
 * not covered and gets figures without a proposal.
 */
enum FixedPriceMethod: string
{
    case NotSet = 'NOT_SET';

    /** K2 huvudregeln: revenue by degree of completion (K2 6.16–6.17). */
    case K2Main = 'K2_MAIN';

    /** K2 alternativregeln: revenue when the work is essentially complete (K2 6.22). */
    case K2Alternative = 'K2_ALTERNATIVE';

    /** K3: revenue by degree of completion (K3 23.17). */
    case K3Main = 'K3_MAIN';

    /** K3 färdigställandemetoden for legal persons (K3 23.31). */
    case K3Completion = 'K3_COMPLETION';

    public function label(): string
    {
        return match ($this) {
            self::NotSet => __('Inte valt'),
            self::K2Main => __('K2 huvudregeln (successiv vinstavräkning)'),
            self::K2Alternative => __('K2 alternativregeln (intäkt när arbetet är i allt väsentligt klart)'),
            self::K3Main => __('K3 successiv vinstavräkning'),
            self::K3Completion => __('K3 färdigställandemetoden'),
        };
    }

    /** Revenue is recognised as the work proceeds. */
    public function recognisesProgress(): bool
    {
        return $this === self::K2Main || $this === self::K3Main;
    }

    /** Revenue waits until the work is essentially complete. */
    public function waitsForCompletion(): bool
    {
        return $this === self::K2Alternative || $this === self::K3Completion;
    }
}
