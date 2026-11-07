<?php

declare(strict_types=1);

use App\Support\Portability\SchemaValidator;

it('publishes only ECMA-262 compatible patterns', function () {
    $schema = (string) file_get_contents(base_path('docs/portability/nonox-dump.schema.json'));
    preg_match_all('/"pattern":\s*"((?:[^"\\\\]|\\\\.)*)"/', $schema, $matches);
    $patterns = array_map(fn (string $pattern): string => (string) json_decode('"'.$pattern.'"'), $matches[1]);

    expect($patterns)->not->toBeEmpty();

    foreach ($patterns as $pattern) {
        expect($pattern)->not->toMatch('/\\\\[AzZGhHRXK]|\(\?[>|+P#a-zA-Z]|[*+?}]\+|\\\\p\{/', "{$pattern} uses a PCRE-only construct");
    }
});

it('does not let a trailing newline pass a dollar anchored pattern', function () {
    $validator = new SchemaValidator(['type' => 'string', 'pattern' => '^[0-9a-f]{4}$']);

    expect($validator->validateJson('"abcd"'))->toBe([])
        ->and($validator->validateJson('"abcd\n"'))->not->toBe([]);
});

it('refuses definitions anywhere but the schema root, and has no assoc entry point', function () {
    expect(fn () => new SchemaValidator(['properties' => ['a' => ['$defs' => ['x' => ['type' => 'string']]]]]))->toThrow(InvalidArgumentException::class)
        ->and((new ReflectionClass(SchemaValidator::class))->hasMethod('validate'))->toBeFalse();
});

it('accepts a 1.0.0 fiscal year without reporting_framework, and checks the value when it is there', function () {
    $published = json_decode((string) file_get_contents(base_path('docs/portability/nonox-dump.schema.json')), true);
    $validator = new SchemaValidator(['$defs' => $published['$defs'], 'type' => 'object', 'properties' => ['year' => ['$ref' => '#/$defs/fiscalYear']]]);
    $year = ['id' => 1, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'OPEN', 'closed_at' => null, 'closed_by' => null, 'ledger_head_hash' => null];

    expect($validator->validateJson((string) json_encode(['year' => $year])))->toBe([])
        ->and($validator->validateJson((string) json_encode(['year' => [...$year, 'reporting_framework' => 'K3']])))->toBe([])
        ->and($validator->validateJson((string) json_encode(['year' => [...$year, 'reporting_framework' => 'K4']])))->not->toBe([]);
});
