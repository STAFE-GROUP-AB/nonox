<?php

declare(strict_types=1);

use Tests\Support\PricingScenario;

/*
 * ADR-082 §5 (P2): golden tests. Each synthetic fixture in
 * tests/Fixtures/pricing/ builds a company's articles, lists, groups,
 * campaigns and agreements, prices its rows and compares every row with the
 * price, discount, source, explanation and warnings it expects.
 */

$fixtures = array_map(fn (string $path): string => basename($path, '.json'), glob(__DIR__.'/../../Fixtures/pricing/*.json') ?: []);

it('prices every row of the fixture as expected', function (string $name) {
    $fixture = PricingScenario::fixture($name);
    $scenario = PricingScenario::build($fixture);

    foreach ($fixture['cases'] as $case) {
        $requests = array_map(fn (array $row) => $scenario->request($row), $case['rows']);
        $resolved = $scenario->resolve($case['context'], $requests);

        expect(array_keys($resolved))->toBe(array_map(fn (array $row): string => $row['key'], $case['rows']));

        foreach ($case['rows'] as $row) {
            expect($scenario->describe($resolved[$row['key']]))->toEqual($row['expected'], "{$name}: {$row['key']} on {$case['context']['date']}");
        }
    }
})->with($fixtures);

it('has golden fixtures to run', function () use ($fixtures) {
    expect(count($fixtures))->toBeGreaterThanOrEqual(8);
});
