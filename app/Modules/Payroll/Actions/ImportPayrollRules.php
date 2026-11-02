<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Modules\Payroll\Models\PayrollRuleVerification;
use App\Modules\Payroll\Models\PayrollRuleVersion;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Reads a year's rule file (database/data/{year}/rules.json) into
 * payroll_rule_versions as unverified versions (PAYROLL_MODULE.md B.6).
 * Idempotent, so it can run on every deploy and every night: an unchanged
 * version is left as it is, and a version that someone has checked, for the
 * installation or for one company, is never changed. A correction to such a
 * version must be a new version with its own valid_from.
 */
final class ImportPayrollRules
{
    /**
     * @return list<int>
     */
    public static function years(): array
    {
        $years = array_map('intval', array_map('basename', glob(self::directory().'/*', GLOB_ONLYDIR) ?: []));
        sort($years);

        return array_values(array_filter($years, fn (int $year): bool => is_file(self::path($year))));
    }

    public static function path(int $year): string
    {
        return self::directory()."/{$year}/rules.json";
    }

    /**
     * The rule file as data: rules, plus the keys the authorities have not
     * published yet (pending) and the keys an open-ended earlier version
     * still covers (continued).
     *
     * @return array{rules: list<array{key: string, valid_from: string, valid_to?: ?string, value: mixed, source_url: string, source_quote?: ?string}>, pending: list<array{key: string, authority?: string, expected?: string, source_url?: string, note?: string}>, continued: list<array{keys: list<string>, source_url?: string, note?: string}>}
     */
    public static function file(int $year): array
    {
        $path = self::path($year);

        if (! is_file($path)) {
            throw new InvalidArgumentException("No rule file for {$year}.");
        }

        $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        /** @var array{rules: list<array{key: string, valid_from: string, valid_to?: ?string, value: mixed, source_url: string, source_quote?: ?string}>, pending: list<array{key: string, authority?: string, expected?: string, source_url?: string, note?: string}>, continued: list<array{keys: list<string>, source_url?: string, note?: string}>} */
        return [
            'rules' => array_values((array) ($data['rules'] ?? [])),
            'pending' => array_values((array) ($data['pending'] ?? [])),
            'continued' => array_values((array) ($data['continued'] ?? [])),
        ];
    }

    /**
     * @return array{imported: int, unchanged: int, checked: int}
     */
    public function __invoke(int $year, ?callable $progress = null): array
    {
        $counts = ['imported' => 0, 'unchanged' => 0, 'checked' => 0];

        DB::transaction(function () use ($year, $progress, &$counts): void {
            foreach (self::file($year)['rules'] as $rule) {
                if ($progress !== null) {
                    $progress($rule);
                }

                $counts[$this->importOne($rule)]++;
            }
        });

        return $counts;
    }

    /**
     * @param  array{key: string, valid_from: string, valid_to?: ?string, value: mixed, source_url: string, source_quote?: ?string}  $rule
     * @return 'imported'|'unchanged'|'checked'
     */
    private function importOne(array $rule): string
    {
        $attributes = [
            'valid_to' => $rule['valid_to'] ?? null,
            'value' => $rule['value'],
            'source_url' => $rule['source_url'],
            'source_quote' => $rule['source_quote'] ?? null,
        ];
        $existing = PayrollRuleVersion::query()->where('key', $rule['key'])->whereDate('valid_from', $rule['valid_from'])->lockForUpdate()->first();

        if (! $existing instanceof PayrollRuleVersion) {
            PayrollRuleVersion::create(['key' => $rule['key'], 'valid_from' => $rule['valid_from'], ...$attributes]);

            return 'imported';
        }

        // Checks by companies are company data; reading them across companies here only answers "has anyone checked this version".
        $checkedByCompany = CompanyScope::bypass(fn (): bool => PayrollRuleVerification::query()->where('payroll_rule_version_id', $existing->id)->exists());

        if ($existing->isVerified() || $checkedByCompany) {
            return 'checked';
        }

        if ($this->same($existing, $attributes)) {
            return 'unchanged';
        }

        $existing->forceFill($attributes)->save();

        return 'imported';
    }

    /**
     * @param  array{valid_to: ?string, value: mixed, source_url: string, source_quote: ?string}  $attributes
     */
    private function same(PayrollRuleVersion $existing, array $attributes): bool
    {
        return $existing->valid_to?->toDateString() === $attributes['valid_to']
            && $existing->source_url === $attributes['source_url']
            && $existing->source_quote === $attributes['source_quote']
            && self::canonical($existing->value) === self::canonical($attributes['value']);
    }

    /**
     * JSON with object keys sorted, since PostgreSQL's jsonb does not keep
     * the key order of the file.
     */
    private static function canonical(mixed $value): string
    {
        $sort = function (mixed $item) use (&$sort): mixed {
            if (! is_array($item)) {
                return $item;
            }

            if (! array_is_list($item)) {
                ksort($item);
            }

            return array_map($sort, $item);
        };

        return (string) json_encode($sort($value));
    }

    private static function directory(): string
    {
        return dirname(__DIR__).'/database/data';
    }
}
