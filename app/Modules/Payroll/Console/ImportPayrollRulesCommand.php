<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Console;

use App\Modules\Payroll\Models\PayrollRuleVersion;
use Illuminate\Console\Command;

/**
 * Reads a year's rule file (app/Modules/Payroll/database/data/{year}/rules.json)
 * into payroll_rule_versions as unverified versions. A verified version is
 * never touched. Rules count only after payroll:verify-rule.
 */
class ImportPayrollRulesCommand extends Command
{
    protected $signature = 'payroll:import-rules {year : Året, till exempel 2026}';

    protected $description = 'Läser in årets lönesatser som overifierade regler';

    public function handle(): int
    {
        $path = dirname(__DIR__)."/database/data/{$this->argument('year')}/rules.json";

        if (! is_file($path)) {
            $this->error("Filen {$path} finns inte.");

            return self::FAILURE;
        }

        /** @var list<array{key: string, valid_from: string, valid_to?: ?string, value: mixed, source_url: string, source_quote?: ?string}> $rules */
        $rules = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)['rules'] ?? [];
        $imported = 0;
        $skipped = 0;

        foreach ($rules as $rule) {
            $this->line("Läser {$rule['key']} från {$rule['valid_from']}...");
            $existing = PayrollRuleVersion::query()->where('key', $rule['key'])->whereDate('valid_from', $rule['valid_from'])->first();

            if ($existing?->isVerified()) {
                $skipped++;

                continue;
            }

            PayrollRuleVersion::query()->updateOrCreate(
                ['key' => $rule['key'], 'valid_from' => $rule['valid_from']],
                ['valid_to' => $rule['valid_to'] ?? null, 'value' => $rule['value'], 'source_url' => $rule['source_url'], 'source_quote' => $rule['source_quote'] ?? null],
            );
            $imported++;
        }

        $this->comment("{$imported} regler inlästa som overifierade, {$skipped} redan verifierade lämnades orörda.");

        return self::SUCCESS;
    }
}
