<?php

declare(strict_types=1);

namespace App\Domain\Overview\Contracts;

use App\Domain\Overview\TodoItem;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Something that knows what waits in its part of the system, for "Att göra"
 * on Översikt (ADR-027). Core areas and modules each have one; a module's
 * provider is only asked while the module is on.
 *
 * A provider checks the user's permissions itself and returns only what the
 * user may act on. Its answer is cached per company, role and day, so it
 * must not depend on who the user is beyond their role; a list that does
 * (tasks delegated to a person) implements PersonalTodoProvider instead.
 * It must read with a fixed number of queries, whatever the amount of data.
 */
interface TodoProvider
{
    /** @return list<TodoItem> */
    public function todos(User $user, Company $company, CarbonImmutable $today): array;

    /**
     * Models whose changes can change the list: a save or delete of one of
     * them refreshes the company's overview after the transaction commits.
     * Everything recorded in the audit log refreshes it anyway.
     *
     * @return list<class-string<Model>>
     */
    public function invalidatedBy(): array;
}
