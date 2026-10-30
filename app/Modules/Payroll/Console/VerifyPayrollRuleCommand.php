<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Console;

use App\Modules\Payroll\Models\PayrollRuleVersion;
use App\Support\Audit\Audit;
use Illuminate\Console\Command;

/**
 * Marks a rule version as verified by a named, qualified person after they
 * have checked the value against its source (PAYROLL_MODULE.md B.6). Only
 * then is it used in payroll runs. The value can never change afterwards.
 */
class VerifyPayrollRuleCommand extends Command
{
    protected $signature = 'payroll:verify-rule {key} {valid_from : ÅÅÅÅ-MM-DD} {--by= : Namnet på den som har kontrollerat värdet mot källan}';

    protected $description = 'Markerar en lönesats som kontrollerad mot källan';

    public function handle(): int
    {
        $by = trim((string) $this->option('by'));

        if ($by === '') {
            $this->error('Ange vem som har kontrollerat värdet med --by="Namn".');

            return self::INVALID;
        }

        $rule = PayrollRuleVersion::query()->where('key', $this->argument('key'))->whereDate('valid_from', (string) $this->argument('valid_from'))->first();

        if (! $rule instanceof PayrollRuleVersion) {
            $this->error('Regeln finns inte.');

            return self::FAILURE;
        }

        if ($rule->isVerified()) {
            $this->info("Regeln är redan kontrollerad av {$rule->verified_by}.");

            return self::SUCCESS;
        }

        $this->line("{$rule->key} från {$rule->valid_from->toDateString()}: ".json_encode($rule->value, JSON_UNESCAPED_UNICODE));
        $this->line("Källa: {$rule->source_url}");

        $rule->forceFill(['verified_by' => $by, 'verified_at' => now()])->save();
        Audit::record('payroll.rule_verified', $rule, ['key' => $rule->key, 'valid_from' => $rule->valid_from->toDateString(), 'value' => $rule->value, 'by' => $by]);

        $this->comment("Kontrollerad av {$by}.");

        return self::SUCCESS;
    }
}
