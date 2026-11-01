<?php

declare(strict_types=1);

namespace App\Domain\Overview\Contracts;

/**
 * A provider whose list belongs to one person, not to a role: tasks
 * delegated to the user, for example. Its answer is cached per user and
 * company instead of being shared by everyone with the same role. Register
 * one by tagging it with TodoProviders::TAG in a service provider.
 */
interface PersonalTodoProvider extends TodoProvider {}
