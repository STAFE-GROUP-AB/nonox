<?php

declare(strict_types=1);

namespace App\Domain\Overview;

use App\Domain\Overview\Contracts\PersonalTodoProvider;
use App\Domain\Overview\Contracts\TodoProvider;
use App\Domain\Overview\Todos\BankTodos;
use App\Domain\Overview\Todos\CompanyTasks;
use App\Domain\Overview\Todos\GuideTodos;
use App\Domain\Overview\Todos\OpeningBalanceTodos;
use App\Models\Company;
use App\Modules\Contracts\ProvidesTodos;
use App\Modules\ModuleManager;
use App\Modules\ModuleRegistry;
use Illuminate\Contracts\Container\Container;

/**
 * Every provider of "Att göra": the core ones, those of the modules that are
 * on for the company, and any tagged with TAG (for example tasks delegated
 * to a person, which implement PersonalTodoProvider).
 */
final class TodoProviders
{
    /** Container tag for extra providers. */
    public const string TAG = 'overview.todo-providers';

    /** @var list<class-string<TodoProvider>> */
    private const array CORE = [CompanyTasks::class, BankTodos::class, OpeningBalanceTodos::class, GuideTodos::class];

    public function __construct(
        private readonly Container $container,
        private readonly ModuleRegistry $modules,
        private readonly ModuleManager $manager,
    ) {}

    /**
     * The providers shared by everyone with the same role in the company.
     *
     * @return list<TodoProvider>
     */
    public function shared(Company $company): array
    {
        return array_values(array_filter($this->all($company), fn (TodoProvider $provider): bool => ! $provider instanceof PersonalTodoProvider));
    }

    /** @return list<PersonalTodoProvider> */
    public function personal(Company $company): array
    {
        return array_values(array_filter($this->all($company), fn (TodoProvider $provider): bool => $provider instanceof PersonalTodoProvider));
    }

    /**
     * Every provider that can exist, whatever the company, for the models
     * that refresh the overview.
     *
     * @return list<TodoProvider>
     */
    public function installed(): array
    {
        return [...$this->core(), ...$this->moduleProviders(null), ...$this->tagged()];
    }

    /** @return list<TodoProvider> */
    private function all(Company $company): array
    {
        return [...$this->core(), ...$this->moduleProviders($company), ...$this->tagged()];
    }

    /** @return list<TodoProvider> */
    private function core(): array
    {
        return array_map(fn (string $class): TodoProvider => $this->container->make($class), self::CORE);
    }

    /**
     * The providers of the modules that are on for the company; every
     * installed module's when no company is given.
     *
     * @return list<TodoProvider>
     */
    private function moduleProviders(?Company $company): array
    {
        $providers = [];

        foreach ($this->modules->all() as $key => $module) {
            if (! $module instanceof ProvidesTodos) {
                continue;
            }

            if ($company !== null && ! $this->manager->isEnabled($key, $company)) {
                continue;
            }

            $providers[] = $module->todoProvider();
        }

        return $providers;
    }

    /** @return list<TodoProvider> */
    private function tagged(): array
    {
        $providers = [];

        foreach ($this->container->tagged(self::TAG) as $provider) {
            if ($provider instanceof TodoProvider) {
                $providers[] = $provider;
            }
        }

        return $providers;
    }
}
