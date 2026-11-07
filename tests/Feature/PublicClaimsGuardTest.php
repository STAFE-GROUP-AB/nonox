<?php

declare(strict_types=1);

use App\Support\Landing\Comparison;
use App\Support\Landing\Features;
use App\Support\Landing\Industries;
use App\Support\Landing\LandingPage;

/*
 * Guards for two wrong public claims found in the truth audit of 2026-11-07:
 * "everything is included" without the NONOX Connect cost note beside it
 * (Marknadsföringslagen 10 §), and the NE appendix as an SRU file while the
 * year's parameters are not verified.
 */

/** The "all included" phrases as a regular expression. */
function allIncludedPattern(): string
{
    return '/(alla (moduler|delar) (ingår|inräknade|med i priset)|allt ingår|allt nedan[^.]{0,60}ingår i priset|alla moduler ingår|every module|all modules (are )?included)/iu';
}

/** Page text without markup, scripts and styles, whitespace collapsed. */
function plainText(string $html): string
{
    $html = (string) preg_replace('#<(script|style|svg)\b.*?</\1>#is', ' ', $html);
    $html = (string) preg_replace('#<[^>]+>#', ' ', $html);

    return (string) preg_replace('/\s+/u', ' ', html_entity_decode($html, ENT_QUOTES | ENT_HTML5));
}

/** @return list<string> the claims that have no Connect note within $radius characters */
function claimsWithoutNote(string $text, int $radius = 700): array
{
    $missing = [];

    preg_match_all(allIncludedPattern(), $text, $matches, PREG_OFFSET_CAPTURE);

    foreach ($matches[0] as [$claim, $offset]) {
        $window = mb_substr($text, max(0, mb_strlen(substr($text, 0, $offset)) - $radius), 2 * $radius + mb_strlen($claim));

        if (! str_contains($window, 'NONOX Connect') || ! preg_match('/kostar extra|costs extra/u', $window)) {
            $missing[] = $claim;
        }
    }

    return $missing;
}

it('puts the NONOX Connect cost note beside every all-included claim on the public pages', function () {
    $paths = ['/', '/branscher', '/utvecklare', '/utvecklare?lang=en', '/flytta-hit'];

    foreach (Comparison::others() as $slug) {
        $paths[] = "/alternativ-till-{$slug}";
    }

    foreach (Industries::slugs() as $slug) {
        $paths[] = "/{$slug}";
    }

    foreach ($paths as $path) {
        $response = $this->get($path);
        $response->assertOk();

        expect(claimsWithoutNote(plainText($response->getContent())))->toBe([], "{$path} says everything is included without the Connect note beside it.");
    }
});

it('puts the Connect cost note beside all-included claims in the FAQ data and the industry files', function () {
    $texts = ['landing' => json_encode(LandingPage::data(), JSON_UNESCAPED_UNICODE)];

    foreach (glob(base_path('docs/branscher/*.json')) ?: [] as $file) {
        $texts[basename($file)] = (string) preg_replace('/^.*"comparison_features".*$/m', '', (string) file_get_contents($file));
    }

    foreach ($texts as $name => $text) {
        expect(claimsWithoutNote((string) $text))->toBe([], "{$name} has an all-included claim without the Connect note.");
    }
});

it('does not claim the NE appendix as an SRU file while no year of income tax parameters is verified', function () {
    $verified = false;

    foreach (glob(database_path('data/income-tax/*.json')) ?: [] as $file) {
        $data = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        $verified = $verified || ($data['verified'] ?? false) === true;
    }

    if ($verified) {
        $this->markTestSkipped('A year is verified; the NE claims may stand.');
    }

    expect(Features::isLive('ne_return'))->toBeFalse();

    foreach (LandingPage::INCLUDED as $item) {
        expect($item['keys'])->not->toContain('ne_return')
            ->and($item['label'])->not->toContain('enskild firma');
    }

    foreach (LandingPage::data()['features'] as $card) {
        expect($card['key'])->not->toBe('ne_return');
    }

    $row = collect(Comparison::data()['features'])->firstWhere('name', 'NE-bilaga för enskild firma (SRU)');
    expect($row['values']['nonox'])->not->toBe('yes');

    $readme = (string) file_get_contents(base_path('README.md'));
    $built = substr($readme, 0, (int) strpos($readme, '### På väg'));
    expect($built)->not->toContain('NE-bilagan för enskild firma och Inkomstdeklaration 4');

    foreach (['docs/branscher/for-redovisningsbyraer.json', 'docs/branscher/for-ekonomikonsulter.json'] as $file) {
        expect((string) file_get_contents(base_path($file)))->not->toContain('NE-bilagan för enskild firma och INK4');
    }
});
