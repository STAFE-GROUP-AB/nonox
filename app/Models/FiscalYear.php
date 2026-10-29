<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Accounting\FiscalYearStatus;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A räkenskapsår (AGENTS.md §6.2).
 *
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable $end_date
 * @property FiscalYearStatus $status
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
        ];
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
