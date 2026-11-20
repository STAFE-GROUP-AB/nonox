<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Models\Account;
use App\Models\Company;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Moves one company from one BAS template year to the next (the yearly
 * routine, `bas:upgrade`), or corrects names inside the year it is on
 * (`--fix-names`, used once after the 2026 templates were checked against
 * BAS's official list). An account is renamed only when its name still is a
 * name NONOX itself seeded: the previous template's name, or one listed in
 * `previous-names.json` of that year. A name the user changed is never
 * overwritten. Nothing is added: accounts the company does not have stay
 * absent, and accounts missing from the new template stay as they are. Only
 * `accounts.name` and `teams.bas_version` change, so no voucher, row, balance
 * or hash is touched.
 */
class UpgradeBasChart
{
    public function __construct(private readonly SeedChartOfAccounts $templates) {}

    /** The folder year of a version label: "BAS 2027" and "2027" both give "2027". */
    public static function year(string $version): string
    {
        $year = trim((string) preg_replace('/^BAS\s*/i', '', trim($version)));

        if (preg_match('/^\d{4}$/', $year) !== 1) {
            throw new InvalidArgumentException("\"{$version}\" is not a BAS version such as \"2027\".");
        }

        return $year;
    }

    public static function folder(string $year): string
    {
        return rtrim((string) config('nonox.bas_path'), '/').'/'.$year;
    }

    public static function exists(string $year): bool
    {
        return is_dir(self::folder($year));
    }

    /**
     * renamed: accounts given the new name. kept: accounts whose own name was left alone.
     * skipped: why the company was not handled, or null.
     *
     * @return array{renamed: int, kept: int, skipped: string|null}
     */
    public function __invoke(Company $company, string $targetYear, bool $dryRun = false, bool $fixNames = false): array
    {
        $fromYear = self::year((string) ($company->bas_version ?: 'BAS 2026'));

        if ($fixNames && $fromYear !== $targetYear) {
            return ['renamed' => 0, 'kept' => 0, 'skipped' => "not on BAS {$targetYear}"];
        }

        if (! $fixNames && $fromYear === $targetYear) {
            return ['renamed' => 0, 'kept' => 0, 'skipped' => 'already on BAS '.$targetYear];
        }

        if ((int) $fromYear > (int) $targetYear) {
            return ['renamed' => 0, 'kept' => 0, 'skipped' => 'newer than BAS '.$targetYear];
        }

        if (! self::exists($fromYear)) {
            return ['renamed' => 0, 'kept' => 0, 'skipped' => 'no template for BAS '.$fromYear];
        }

        try {
            $old = $this->names($company, $fromYear);
            $new = $this->names($company, $targetYear);
        } catch (RuntimeException $exception) {
            return ['renamed' => 0, 'kept' => 0, 'skipped' => $exception->getMessage()];
        }

        $previous = $this->previousNames($fromYear);

        return CurrentCompany::run($company, function () use ($company, $old, $new, $previous, $targetYear, $dryRun, $fixNames): array {
            $changes = [];
            $kept = 0;

            $accounts = Account::query()->whereIn('number', array_keys($new))->get(['id', 'number', 'name']);

            foreach ($accounts as $account) {
                $newName = $new[$account->number];

                if ($account->name === $newName) {
                    continue;
                }

                $ours = array_filter([$old[$account->number] ?? null, ...($previous[$account->number] ?? [])]);

                if (in_array($account->name, $ours, true)) {
                    $changes[$account->id] = $newName;

                    continue;
                }

                if ($ours !== [] && $ours !== [$newName]) {
                    $kept++;
                }
            }

            if ($dryRun) {
                return ['renamed' => count($changes), 'kept' => $kept, 'skipped' => null];
            }

            DB::transaction(function () use ($company, $changes, $accounts, $targetYear, $kept, $fixNames): void {
                foreach ($accounts as $account) {
                    if (! isset($changes[$account->id])) {
                        continue;
                    }

                    $before = $account->name;
                    $account->forceFill(['name' => $changes[$account->id]])->save();

                    Audit::record('account.updated', $account, [
                        'number' => $account->number,
                        'before' => ['name' => $before],
                        'after' => ['name' => $account->name],
                        'reason' => $fixNames ? 'bas_name_correction' : 'bas_upgrade',
                    ], $company);
                }

                if ($fixNames) {
                    return;
                }

                $previous = $company->bas_version;
                $company->forceFill(['bas_version' => 'BAS '.$targetYear])->save();

                Audit::record('company.bas_upgraded', $company, [
                    'from' => $previous,
                    'to' => $company->bas_version,
                    'renamed' => count($changes),
                    'kept' => $kept,
                ], $company);
            });

            return ['renamed' => count($changes), 'kept' => $kept, 'skipped' => null];
        });
    }

    /** @return array<int, string> */
    private function names(Company $company, string $year): array
    {
        $names = [];

        foreach ($this->templates->template($company->reporting_framework, $company->legal_form, $year) as $account) {
            $names[$account['number']] = $account['name'];
        }

        return $names + $this->officialExtras($year);
    }

    /**
     * Official names of accounts outside the templates that NONOX creates itself.
     *
     * @return array<int, string>
     */
    private function officialExtras(string $year): array
    {
        $data = $this->previousFile($year);
        $extras = [];

        foreach (is_array($data['official'] ?? null) ? $data['official'] : [] as $number => $name) {
            $extras[(int) $number] = (string) $name;
        }

        return $extras;
    }

    /** @return array<string, mixed> */
    private function previousFile(string $year): array
    {
        $path = self::folder($year).'/previous-names.json';
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_array($data) ? $data : [];
    }

    /** @return array<int, list<string>> */
    private function previousNames(string $year): array
    {
        $data = $this->previousFile($year);
        $names = [];

        foreach (is_array($data['names'] ?? null) ? $data['names'] : [] as $number => $list) {
            $names[(int) $number] = array_values(array_map('strval', (array) $list));
        }

        return $names;
    }
}
