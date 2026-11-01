<?php

declare(strict_types=1);

namespace App\Modules\Agreements\Actions;

use App\Modules\Agreements\Domain\AgreementFileValidator;
use App\Modules\Agreements\Models\AgreementRuleVersion;
use App\Modules\Agreements\Support\InvalidAgreementFile;
use App\Support\Audit\Audit;
use JsonException;

/**
 * Reads an agreement data file
 * (app/Modules/Agreements/database/data/agreements/<key>/<variant>-<valid_from>.json)
 * into agreement_rule_versions as an UNVERIFIED version (ADR-033 §2). A
 * verified version is never touched: a corrected file for an already
 * verified period must be a new version with a new valid_from.
 */
class ImportAgreementRules
{
    public const string IMPORTED = 'imported';

    public const string UPDATED = 'updated';

    public const string UNCHANGED = 'unchanged';

    /** The file differs from a version someone has verified; the verified version is kept. */
    public const string VERIFIED_KEPT = 'verified_kept';

    public function __construct(private readonly AgreementFileValidator $validator) {}

    /** @return list<string> every shipped agreement file */
    public static function shippedFiles(): array
    {
        $files = glob(dirname(__DIR__).'/database/data/agreements/*/*.json') ?: [];
        sort($files);

        return $files;
    }

    /**
     * @param  bool  $updateUnverified  false only adds versions that are missing (used when the module is turned on)
     * @return array{status: string, version: ?AgreementRuleVersion}
     *
     * @throws InvalidAgreementFile
     */
    public function __invoke(string $path, bool $updateUnverified = true): array
    {
        if (! is_file($path)) {
            throw new InvalidAgreementFile([__('Filen :file finns inte.', ['file' => basename($path)])]);
        }

        try {
            $file = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidAgreementFile([__('Filen :file är inte giltig JSON.', ['file' => basename($path)])]);
        }

        if (! is_array($file)) {
            throw new InvalidAgreementFile([__('Filen :file är inte giltig JSON.', ['file' => basename($path)])]);
        }

        $problems = $this->validator->validate($file, basename(dirname($path)));
        $variant = (string) ($file['agreement']['variant'] ?? '');
        $validFrom = (string) ($file['valid_from'] ?? '');

        if (basename($path) !== "{$variant}-{$validFrom}.json") {
            $problems[] = __('Filen ska heta :name.', ['name' => "{$variant}-{$validFrom}.json"]);
        }

        if ($problems !== []) {
            throw new InvalidAgreementFile($problems);
        }

        $sources = array_values((array) $file['sources']);
        unset($file['sources']);
        $checksum = self::checksum($file, $sources);
        $key = (string) $file['agreement']['key'];

        $existing = AgreementRuleVersion::query()
            ->where('agreement_key', $key)
            ->where('variant', $variant)
            ->whereDate('valid_from', $validFrom)
            ->first();

        if ($existing instanceof AgreementRuleVersion) {
            if ($existing->checksum === $checksum || ! $updateUnverified) {
                return ['status' => self::UNCHANGED, 'version' => $existing];
            }

            if ($existing->isVerified()) {
                return ['status' => self::VERIFIED_KEPT, 'version' => $existing];
            }

            $existing->fill(['valid_to' => $file['valid_to'] ?? null, 'rules' => $file, 'sources' => $sources, 'checksum' => $checksum])->save();
            Audit::record('agreements.version_updated', $existing, ['agreement_key' => $key, 'variant' => $variant, 'valid_from' => $validFrom, 'checksum' => $checksum]);

            return ['status' => self::UPDATED, 'version' => $existing];
        }

        $version = AgreementRuleVersion::create([
            'agreement_key' => $key,
            'variant' => $variant,
            'valid_from' => $validFrom,
            'valid_to' => $file['valid_to'] ?? null,
            'rules' => $file,
            'sources' => $sources,
            'checksum' => $checksum,
        ]);
        Audit::record('agreements.version_imported', $version, ['agreement_key' => $key, 'variant' => $variant, 'valid_from' => $validFrom, 'checksum' => $checksum]);

        return ['status' => self::IMPORTED, 'version' => $version];
    }

    /**
     * Adds every shipped version that is not in the database yet. Never
     * changes a version that is there.
     */
    public function importMissing(): int
    {
        $imported = 0;

        foreach (self::shippedFiles() as $path) {
            if (($this)($path, updateUnverified: false)['status'] === self::IMPORTED) {
                $imported++;
            }
        }

        return $imported;
    }

    /**
     * sha256 of the canonical JSON (keys sorted) of the rules and sources.
     *
     * @param  array<string, mixed>  $rules
     * @param  list<mixed>  $sources
     */
    public static function checksum(array $rules, array $sources): string
    {
        return hash('sha256', (string) json_encode(self::canonical(['rules' => $rules, 'sources' => $sources]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }
}
