<?php

declare(strict_types=1);

namespace App\Modules\Boardroom\Models;

use App\Modules\Boardroom\Domain\HolderKind;
use App\Modules\Payroll\Domain\PersonalNumber;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A shareholder in the register (ABL 5 kap. 5 § 2): name, personal number,
 * organisation number or another identification number, and postal address.
 * The personal number and a foreign id are encrypted. The row holds the
 * current details; each change is also an ADDRESS_CHANGE entry in the
 * register with the new details, so the history is never lost.
 *
 * @property int $id
 * @property int $company_id
 * @property HolderKind $kind
 * @property string $name
 * @property ?string $personal_number
 * @property ?string $org_number
 * @property ?string $other_id
 * @property ?string $other_id_country
 * @property string $address_line1
 * @property ?string $address_line2
 * @property string $postal_code
 * @property string $city
 * @property string $country
 * @property ?int $created_by
 */
class Shareholder extends Model
{
    use BelongsToCompany;

    protected $table = 'boardroom_shareholders';

    /** @var list<string> */
    protected $hidden = ['personal_number', 'other_id'];

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'kind',
        'name',
        'personal_number',
        'org_number',
        'other_id',
        'other_id_country',
        'address_line1',
        'address_line2',
        'postal_code',
        'city',
        'country',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => HolderKind::class,
            'personal_number' => 'encrypted',
            'other_id' => 'encrypted',
        ];
    }

    /**
     * The details the register shows and every entry keeps a copy of.
     *
     * @return array{kind: string, name: string, personal_number: ?string, org_number: ?string, other_id: ?string, other_id_country: ?string, address_line1: string, address_line2: ?string, postal_code: string, city: string, country: string}
     */
    public function snapshot(): array
    {
        return [
            'kind' => $this->kind->value,
            'name' => $this->name,
            'personal_number' => $this->personal_number,
            'org_number' => $this->org_number,
            'other_id' => $this->other_id,
            'other_id_country' => $this->other_id_country,
            'address_line1' => $this->address_line1,
            'address_line2' => $this->address_line2,
            'postal_code' => $this->postal_code,
            'city' => $this->city,
            'country' => $this->country,
        ];
    }

    /**
     * The identity as the full register shows it, or masked for lists and
     * the courtesy copy: a personal number becomes the birth date.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public static function identity(array $snapshot, bool $masked = false): string
    {
        $personal = is_string($snapshot['personal_number'] ?? null) ? $snapshot['personal_number'] : null;

        if ($personal !== null && $personal !== '') {
            $number = PersonalNumber::tryFrom($personal);

            if ($masked) {
                return $number === null ? __('födelsedatum saknas') : $number->birthDate->toDateString();
            }

            return $number?->formatted() ?? $personal;
        }

        if (is_string($snapshot['org_number'] ?? null) && $snapshot['org_number'] !== '') {
            return $snapshot['org_number'];
        }

        $other = is_string($snapshot['other_id'] ?? null) ? $snapshot['other_id'] : '';

        if ($other === '') {
            return '';
        }

        $country = is_string($snapshot['other_id_country'] ?? null) ? " ({$snapshot['other_id_country']})" : '';

        return $masked ? __('utländskt id-nummer').$country : $other.$country;
    }

    /** @param  array<string, mixed>  $snapshot */
    public static function address(array $snapshot): string
    {
        $parts = [$snapshot['address_line1'] ?? null, $snapshot['address_line2'] ?? null, trim(($snapshot['postal_code'] ?? '').' '.($snapshot['city'] ?? ''))];
        $country = $snapshot['country'] ?? 'SE';

        if ($country !== 'SE') {
            $parts[] = $country;
        }

        return implode(', ', array_filter(array_map(fn (mixed $part): string => is_string($part) ? trim($part) : '', $parts), fn (string $part): bool => $part !== ''));
    }
}
