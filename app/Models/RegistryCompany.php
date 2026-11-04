<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A legal person in the instance's company register (ADR-047), from SCB's
 * and Bolagsverket's open data. Instance data, not company data: no
 * BelongsToCompany, readable by every CRM user. Natural persons (sole
 * traders) are never here.
 *
 * @property int $id
 * @property string $org_number ten digits
 * @property string $name
 * @property ?string $legal_form
 * @property ?int $scb_status
 * @property ?string $sni_code
 * @property ?string $sni_division
 * @property ?list<string> $sni_codes
 * @property ?string $address_line1
 * @property ?string $co_address
 * @property ?string $postal_code
 * @property ?string $city
 * @property ?string $municipality_code
 * @property ?string $county_code
 * @property ?int $employee_class
 * @property ?int $turnover_class
 * @property ?int $turnover_year
 * @property ?string $phone
 * @property ?string $email
 * @property ?int $bolagsverket_status
 * @property ?CarbonImmutable $registered_on
 * @property ?CarbonImmutable $deregistered_on
 * @property ?string $deregistration_reason
 * @property ?string $proceedings
 * @property ?CarbonImmutable $proceedings_since
 * @property bool $advertising_block
 * @property ?int $phone_block
 * @property bool $email_block
 * @property list<string> $sources
 * @property ?CarbonImmutable $fetched_at
 * @property ?CarbonImmutable $bolagsverket_fetched_at
 * @property ?int $last_import_id
 * @property ?CarbonImmutable $gone_at
 */
class RegistryCompany extends Model
{
    protected $table = 'company_registry';

    /** SCB's FtgStat for an active company (VAT, employer or F-tax). */
    public const int ACTIVE = 1;

    /** @var list<string> */
    protected $fillable = [
        'org_number', 'name', 'legal_form', 'scb_status', 'sni_code', 'sni_division', 'sni_codes',
        'address_line1', 'co_address', 'postal_code', 'city', 'municipality_code', 'county_code',
        'employee_class', 'turnover_class', 'turnover_year', 'phone', 'email', 'bolagsverket_status',
        'registered_on', 'deregistered_on', 'deregistration_reason', 'proceedings', 'proceedings_since',
        'advertising_block', 'phone_block', 'email_block', 'sources', 'fetched_at', 'bolagsverket_fetched_at',
        'last_import_id', 'gone_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'scb_status' => 'integer',
            'employee_class' => 'integer',
            'turnover_class' => 'integer',
            'turnover_year' => 'integer',
            'bolagsverket_status' => 'integer',
            'phone_block' => 'integer',
            'sni_codes' => 'array',
            'sources' => 'array',
            'advertising_block' => 'boolean',
            'email_block' => 'boolean',
            'registered_on' => 'immutable_date',
            'deregistered_on' => 'immutable_date',
            'proceedings_since' => 'immutable_date',
            'fetched_at' => 'immutable_datetime',
            'bolagsverket_fetched_at' => 'immutable_datetime',
            'gone_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * Active and in business: active at SCB, not deregistered, no ongoing
     * bankruptcy, liquidation or reorganisation, and still in the source.
     *
     * @param  Builder<self>  $query
     */
    public function scopeInBusiness(Builder $query): void
    {
        $query->where('scb_status', self::ACTIVE)
            ->whereNull('deregistered_on')
            ->whereNull('proceedings')
            ->whereNull('gone_at')
            ->where(fn (Builder $inner) => $inner->whereNull('bolagsverket_status')->orWhere('bolagsverket_status', 0));
    }

    public function isInBusiness(): bool
    {
        return $this->scb_status === self::ACTIVE && $this->deregistered_on === null && $this->proceedings === null
            && $this->gone_at === null && in_array($this->bolagsverket_status, [null, 0], true);
    }

    /** NNNNNN-NNNN */
    public function formattedOrgNumber(): string
    {
        return substr($this->org_number, 0, 6).'-'.substr($this->org_number, 6);
    }

    /** The town with an initial capital ("STOCKHOLM" → "Stockholm"), as ADR-035 writes it. */
    public function town(): ?string
    {
        return self::titleCase($this->city);
    }

    public static function titleCase(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_convert_case(mb_strtolower($value), MB_CASE_TITLE);
    }

    /** Whether telemarketing to the company goes against its opt-out (2 telemarketing, 3 NIX-Telefon). */
    public function hasPhoneBlock(): bool
    {
        return in_array($this->phone_block, [2, 3], true);
    }

    /** Ten digits of an organisation number however it is written, or null for anything else. */
    public static function digits(?string $number): ?string
    {
        $digits = (string) preg_replace('/\D/', '', (string) $number);

        if (strlen($digits) === 12 && str_starts_with($digits, '16')) {
            $digits = substr($digits, 2);
        }

        return strlen($digits) === 10 ? $digits : null;
    }
}
