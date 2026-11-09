<?php

declare(strict_types=1);

namespace App\Modules\Portal\Models;

use App\Support\Tenancy\BelongsToCompany;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A company's portal settings (ADR-075 §8, §9): the random portal key used
 * in the portal's addresses (never the company id), whether the footer says
 * "Kundportalen drivs med NONOX" (on by default, decision O8), how many
 * months of invoices the portal shows (every open invoice is shown
 * regardless), and the company's own addition to the privacy notice.
 *
 * @property int $id
 * @property int $company_id
 * @property string $portal_key
 * @property bool $show_powered_by
 * @property int $history_months
 * @property ?string $privacy_notice
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 */
class PortalSettings extends Model
{
    use BelongsToCompany;

    public const string KEY_PATTERN = '[a-z0-9]{16}';

    protected $table = 'customer_portal_settings';

    /** @var list<string> */
    protected $fillable = ['company_id', 'portal_key', 'show_powered_by', 'history_months', 'privacy_notice'];

    /** @var array<string, mixed> */
    protected $attributes = ['show_powered_by' => true, 'history_months' => 24];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'show_powered_by' => 'boolean',
            'history_months' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** The company's settings, created with a fresh key the first time. Inside the company. */
    public static function current(): self
    {
        $settings = self::query()->first();

        if ($settings instanceof self) {
            return $settings;
        }

        return self::query()->create(['company_id' => CurrentCompany::id(), 'portal_key' => self::newKey()]);
    }

    public static function newKey(): string
    {
        return Str::lower(Str::random(16));
    }
}
