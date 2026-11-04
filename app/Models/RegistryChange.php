<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A change in the company register for an organisation number some
 * company's CRM watches (ADR-047): field, old and new value, when and from
 * which source. Instance data; crm:registry-watch turns it into a task in
 * each company that has the organisation number as an account.
 *
 * @property int $id
 * @property string $org_number
 * @property string $field
 * @property ?string $old_value
 * @property ?string $new_value
 * @property string $source
 * @property ?int $import_id
 * @property CarbonImmutable $detected_at
 */
class RegistryChange extends Model
{
    public $timestamps = false;

    protected $table = 'company_registry_changes';

    /** @var list<string> */
    protected $fillable = ['org_number', 'field', 'old_value', 'new_value', 'source', 'import_id', 'detected_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['detected_at' => 'immutable_datetime'];
    }
}
