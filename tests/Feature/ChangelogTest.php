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

it('has no duplicate keys inside any entry of the changelog or the roadmap', function () {
    foreach (['andringslogg/andringslogg.json', 'fardplan/fardplan.json'] as $file) {
        $json = (string) file_get_contents(base_path("docs/{$file}"));

        // Every object's keys, in the order written: a repeated key inside one object means two entries were merged.
        $seen = [[]];
        $inString = false;
        $escaped = false;
        $token = '';
        $afterKey = false;

        foreach (str_split($json) as $char) {
            if ($inString) {
                if ($escaped) {
                    $token .= $char;
                    $escaped = false;
                } elseif ($char === '\\') {
                    $token .= $char;
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                    $afterKey = true;
                } else {
                    $token .= $char;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
                $token = '';
            } elseif ($char === ':' && $afterKey) {
                $depth = count($seen) - 1;
                expect(isset($seen[$depth][$token]))->toBeFalse("{$file}: the key \"{$token}\" is repeated inside one object.");
                $seen[$depth][$token] = true;
                $afterKey = false;
            } elseif ($char === '{') {
                $seen[] = [];
                $afterKey = false;
            } elseif ($char === '}') {
                array_pop($seen);
                $afterKey = false;
            } elseif (! ctype_space($char)) {
                $afterKey = false;
            }
        }
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
            "'faser' saknas.",
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

it('reports what is wrong with a phase', function () {
    $file = tempnam(sys_get_temp_dir(), 'andringslogg');
    file_put_contents($file, json_encode(['poster' => [], 'faser' => [
        ['id' => 'x', 'namn' => '', 'beskrivning' => 'B', 'fran' => '2026-1-1', 'till' => 'snart'],
    ]]));

    try {
        expect(Changelog::errors($file))->toBe([
            "fas x: 'namn' saknas.",
            "fas x: 'fran' ska vara ett datum, till exempel 2026-11-02.",
            "fas x: 'till' ska vara ett datum, till exempel 2026-11-02.",
        ]);
    } finally {
        unlink($file);
    }
});

it('accepts research entries as their own type with their own badge', function () {
    expect(Changelog::TYPES)->toHaveKey('utredning')
        ->and(Changelog::TYPES['utredning'])->toBe('Utredning')
        ->and(Changelog::AREAS)->toHaveKey('kvalitet');

    $research = array_filter(Changelog::all(), fn (array $entry): bool => $entry['typ'] === 'Utredning');
    expect($research)->not->toBeEmpty();

    $html = $this->get('/andringslogg?typ=utredning')->assertOk()
        ->assertSee('aria-current="true">Utredning', false)
        ->assertSee('class="cl-badge cl-utredning"', false)
        ->assertSee('.cl-utredning{background:var(--u-bg);color:var(--u-fg)}', false)
        ->getContent();

    expect(substr_count($html, 'class="cl-entry"'))->toBe(count($research))
        ->and(substr_count($html, '--u-bg:'))->toBe(4);

    // Research is not something that shipped: the roadmap's "Klart nyligen" leaves it out.
    foreach (Changelog::latest(10) as $entry) {
        expect($entry['typ'])->not->toBe('Utredning');
    }
});

it('dates every entry honestly: never in the future, and only work outside the code in the preparation months', function () {
    $now = CarbonImmutable::now();
    $preparation = [CarbonImmutable::parse('2026-02-01', Changelog::TIMEZONE), CarbonImmutable::parse('2026-09-30 23:59:59', Changelog::TIMEZONE)];

    foreach (Changelog::all() as $entry) {
        $date = Changelog::date($entry);

        expect($date->lessThanOrEqualTo($now))->toBeTrue("{$entry['id']} is dated in the future ({$entry['datum']}).")
            ->and(Changelog::phaseOf($entry))->not->toBeNull("{$entry['id']} lies outside every phase.");

        if (! $date->between(...$preparation)) {
            continue;
        }

        // Nothing was committed February–September 2026: only work outside the code, shown by its period, may lie there.
        expect($entry['period'] ?? null)->toBeString("{$entry['id']} is dated February–September 2026 without a period.")
            ->and($entry['utanfor_koden'] ?? null)->toBeTrue("{$entry['id']} is dated February–September 2026 but is not marked as work outside the code.")
            ->and($entry)->not->toHaveKey('version')
            ->and(Changelog::phaseOf($entry)['id'] ?? null)->toBe('forberedelser');
    }
});

it('has the preparation work outside the code as period entries', function () {
    $period = array_values(array_filter(Changelog::all(), fn (array $entry): bool => Changelog::isPeriod($entry)));

    expect($period)->toHaveCount(5);

    foreach ($period as $entry) {
        expect($entry['period'])->toBeIn(['februari 2026', 'mars 2026', 'april–maj 2026', 'augusti 2026', 'september 2026'])
            ->and($entry['utanfor_koden'])->toBeTrue()
            ->and($entry['typ'])->toBe('Utredning')
            ->and(substr($entry['datum'], 0, 7))->toBeIn(['2026-02', '2026-03', '2026-04', '2026-08', '2026-09']);
    }
});

it('reports what is wrong with a period entry', function () {
    $file = tempnam(sys_get_temp_dir(), 'andringslogg');
    $entry = ['datum' => '2026-02-01T00:00:00+01:00', 'rubrik' => 'R', 'beskrivning' => 'B.', 'typ' => 'Utredning', 'omrade' => 'Kvalitet'];
    file_put_contents($file, json_encode(['faser' => [['id' => 'f', 'namn' => 'F', 'beskrivning' => 'B', 'fran' => '2026-02-01']], 'poster' => [
        ['id' => 'ok', ...$entry, 'period' => 'februari–september 2026', 'utanfor_koden' => true],
        ['id' => 'a', ...$entry, 'period' => 'februari–september 2026'],
        ['id' => 'b', ...$entry, 'period' => '', 'utanfor_koden' => true, 'version' => 'abc1234'],
        ['id' => 'c', ...$entry, 'utanfor_koden' => true],
    ]]));

    try {
        expect(Changelog::errors($file))->toBe([
            "a: en post med 'period' ska ha 'utanfor_koden': true.",
            "b: 'period' ska vara en text, till exempel februari–september 2026.",
            "b: en post med 'period' kan inte ha 'version'.",
            "c: 'utanfor_koden' används bara tillsammans med 'period'.",
        ]);
    } finally {
        unlink($file);
    }
});

it('shows the period of work outside the code, never a made-up date or time', function () {
    $period = array_values(array_filter(Changelog::all(), fn (array $entry): bool => Changelog::isPeriod($entry)));
    $html = $this->get('/andringslogg')->assertOk()->getContent();
    $phase = pagePart($html, 'aria-labelledby="fas-forberedelser"', 'aria-labelledby="fas-grund"');

    expect($phase)->toContain('<h3 id="manad-februari-2026" class="cl-month">Februari 2026 <span>· 1 ändring</span></h3>')
        ->and($phase)->toContain('<h3 id="manad-april-maj-2026" class="cl-month">April–maj 2026 <span>· 1 ändring</span></h3>')
        ->and(strpos($phase, 'Samtal med caféer'))->toBeLessThan(strpos($phase, 'Funktionslistan'))
        ->and(strpos($phase, 'Funktionslistan'))->toBeLessThan(strpos($phase, 'Agenterna och arbetssättet'))
        ->and(strpos($phase, 'Namn, sidor och information'))->toBeLessThan(strpos($phase, 'Plan för bygget'))
        ->and(substr_count($phase, 'class="cl-entry"'))->toBe(count($period))
        ->and(substr_count($phase, 'Arbete utanför koden'))->toBe(count($period))
        ->and($phase)->not->toContain('<time')
        ->and($phase)->not->toContain('kl. ')
        ->and($phase)->not->toContain('1 februari 2026</strong>')
        ->and($html)->not->toContain('2026-02-01T00:00:00+01:00')
        ->and($html)->not->toContain('2026-09-01T00:00:00+02:00');

    foreach ($period as $entry) {
        expect($phase)->toContain('<li id="'.$entry['id'].'" class="cl-entry">')
            ->and($phase)->toContain(e($entry['rubrik']));
    }

    // The filters reach them like any other entry.
    $this->get('/andringslogg?typ=utredning&omrade=kvalitet&q=caféer')->assertOk()
        ->assertSee('Samtal med caféer, restauranger och andra företag')
        ->assertSee('Arbete utanför koden');
    $this->get('/andringslogg?typ=nytt')->assertOk()
        ->assertDontSee('Arbete utanför koden')
        ->assertDontSee('id="fas-forberedelser"', false);
});

it('leaves the work outside the code out of the RSS feed and the roadmap', function () {
    $xml = simplexml_load_string((string) $this->get('/andringslogg/rss.xml')->assertOk()->getContent());
    $guids = array_map(fn (SimpleXMLElement $item): string => (string) $item->guid, iterator_to_array($xml->channel->item, false));

    foreach (Changelog::all() as $entry) {
        if (Changelog::isPeriod($entry)) {
            expect($guids)->not->toContain("nonox-andringslogg-{$entry['id']}");
        }
    }

    expect(array_filter(Changelog::dated(), fn (array $entry): bool => Changelog::isPeriod($entry)))->toBe([])
        ->and(array_filter(Changelog::latest(100, ['Utredning']), fn (array $entry): bool => Changelog::isPeriod($entry)))->toBe([]);
});

it('groups the changes by phase under the background, with the goal first', function () {
    $phases = Changelog::phases();

    expect(array_column($phases, 'id'))->toBe(['version-1-0', 'beta-0-9', 'forberedelser', 'grund'])
        ->and($phases[0]['kommande'])->toBeTrue()
        ->and($phases[0]['fran'])->toBe('2027-02-01');

    $html = $this->get('/andringslogg')->assertOk()
        ->assertSeeInOrder([
            'Bakgrund',
            'började i januari 2026',
            'Mellan februari och september 2026 pågick förberedelserna utanför koden',
            'Från oktober 2026',
            'AI-stödd utveckling under mänsklig ledning',
            'lagtexten, BAS, SIE och Skatteverkets dokumentation',
            'öppen beta',
            'version 1.0 den 1 februari 2027',
            'aria-labelledby="fas-version-1-0"',
            'Mål · 1 februari 2027',
            'aria-labelledby="fas-beta-0-9"',
            'Sedan 29 oktober 2026',
            'aria-labelledby="fas-forberedelser"',
            '1 februari 2026 – 28 oktober 2026',
            'Förberedelser',
            'så posterna visar månaden',
            'aria-labelledby="fas-grund"',
            '11 januari 2026',
        ], false)
        ->getContent();

    expect(substr_count($html, 'aria-labelledby="fas-'))->toBe(4)
        ->and($html)->not->toContain('vilade');

    // The goal has no entries; every other phase holds exactly its own.
    foreach (Changelog::byPhase(Changelog::all()) as $group) {
        $inPhase = array_filter(Changelog::all(), fn (array $entry): bool => Changelog::phaseOf($entry)['id'] === $group['phase']['id']);
        expect($group['count'])->toBe(count($inPhase));
    }
});

it('never shows a month heading without changes', function () {
    $html = $this->get('/andringslogg')->assertOk()->getContent();
    $months = [];

    foreach (Changelog::all() as $entry) {
        $months[Changelog::isPeriod($entry) ? $entry['period'] : Changelog::date($entry)->format('Y-m')] = true;
    }

    expect(substr_count($html, 'class="cl-month"'))->toBe(count($months));

    foreach (Changelog::byPhase(Changelog::all()) as $group) {
        foreach ($group['months'] as $month) {
            expect($month['entries'])->not->toBeEmpty();
        }
    }

    // The preparation work has its months; the months without any work get no heading.
    foreach (['April', 'Maj', 'Juni', 'Juli'] as $quiet) {
        expect($html)->not->toContain(">{$quiet} 2026 <span>");
    }

    // A filter leaves out the months and phases it empties.
    $html = $this->get('/andringslogg?typ=sakerhet')->assertOk()->getContent();
    expect($html)->not->toContain('id="fas-grund"')
        ->and($html)->toContain('id="fas-version-1-0"');
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
        ->and(count($xml->channel->item))->toBe(min(50, count(Changelog::dated())));

    $first = Changelog::dated()[0];
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

it('gathers the manual, the API, security, changelog and roadmap under Dokumentation in the header, and keeps them in the footer', function () {
    $html = $this->get('/branscher')->assertOk()->getContent();
    $header = pagePart($html, '<header id="lp-header"', '</header>');
    $documentation = pagePart($header, 'id="mm-dokumentation"', '</nav>');
    $footer = pagePart($html, '<footer', '</footer>');

    foreach ([route('manual.home'), route('developers.home'), route('security'), route('changelog'), route('roadmap')] as $url) {
        expect($documentation)->toContain('href="'.$url.'"')
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
