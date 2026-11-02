<?php

declare(strict_types=1);

namespace App\Modules\Planning\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A schedule template of one to eight weeks (mall), applied to weeks of the
 * schedule as draft shifts.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property int $weeks
 * @property ?string $notes
 * @property ?int $created_by
 */
class Template extends Model
{
    use BelongsToCompany;

    protected $table = 'planning_templates';

    /** @var list<string> */
    protected $fillable = ['company_id', 'name', 'weeks', 'notes', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['weeks' => 'integer'];
    }

    /** @return HasMany<TemplateShift, $this> */
    public function shifts(): HasMany
    {
        return $this->hasMany(TemplateShift::class)->orderBy('week_index')->orderBy('weekday')->orderBy('start');
    }
}
