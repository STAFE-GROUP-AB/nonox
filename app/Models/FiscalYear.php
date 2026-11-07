<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Accounting\FiscalYearStatus;
use App\Domain\Company\ReportingFramework;
use App\Support\Tenancy\BelongsToCompany;
use App\Support\Tenancy\CompanyScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A räkenskapsår (AGENTS.md §6.2).
 *
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable $end_date
 * @property FiscalYearStatus $status
 * @property ReportingFramework $reporting_framework the K-regelverk of this year (ADR-059, A.2.1)
 * @property CarbonImmutable|null $reporting_framework_changed_at
 * @property int|null $reporting_framework_changed_by
 * @property string|null $reporting_framework_change_reason
 */
class FiscalYear extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'start_date',
        'end_date',
        'status',
    ];

    /**
     * The database default, also on a model that was just created.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'OPEN',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'start_date' => 'immutable_date',
            'end_date' => 'immutable_date',
            'status' => FiscalYearStatus::class,
            'closed_at' => 'immutable_datetime',
            'reporting_framework' => ReportingFramework::class,
            'reporting_framework_changed_at' => 'immutable_datetime',
        ];
    }

    /**
     * A new year follows the framework of the year before it, else of the
     * nearest later year, else the company's (ADR-059, A.2.1), so no creator
     * has to pass it. A change
     * goes through ChangeReportingFramework.
     */
    protected static function booted(): void
    {
        static::creating(function (FiscalYear $fiscalYear): void {
            if ($fiscalYear->getAttribute('reporting_framework') !== null) {
                return;
            }

            $fiscalYear->setAttribute('reporting_framework', self::inheritedFramework($fiscalYear));
        });
    }

    private static function inheritedFramework(FiscalYear $fiscalYear): ReportingFramework
    {
        $companyId = (int) $fiscalYear->getAttribute('company_id');

        // Safe: limited to the new year's own company, which the tenancy hook has already set and checked.
        $previous = CompanyScope::bypass(fn (): mixed => self::query()
            ->where('company_id', $companyId)
            ->whereDate('start_date', '<', $fiscalYear->start_date->toDateString())
            ->orderByDesc('start_date')
            ->value('reporting_framework'));

        if ($previous instanceof ReportingFramework) {
            return $previous;
        }

        // An older year added after newer ones (an earlier year imported later) takes the nearest later year's.
        $later = CompanyScope::bypass(fn (): mixed => self::query()
            ->where('company_id', $companyId)
            ->whereDate('start_date', '>', $fiscalYear->start_date->toDateString())
            ->orderBy('start_date')
            ->value('reporting_framework'));

        if ($later instanceof ReportingFramework) {
            return $later;
        }

        return Company::query()->findOrFail($companyId)->reporting_framework;
    }

    public function isOpen(): bool
    {
        return $this->status === FiscalYearStatus::Open;
    }

    public function contains(CarbonImmutable $date): bool
    {
        return $date->betweenIncluded($this->start_date, $this->end_date);
    }

    /**
     * "2026" for a calendar year, otherwise "2025/2026".
     */
    public function label(): string
    {
        return $this->start_date->year === $this->end_date->year
            ? (string) $this->start_date->year
            : "{$this->start_date->year}/{$this->end_date->year}";
    }
}
