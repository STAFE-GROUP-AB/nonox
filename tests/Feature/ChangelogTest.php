<?php

declare(strict_types=1);

use App\Http\Controllers\RoadmapController;
use App\Models\User;
use App\Support\Landing\Changelog;
use App\Support\Landing\LandingPage;
use App\Support\Landing\Roadmap;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Process;

/*
 * The public changelog (/andringslogg, docs/andringslogg) and roadmap
 * (/fardplan, docs/fardplan): both files follow their schema, the pages and
 * the feed render from them, the filters work as plain query strings, and the
 * changelog keeps up with what is actually committed.
 */

beforeEach(function () {
    config(['app.landing.enabled' => true]);
});

/** The part of a public page between two tags, such as its header or footer. */
function pagePart(string $html, string $open, string $close): string
{
    $start = strpos($html, $open);
    expect($start)->not->toBeFalse("'{$open}' is missing.");
    $end = strpos($html, $close, (int) $start);

    return substr($html, (int) $start, (int) $end - (int) $start + strlen($close));
}

it('has a changelog that follows its schema', function () {
    expect(Changelog::errors())->toBe([])
        ->and(count(Changelog::all()))->toBeGreaterThan(50);

    foreach (Changelog::all() as $entry) {
        expect(mb_strlen($entry['rubrik']))->toBeLessThanOrEqual(90, "{$entry['id']}: the heading is too long.")
            ->and(preg_match_all('/[.!?](\s|$)/u', $entry['beskrivning']))->toBeBetween(1, 3, "{$entry['id']}: write one to three sentences.");
    }
});

it('has a roadmap that follows its schema', function () {
    expect(Roadmap::errors())->toBe([])
        ->and(Roadmap::all())->not->toBeEmpty();

    foreach (Roadmap::STATUSES as $status) {
        expect(Roadmap::columns())->toHaveKey($status);
    }
});

it('reports what is wrong with an entry', function () {
    $file = tempnam(sys_get_temp_dir(), 'andringslogg');
    file_put_contents($file, json_encode(['poster' => [
        ['id' => 'a', 'datum' => '2026-11-02 12:00', 'rubrik' => 'R', 'beskrivning' => 'B.', 'typ' => 'Okänd', 'omrade' => 'Moms', 'version' => 'not-a-hash'],
        ['id' => 'a', 'datum' => '2026-11-02T12:00:00+01:00', 'rubrik' => '', 'beskrivning' => 'B.', 'typ' => 'Nytt', 'omrade' => 'Inget'],
    ]]));

    try {
        expect(Changelog::errors($file))->toBe([
            "a: 'version' ska vara en kort commit-hash.",
            'a: okänd typ.',
            "a: 'datum' ska vara ISO 8601 med tidszon, till exempel 2026-11-02T12:00:00+01:00.",
            "a: 'rubrik' saknas.",
            'a: okänt område.',
            'a: id finns redan.',
        ]);
    } finally {
        unlink($file);
    }
});

it('never mentions GitHub in the changelog or the roadmap', function () {
    expect((string) file_get_contents(base_path(Changelog::FILE)))->not->toContain('github.com')
        ->and((string) file_get_contents(base_path(Roadmap::FILE)))->not->toContain('github.com');

    foreach ([route('changelog'), route('roadmap'), route('changelog.feed')] as $url) {
        $html = $this->get($url)->assertOk()->getContent();
        $content = str_contains($html, '<main') ? pagePart($html, '<main', '</main>') : $html;

        expect($content)->not->toContain('github.com');
    }
});

it('shows every change newest first, grouped by month, with date and time', function () {
    $entries = Changelog::all();
    $newest = $entries[0];
    $oldest = $entries[array_key_last($entries)];
    $date = Changelog::date($newest);

    $html = $this->get('/andringslogg')
        ->assertOk()
        ->assertSee('<title>Ändringslogg – allt vi har byggt i NONOX</title>', false)
        ->assertSee('<link rel="canonical" href="'.route('changelog').'">', false)
        ->assertSee('Allt vi har byggt')
        ->assertSee('tas fram ur det vi faktiskt levererar')
        ->assertSee('<time datetime="'.$date->format('c').'">', false)
        ->assertSee('kl. '.$date->format('H:i'))
        ->assertSee(count($entries).' ändringar sedan')
        ->assertSee('type="application/rss+xml"', false)
        ->assertSee('class="cl-month"', false)
        ->assertSeeInOrder([$newest['rubrik'], $oldest['rubrik']])
        ->getContent();

    expect(substr_count($html, '<h1'))->toBe(1)
        ->and(substr_count($html, 'class="cl-entry"'))->toBe(count($entries))
        ->and(substr_count($html, 'class="cl-month"'))->toBe(count(Changelog::byMonth($entries)));
});

