<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Landing\Changelog;
use App\Support\Landing\LandingPage;
use App\Support\Landing\Roadmap;
use Illuminate\View\View;

/**
 * The public roadmap at /fardplan: the items in docs/fardplan/fardplan.json
 * by status, and the newest shipped features from the changelog.
 */
class RoadmapController
{
    public const int RECENT_SIZE = 8;

    public function __invoke(): View
    {
        return view('start.roadmap', [
            ...LandingPage::data(),
            'columns' => Roadmap::columns(),
            'recent' => Changelog::latest(self::RECENT_SIZE),
            'breadcrumbs' => [
                ['name' => 'Start', 'url' => url('/')],
                ['name' => 'Färdplan', 'url' => route('roadmap')],
            ],
            'pageTitle' => 'Färdplan – det vi bygger härnäst i NONOX',
            'pageDescription' => 'Det som pågår, det som är planerat och önskemålen från kunder och partner, och det som blev klart nyligen.',
        ]);
    }
}
