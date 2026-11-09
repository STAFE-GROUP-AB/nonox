<?php

declare(strict_types=1);

namespace App\Migration\Import\Http;

use App\Migration\Import\Guides\FlyttGuider;
use App\Support\Landing\LandingPage;
use Illuminate\View\View;

/**
 * /flytta-fran/{program}: one moving guide per program, generated from
 * docs/migration/flyttguider.json (ADR-076). Behind an instance setting that
 * is off by default; while it is off the pages answer 404 as if they did not
 * exist.
 */
class FlyttGuideController
{
    public function index(): View
    {
        abort_unless(FlyttGuider::enabled(), 404);

        return view('import::guides.index', [
            ...LandingPage::data(),
            'programs' => FlyttGuider::programs(),
            'breadcrumbs' => [
                ['name' => 'Start', 'url' => url('/')],
                ['name' => 'Flytta hit', 'url' => route('migrationGuide')],
                ['name' => 'Flytta från ett annat program', 'url' => route('migrationFrom.index')],
            ],
            'pageTitle' => 'Flytta från ett annat program – vad respektive program beskriver',
            'pageDescription' => 'Vad bokföringsprogram själva beskriver om att ta ut bokföring, register och underlag, med källa och datum.',
        ]);
    }

    public function show(string $program): View
    {
        abort_unless(FlyttGuider::enabled(), 404);

        $guide = FlyttGuider::find($program);

        abort_if($guide === null, 404);

        return view('import::guides.show', [
            ...LandingPage::data(),
            'program' => $guide,
            'breadcrumbs' => [
                ['name' => 'Start', 'url' => url('/')],
                ['name' => 'Flytta hit', 'url' => route('migrationGuide')],
                ['name' => 'Flytta från ett annat program', 'url' => route('migrationFrom.index')],
                ['name' => $guide['name'], 'url' => route('migrationFrom.show', $guide['slug'])],
            ],
            'pageTitle' => 'Flytta från '.$guide['name'].' till NONOX',
            'pageDescription' => 'Vad '.$guide['name'].' själva beskriver om att ta ut bokföring, register och underlag, med källa och datum.',
        ]);
    }
}
