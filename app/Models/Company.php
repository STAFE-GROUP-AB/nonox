<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Company\AccountingMethod;
use App\Domain\Company\LegalForm;
use App\Domain\Company\ReportingFramework;
use App\Domain\Company\VatPeriod;
use Carbon\CarbonImmutable;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Laravel\Jetstream\Events\TeamCreated;
use Laravel\Jetstream\Events\TeamDeleted;
use Laravel\Jetstream\Events\TeamUpdated;
use Laravel\Jetstream\Team as JetstreamTeam;

/**
 * A company is a Jetstream team (AGENTS.md §3). The table stays `teams`.
 *
 * @property LegalForm $legal_form
 * @property AccountingMethod $accounting_method
 * @property VatPeriod $vat_period
 * @property ReportingFramework $reporting_framework
 * @property int $fiscal_year_start_month
 * @property ?CarbonImmutable $locked_until_date
 */
class Company extends JetstreamTeam
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    protected $table = 'teams';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'personal_team',
        'org_number',
        'legal_form',
        'legal_name',
        'vat_number',
        'address_line1',
        'address_line2',
        'postal_code',
        'city',
        'country',
        'email',
        'phone',
        'website',
        'fiscal_year_start_month',
        'accounting_method',
        'vat_period',
        'vat_registered',
        'reporting_framework',
        'bas_version',
        'f_skatt',
        'employer_registered',
        'base_currency',
        'bankgiro',
        'plusgiro',
        'iban',
        'bic',
    ];

    /** @var array<string, class-string> */
    protected $dispatchesEvents = [
        'created' => TeamCreated::class,
        'updated' => TeamUpdated::class,
        'deleted' => TeamDeleted::class,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'personal_team' => 'boolean',
            'legal_form' => LegalForm::class,
            'accounting_method' => AccountingMethod::class,
            'vat_period' => VatPeriod::class,
            'reporting_framework' => ReportingFramework::class,
            'vat_registered' => 'boolean',
            'f_skatt' => 'boolean',
            'employer_registered' => 'boolean',
            'fiscal_year_start_month' => 'integer',
            'locked_until_date' => 'immutable_date',
            'archived_at' => 'immutable_datetime',
        ];
    }

    /**
     * Jetstream's own relations (members, invitations, the user's companies)
     * derive their keys from this and expect the `team_id` columns in
     * `team_user` and `team_invitations`. Domain tables use `company_id`, so
     * relations to them must name `company_id` explicitly.
     */
    public function getForeignKey(): string
    {
        return 'team_id';
    }

    /**
     * The first day of the fiscal year in words, e.g. "1 januari".
     */
    public function fiscalYearStartLabel(): string
    {
        $months = [
            __('januari'), __('februari'), __('mars'), __('april'), __('maj'), __('juni'),
            __('juli'), __('augusti'), __('september'), __('oktober'), __('november'), __('december'),
        ];

        return '1 '.$months[$this->fiscal_year_start_month - 1];
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
