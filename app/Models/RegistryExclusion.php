<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * An objection (art. 21) or erasure request (art. 17) about data in the
 * instance's company register (ADR-047). RegistryWriter applies it before
 * every write, so a re-import never brings the data back:
 *
 * - ALL: the company is left out of the register altogether;
 * - NAME: its name is replaced (a name that contains a person's name);
 * - PHONE, EMAIL: the field is left empty.
 *
 * Instance data, managed in HQ and with registry:exclude.
 *
 * @property int $id
 * @property string $org_number
 * @property string $field
 * @property string $reason OBJECTION or ERASURE
 * @property ?int $created_by
 * @property ?CarbonImmutable $created_at
 */
class RegistryExclusion extends Model
{
    public const string ALL = 'ALL';

    public const string NAME = 'NAME';

    public const string PHONE = 'PHONE';

    public const string EMAIL = 'EMAIL';

    public const array FIELDS = [self::ALL, self::NAME, self::PHONE, self::EMAIL];

    public const array REASONS = ['OBJECTION', 'ERASURE'];

    protected $table = 'company_registry_exclusions';

    /** @var list<string> */
    protected $fillable = ['org_number', 'field', 'reason', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** What a NAME exclusion shows instead of the name. */
    public static function replacementName(string $orgNumber): string
    {
        return (string) __('Företag :number', ['number' => substr($orgNumber, 0, 6).'-'.substr($orgNumber, 6)]);
    }

    /**
     * The exclusions of these organisation numbers: org number => fields.
     *
     * @param  list<string>  $orgNumbers
     * @return array<string, list<string>>
     */
    public static function for(array $orgNumbers): array
    {
        if ($orgNumbers === []) {
            return [];
        }

        $found = [];

        foreach (self::query()->whereIn('org_number', $orgNumbers)->get(['org_number', 'field']) as $exclusion) {
            $found[$exclusion->org_number][] = $exclusion->field;
        }

        return $found;
    }
}
