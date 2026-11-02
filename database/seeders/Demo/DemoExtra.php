<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

/**
 * More demo content for some of the demo companies, so every page the
 * manual shows has something on it. Each class in Demo/Extras that extends
 * this one is picked up by DemoCompanyBuilder, for the industries it names.
 *
 * - schedule() runs while the company is built, after its registers and
 *   employees and before the year's events, and adds events with on().
 * - afterAll() runs when every demo company exists and the firms are
 *   linked to their clients.
 *
 * Everything goes through the app's own Actions, like the rest of the demo.
 */
abstract class DemoExtra
{
    /** @return list<string> industry slugs (DemoIndustries keys) this applies to */
    abstract public function industries(): array;

    public function schedule(DemoContext $context): void {}

    public function afterAll(DemoContext $context): void {}

    /** @return list<self> every extra in Demo/Extras, in file name order */
    public static function all(): array
    {
        $files = glob(__DIR__.'/Extras/*.php') ?: [];
        sort($files);

        return array_map(function (string $file): self {
            $class = __NAMESPACE__.'\\Extras\\'.basename($file, '.php');
            $extra = app($class);

            if (! $extra instanceof self) {
                throw new \LogicException("{$class} must extend DemoExtra.");
            }

            return $extra;
        }, $files);
    }

    /** @return list<self> */
    public static function for(string $slug): array
    {
        return array_values(array_filter(self::all(), fn (self $extra): bool => in_array($slug, $extra->industries(), true)));
    }
}
