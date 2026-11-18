<?php

declare(strict_types=1);

namespace App\Domain\Review\Contracts;

use App\Domain\Review\ReviewItem;
use App\Models\Company;
use App\Models\User;

/**
 * One kind of prepared work that waits for a person, for the approval queue
 * "Att godkänna" (ADR-067 §1). The queue is a read model over what already
 * exists: a source reads its own tables, checks the user's permissions
 * itself and returns only the items the user may act on, or may read and
 * is marked read-only. It reads with a fixed number of queries whatever the
 * number of items (QUICKSTART §6).
 *
 * This is ADR-067 phase 1 (a list with links to the existing pages);
 * approving and rejecting from the queue (phase 2) go through each kind's
 * own action and are added to the contract with that phase.
 */
interface ReviewSource
{
    /** A stable key for the kind, used in the page's tabs and query string. */
    public function key(): string;

    /** The kind's name in the queue (Swedish). */
    public function label(): string;

    /** @return list<ReviewItem> */
    public function items(User $user, Company $company): array;
}
