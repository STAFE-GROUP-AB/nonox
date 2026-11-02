<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Support;

use App\Modules\Payroll\Actions\ImportPayrollRules;
use App\Modules\Payroll\Domain\Rules\RuleSet;
use App\Modules\Payroll\Models\PayrollRuleVerification;
use App\Modules\Payroll\Models\PayrollRuleVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Whether a payment year is ready for payroll, for the current company:
 * for every rule a run may need, whether the year is covered by versions
 * and whether those are checked, plus whether Skatteverket's tax tables for
 * the year are imported. Runs pick rules and tables by the payment date, so
 * this is the list to work through before the year's first payday.
 */
final class YearReadiness
{
    public const string VERIFIED = 'verified';

    public const string UNVERIFIED = 'unverified';

    public const string MISSING = 'missing';

    public const string PENDING = 'pending';

    /**
     * The payment year to prepare: from October the next one, since
     * December's pay is often paid in January.
     */
    public static function defaultYear(CarbonImmutable $today): int
    {
        return $today->month >= 10 ? $today->year + 1 : $today->year;
    }

    /**
     * @return array{
     *     year: int,
     *     rows: list<array{key: string, status: string, versions: Collection<int, PayrollRuleVersion>, gap_from: ?string, pending: ?array<string, mixed>}>,
     *     counts: array<string, int>,
     *     tax_tables: ?object,
     *     checked: array<int, PayrollRuleVerification>
     * }
     */
    public static function for(int $year): array
    {
        $start = CarbonImmutable::parse("{$year}-01-01");
        $end = CarbonImmutable::parse("{$year}-12-31");
        $versions = PayrollRuleVersion::query()
            ->whereIn('key', RuleSet::REQUIRED_KEYS)
            ->whereDate('valid_from', '<=', $end->toDateString())
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $start->toDateString()))
            ->orderBy('valid_from')
            ->get()
            ->groupBy('key');
        $checked = PayrollRuleVerification::query()->whereIn('payroll_rule_version_id', $versions->flatten()->pluck('id')->all())->get()->keyBy('payroll_rule_version_id')->all();
        $pending = collect(in_array($year, ImportPayrollRules::years(), true) ? ImportPayrollRules::file($year)['pending'] : [])->keyBy('key');
        $rows = [];

        foreach (RuleSet::REQUIRED_KEYS as $key) {
            /** @var Collection<int, PayrollRuleVersion> $all */
            $all = $versions->get($key, collect())->values();
            [$own, $gap] = self::effective($all, $start, $end);
            $allChecked = $own->every(fn (PayrollRuleVersion $version): bool => $version->isVerified() || isset($checked[$version->id]));

            $rows[] = [
                'key' => $key,
                'status' => match (true) {
                    $gap !== null && $pending->has($key) => self::PENDING,
                    $gap !== null => self::MISSING,
                    $allChecked => self::VERIFIED,
                    default => self::UNVERIFIED,
                },
                'versions' => $own,
                'gap_from' => $gap,
                'pending' => $pending->get($key),
            ];
        }

        $counts = array_count_values(array_column($rows, 'status'));

        return [
            'year' => $year,
            'rows' => $rows,
            'counts' => [self::VERIFIED => 0, self::UNVERIFIED => 0, self::MISSING => 0, self::PENDING => 0, ...$counts],
            'tax_tables' => DB::table('tax_table_imports')->where('year', $year)->where('period_type', 'MONTH')->first(['file_name', 'table_count', 'created_at']),
            'checked' => $checked,
        ];
    }

    /**
     * The versions a run would use on some day of the year (as RuleSet
     * does: the latest valid_from among those covering the day), and the
     * first day no version covers, or null when the whole year is covered.
     *
     * @param  Collection<int, PayrollRuleVersion>  $versions  ordered by valid_from
     * @return array{0: Collection<int, PayrollRuleVersion>, 1: ?string}
     */
    private static function effective(Collection $versions, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $used = [];
        $gap = null;

        for ($day = $start; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
            $version = $versions->last(fn (PayrollRuleVersion $version): bool => $version->valid_from->lessThanOrEqualTo($day)
                && ($version->valid_to === null || $version->valid_to->greaterThanOrEqualTo($day)));

            if ($version === null) {
                $gap ??= $day->toDateString();

                continue;
            }

            $used[$version->id] = $version;
        }

        return [collect(array_values($used)), $gap];
    }
}
