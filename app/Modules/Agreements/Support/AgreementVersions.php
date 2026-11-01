<?php

declare(strict_types=1);

namespace App\Modules\Agreements\Support;

use App\Modules\Agreements\Models\AgreementRuleVerification;
use App\Modules\Agreements\Models\AgreementRuleVersion;
use Illuminate\Support\Collection;

/**
 * The agreement versions there are, and which of them a company may use: a
 * version counts once it is verified for the installation, or checked by
 * the company itself (docs/avtal/README.md §5). Runs in the current
 * company's context.
 */
final class AgreementVersions
{
    /**
     * Ids of the versions the current company has checked itself.
     *
     * @return list<int>
     */
    public static function companyChecked(): array
    {
        return array_values(array_map('intval', AgreementRuleVerification::query()->pluck('agreement_rule_version_id')->all()));
    }

    /** @param  list<int>  $companyChecked */
    public static function isUsable(AgreementRuleVersion $version, array $companyChecked): bool
    {
        return $version->isVerified() || in_array($version->id, $companyChecked, true);
    }

    /**
     * The agreements there is data for, one entry per agreement and variant
     * with the newest version, sorted by name.
     *
     * @return Collection<string, AgreementRuleVersion> by "<key>|<variant>"
     */
    public static function catalog(): Collection
    {
        return AgreementRuleVersion::query()
            ->orderBy('agreement_key')
            ->orderBy('variant')
            ->orderByDesc('valid_from')
            ->get()
            ->unique(fn (AgreementRuleVersion $version): string => "{$version->agreement_key}|{$version->variant}")
            ->keyBy(fn (AgreementRuleVersion $version): string => "{$version->agreement_key}|{$version->variant}")
            ->sortBy(fn (AgreementRuleVersion $version): string => $version->agreementRules()->name());
    }

    /**
     * The newest version of each agreement, whatever the variant, by key.
     *
     * @return Collection<string, AgreementRuleVersion>
     */
    public static function agreements(): Collection
    {
        return self::catalog()
            ->sortBy(fn (AgreementRuleVersion $version): string => $version->variant === AgreementRuleVersion::MEMBER ? '0' : '1')
            ->unique('agreement_key')
            ->keyBy('agreement_key')
            ->sortBy(fn (AgreementRuleVersion $version): string => $version->agreementRules()->name());
    }

    public static function name(string $key): string
    {
        return self::agreements()->get($key)?->agreementRules()->name() ?? $key;
    }
}