it('filters by type, area and search text in the query string', function () {
    $html = $this->get('/andringslogg?typ=sakerhet')->assertOk()->assertSee('aria-current="true">Säkerhet', false)->getContent();
    $security = array_filter(Changelog::all(), fn (array $entry): bool => $entry['typ'] === 'Säkerhet');
    expect(substr_count($html, 'class="cl-entry"'))->toBe(count($security))
        ->and($html)->toContain('Visar '.count($security).' av '.count(Changelog::all()).' ändringar');

    $html = $this->get('/andringslogg?omrade=lon')->assertOk()->assertSee('<option value="lon" selected>Lön</option>', false)->getContent();
    $payroll = array_filter(Changelog::all(), fn (array $entry): bool => $entry['omrade'] === 'Lön');
    expect(substr_count($html, 'class="cl-entry"'))->toBe(count($payroll));

    $this->get('/andringslogg?typ=nytt&omrade=personalliggare')->assertOk()
        ->assertSee('Personalliggare')
        ->assertDontSee('Stabilare kiosk');

    $this->get('/andringslogg?q=semesterlagen')->assertOk()
        ->assertSee('Semester enligt semesterlagen')
        ->assertDontSee('Bankkoppling');

    $this->get('/andringslogg?q=finnsinteallsnagonstans')->assertOk()->assertSee('Inga ändringar matchar.');

    // An unknown filter is ignored rather than showing an empty page.
    $html = $this->get('/andringslogg?typ=okand&omrade[]=x')->assertOk()->getContent();
    expect(substr_count($html, 'class="cl-entry"'))->toBe(count(Changelog::all()));
});

it('keeps the other filters in every filter link', function () {
    $this->get('/andringslogg?omrade=moms&q=valuta')->assertOk()
        ->assertSee(e(route('changelog').'?typ=rattat&omrade=moms&q=valuta'), false)
        ->assertSee(e(route('changelog').'?omrade=moms&q=valuta'), false);
});

it('publishes the changelog as a valid RSS feed', function () {
    $response = $this->get('/andringslogg/rss.xml')->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/rss+xml; charset=UTF-8');

    $xml = simplexml_load_string((string) $response->getContent());
    expect($xml)->not->toBeFalse()
        ->and($xml->getName())->toBe('rss')
        ->and((string) $xml['version'])->toBe('2.0')
        ->and((string) $xml->channel->title)->toBe('NONOX ändringslogg')
        ->and((string) $xml->channel->link)->toBe(route('changelog'))
        ->and(count($xml->channel->item))->toBe(min(50, count(Changelog::all())));

    $first = Changelog::all()[0];
    $item = $xml->channel->item[0];
    expect((string) $item->title)->toBe("{$first['typ']}: {$first['rubrik']}")
        ->and((string) $item->link)->toBe(route('changelog').'#'.$first['id'])
        ->and((string) $item->guid)->toBe("nonox-andringslogg-{$first['id']}")
        ->and(CarbonImmutable::createFromFormat(DATE_RFC2822, (string) $item->pubDate)?->equalTo(Changelog::date($first)))->toBeTrue();

    $guids = array_map(fn (SimpleXMLElement $item): string => (string) $item->guid, iterator_to_array($xml->channel->item, false));
    expect($guids)->toBe(array_unique($guids));
});

