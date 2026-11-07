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
