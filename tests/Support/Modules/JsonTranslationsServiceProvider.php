<?php

declare(strict_types=1);

namespace Tests\Support\Modules;

use Illuminate\Support\ServiceProvider;

/**
 * A package provider that adds its JSON translations in boot(), as
 * loadJsonTranslationsFrom() is meant to be used. The module check runs
 * before any provider boots and must not leave translations cached that
 * would hide these.
 */
final class JsonTranslationsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadJsonTranslationsFrom(base_path('tests/Fixtures/lang/third-party'));
    }
}
