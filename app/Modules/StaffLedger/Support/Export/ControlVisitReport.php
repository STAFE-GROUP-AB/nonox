<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Support\Export;

use App\Models\Company;
use App\Modules\StaffLedger\Domain\LedgerQuery;
use App\Modules\StaffLedger\Domain\Shift;
use App\Modules\StaffLedger\Models\Entry;
use App\Modules\StaffLedger\Models\Location;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What the control visit view and every export show for a period: the
 * company, its locations, who is checked in now, the shifts, and every entry
 * (the ledger itself, corrections included) with personal numbers in full.
 * Built in the current company.
 */
final readonly class ControlVisitReport
{
    /** The longest period one view or export covers, so a request cannot ask for years at once. */
    public const int MAX_MONTHS = 13;

    public static function tooLong(CarbonImmutable $from, CarbonImmutable $to): bool
    {
        return $from->startOfDay()->addMonthsNoOverflow(self::MAX_MONTHS) < $to->startOfDay();
    }

    public static function tooLongMessage(): string
    {
        return __('Välj en period på högst :months månader.', ['months' => self::MAX_MONTHS]);
    }

    /**
     * @param  Collection<int, Location>  $locations
     * @param  list<Shift>  $present
     * @param  list<Shift>  $shifts
     * @param  Collection<int, Entry>  $entries
     */
    public function __construct(
        public Company $company,
        public Collection $locations,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public CarbonImmutable $createdAt,
        public array $present,
        public array $shifts,
        public Collection $entries,
    ) {}

    public static function build(Company $company, ?int $locationId, CarbonImmutable $from, CarbonImmutable $to, string $search = ''): self
    {
        $locations = Location::query()->when($locationId !== null, fn ($query) => $query->whereKey($locationId))->orderBy('name')->get();
        $needle = mb_strtolower(trim($search));
        $digits = (string) preg_replace('/\D/', '', $search);

        $matches = function (string $name, string $number) use ($needle, $digits): bool {
            if ($needle === '') {
                return true;
            }

            return str_contains(mb_strtolower($name), $needle) || ($digits !== '' && str_contains($number, $digits));
        };

        $present = array_values(array_filter(LedgerQuery::present($locationId), fn (Shift $shift): bool => $matches($shift->personName, $shift->personalNumber)));
        $shifts = array_values(array_filter(LedgerQuery::shifts($locationId, $from, $to), fn (Shift $shift): bool => $matches($shift->personName, $shift->personalNumber)));

        $entries = LedgerQuery::entries($locationId, $from, $to)
            ->filter(fn (Entry $entry): bool => $entry->occurred_at >= $from && $entry->occurred_at <= $to || $entry->corrects_entry_id !== null)
            ->filter(fn (Entry $entry): bool => $matches($entry->person_name, $entry->personal_number))
            ->sortBy([['occurred_at', 'asc'], ['id', 'asc']])
            ->values();

        return new self($company, $locations, $from, $to, CarbonImmutable::now(), $present, $shifts, $entries);
    }

    public function locationName(int $id): string
    {
        return (string) $this->locations->firstWhere('id', $id)?->name;
    }

    public static function formatNumber(string $digits): string
    {
        return strlen($digits) === 12 ? substr($digits, 0, 8).'-'.substr($digits, 8) : $digits;
    }

    public function filename(string $extension): string
    {
        return 'personalliggare-'.preg_replace('/\D/', '', (string) $this->company->org_number).'-'.$this->from->format('Ymd').'-'.$this->to->format('Ymd').'.'.$extension;
    }
}
