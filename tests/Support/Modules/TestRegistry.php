<?php

declare(strict_types=1);

namespace Tests\Support\Modules;

use App\Modules\Contracts\Module;
use App\Modules\ModuleManager;
use App\Modules\ModuleRegistry;

/**
 * A module registry for tests: the installed modules, with the test modules'
 * unprefixed keys (`dummy`) and classes treated as first-party (ADR-079 §2.2).
 */
final class TestRegistry
{
    /**
     * A new registry holding the installed modules.
     *
     * @param  list<string>  $firstParty  test keys to treat as first-party
     */
    public static function make(array $firstParty = []): ModuleRegistry
    {
        $registry = new ModuleRegistry([...ModuleRegistry::FIRST_PARTY, ...$firstParty], [ModuleRegistry::FIRST_PARTY_NAMESPACE, 'Tests\\Support\\Modules\\']);

        foreach (app(ModuleRegistry::class)->all() as $module) {
            $registry->register($module);
        }

        return $registry;
    }

    /**
     * Installs the test modules in the application's registry.
     *
     * @param  list<string>  $firstParty
     */
    public static function install(array $firstParty, Module ...$modules): ModuleRegistry
    {
        $registry = self::make($firstParty);

        foreach ($modules as $module) {
            $registry->register($module);
        }

        app()->instance(ModuleRegistry::class, $registry);
        app()->forgetInstance(ModuleManager::class);

        return $registry;
    }
}
