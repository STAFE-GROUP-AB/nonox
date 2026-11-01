<?php

declare(strict_types=1);

namespace App\Support\Overview;

use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Something that adds to "Att göra" on a company's Översikt (ADR-028).
 *
 * Merge note: the redesigned Översikt (branch feat/overview, ADR-027) has
 * its own contract, App\Domain\Overview\Contracts\PersonalTodoProvider,
 * collected through the container tag TodoProviders::TAG
 * ("overview.todo-providers"). When both branches are in main this
 * interface is replaced by that one: an implementation keeps its body and
 * wraps each array below in `new TodoItem(...)` (same keys), then is tagged
 * with TodoProviders::TAG instead of self::TAG. The array shape here is
 * that item's toArray() on purpose.
 *
 * A provider checks the user's permissions itself, returns only what the
 * user may act on in the given company, and reads with a fixed number of
 * queries whatever the amount of data. Its list may depend on who the user
 * is (tasks assigned to the user), so a cache must key it per user.
 */
interface TodoProvider
{
    /** Container tag that collects every provider. */
    public const string TAG = 'overview.todos';

    /**
     * @return list<array{key: string, icon: string, title: string, subtitle: ?string, url: string, priority: int, due: ?string}>
     */
    public function todos(User $user, Company $company, CarbonImmutable $today): array;

    /**
     * Models whose changes change the list, for whoever caches it.
     *
     * @return list<class-string<Model>>
     */
    public function invalidatedBy(): array;
}