it('shows the roadmap by status, with what was finished recently', function () {
    $html = $this->get('/fardplan')
        ->assertOk()
        ->assertSee('<title>Färdplan – det vi bygger härnäst i NONOX</title>', false)
        ->assertSee('Önskemål läggs in av oss när de kommer in från kunder och partner')
        ->assertSee('href="'.e(LandingPage::links()['contact']).'"', false)
        ->assertSeeInOrder(['Pågår', 'Planerat', 'Önskemål', 'Klart nyligen'])
        ->getContent();

    foreach (Roadmap::all() as $item) {
        expect($html)->toContain(e($item['rubrik']))
            ->and($html)->toContain('<time datetime="'.$item['uppdaterad'].'">');
    }

    $recent = Changelog::latest(RoadmapController::RECENT_SIZE);
    expect($recent)->toHaveCount(RoadmapController::RECENT_SIZE);

    foreach ($recent as $entry) {
        expect(['Nytt', 'Förbättrat'])->toContain($entry['typ'])
            ->and($html)->toContain(route('changelog').'#'.$entry['id']);
    }
});

it('lists the changelog and the roadmap in the sitemap', function () {
    $this->get('/sitemap.xml')->assertOk()
        ->assertSee('<loc>'.route('changelog').'</loc>', false)
        ->assertSee('<loc>'.route('roadmap').'</loc>', false)
        ->assertDontSee('rss.xml');
});

it('moves the manual, the API, security, changelog and roadmap to the footer', function () {
    $html = $this->get('/branscher')->assertOk()->getContent();
    $header = pagePart($html, '<header id="lp-header"', '</header>');
    $footer = pagePart($html, '<footer', '</footer>');

    foreach ([route('manual.home'), route('developers.home'), route('security'), route('changelog'), route('roadmap')] as $url) {
        expect($header)->not->toContain('href="'.$url.'"')
            ->and($footer)->toContain('href="'.$url.'"');
    }

    expect($footer)->toContain('aria-label="Resurser"')
        ->and($footer)->toContain('>Manual</a>')
        ->and($footer)->toContain('>Ändringslogg</a>')
        ->and($footer)->toContain('>Färdplan</a>')
        ->and($footer)->not->toContain('>Dokumentation</a>')
        ->and($header)->toContain('Funktioner')
        ->and($header)->toContain('Vanliga frågor');
});

it('points readers of the landing page to the changelog and the roadmap', function () {
    app()->detectEnvironment(fn (): string => 'production');
    User::factory()->withCompany()->create();

    $html = $this->get('/')->assertOk()->assertSee('Läser du hellre än kodar?')->getContent();
    $main = pagePart($html, '<main', '</main>');

    expect($main)->toContain('href="'.route('changelog').'"')
        ->and($main)->toContain('href="'.route('roadmap').'"');
});

it('has a share image for both pages', function () {
    $this->get('/andringslogg')->assertOk()->assertSee(asset('og/andringslogg.png'), false);
    $this->get('/fardplan')->assertOk()->assertSee(asset('og/fardplan.png'), false);
});

it('has a changelog entry for the newest feat or fix commit', function () {
    try {
        $shallow = Process::path(base_path())->run(['git', 'rev-parse', '--is-shallow-repository']);
    } catch (Throwable) {
        $this->markTestSkipped('git is not available.');
    }

    // CI checks out one commit only; without the history there is nothing to compare with.
    if (! $shallow->successful() || trim($shallow->output()) !== 'false') {
        $this->markTestSkipped('No git history to compare with.');
    }

    $log = Process::path(base_path())->run(['git', 'log', '-n', '200', '-E', '--grep=^(feat|fix)(\(.+\))?!?:', '--format=%aI']);
    $dates = array_map(fn (string $line): CarbonImmutable => CarbonImmutable::parse($line), array_filter(explode("\n", trim($log->output()))));

    if ($dates === []) {
        $this->markTestSkipped('No feat or fix commits in the history.');
    }

    $newestCommit = max($dates);
    $newestEntry = Changelog::date(Changelog::all()[0]);

    expect($newestEntry->greaterThanOrEqualTo($newestCommit->subDay()))->toBeTrue(
        "The newest feat/fix commit is from {$newestCommit->toIso8601String()}, the newest changelog entry from {$newestEntry->toIso8601String()}. Add a Swedish entry to docs/andringslogg/andringslogg.json (CONTRIBUTING.md)."
    );
});
