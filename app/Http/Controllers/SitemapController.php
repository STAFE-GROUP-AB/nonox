<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Landing\Comparison;
use Illuminate\Http\Response;

/**
 * sitemap.xml and robots.txt for the public pages, with this
 * installation's own address. Nothing is listed when the landing page is
 * turned off.
 */
class SitemapController
{
    public function sitemap(): Response
    {
        $urls = config('app.landing.enabled')
            ? [url('/'), route('migrationGuide'), ...array_map(fn (string $slug): string => route('alternative', $slug), Comparison::others())]
            : [];

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($urls as $url) {
            $xml .= '  <url><loc>'.e($url).'</loc></url>'."\n";
        }

        return response($xml.'</urlset>'."\n", 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    public function robots(): Response
    {
        $body = config('app.landing.enabled')
            ? "User-agent: *\nDisallow: /livewire/\nDisallow: /api/\nAllow: /\n\nSitemap: ".url('/sitemap.xml')."\n"
            : "User-agent: *\nDisallow: /\n";

        return response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
