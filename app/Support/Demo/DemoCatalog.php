<?php

declare(strict_types=1);

namespace App\Support\Demo;

use App\Support\Landing\Industries;
use Database\Seeders\Demo\DemoIndustries;

/**
 * The demo companies a visitor may choose between (ADR-077): the industry
 * demos that are one company. The two accounting-firm demos need several
 * companies per visitor and are left out.
 */
final class DemoCatalog
{
    /** @return array<string, string> industry slug => the industry's name, in menu order */
    public static function industries(): array
    {
        $demos = DemoIndustries::all();
        $industries = [];

        foreach (Industries::all() as $slug => $industry) {
            if (isset($demos[$slug]) && ! ($demos[$slug]['is_firm'] ?? false)) {
                $industries[$slug] = $industry['name'];
            }
        }

        return $industries;
    }
}
