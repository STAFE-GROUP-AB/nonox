<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Landing\Changelog;
use App\Support\Landing\LandingPage;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * The public changelog (/andringslogg) and its RSS feed, rendered on the
 * server from docs/andringslogg/andringslogg.json, so the page works without
 * JavaScript and every filter is a plain query string.
 */
class ChangelogController
{
    public const int FEED_SIZE = 50;

    public function index(Request $request): View
    {
        $type = self::slug($request->query('typ'), Changelog::TYPES);
        $area = self::slug($request->query('omrade'), Changelog::AREAS);
        $search = mb_substr(trim((string) (is_string($request->query('q')) ? $request->query('q') : '')), 0, 100);
        $entries = Changelog::filter($type, $area, $search);
        $all = Changelog::all();

        return view('start.changelog', [
            ...LandingPage::data(),
            'phases' => Changelog::byPhase($entries),
            'shown' => count($entries),
            'total' => count($all),
            'first' => $all === [] ? null : Changelog::date($all[array_key_last($all)]),
            'type' => $type,
            'area' => $area,
            'search' => $search,
            'filtered' => $type !== null || $area !== null || $search !== '',
            'breadcrumbs' => [
                ['name' => 'Start', 'url' => url('/')],
                ['name' => 'Ändringslogg', 'url' => route('changelog')],
            ],
            'canonical' => route('changelog'),
            'pageTitle' => 'Ändringslogg – allt vi har byggt i NONOX',
            'pageDescription' => 'Varje ny funktion, förbättring, rättelse och utredning i NONOX med datum och tid, på vanlig svenska. Hämtat ur det vi faktiskt levererar.',
        ]);
    }

    public function feed(): Response
    {
        $entries = array_slice(Changelog::all(), 0, self::FEED_SIZE);
        $updated = $entries === [] ? now() : Changelog::date($entries[0]);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".view('start.changelog-feed', [
            'entries' => $entries,
            'updated' => $updated,
        ])->render();

        return response($xml, 200, ['Content-Type' => 'application/rss+xml; charset=UTF-8']);
    }

    /** @param  array<string, string>  $allowed */
    private static function slug(mixed $value, array $allowed): ?string
    {
        return is_string($value) && array_key_exists($value, $allowed) ? $value : null;
    }
}
