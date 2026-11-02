<?php

declare(strict_types=1);

namespace App\Modules\Planning\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A role or skill a shift can ask for (kompetens: kassa, kock, öppnare),
 * held by employees through EmployeeSkill.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 */
class Skill extends Model
{
    use BelongsToCompany;

    protected $table = 'planning_skills';

    /** @var list<string> */
    protected $fillable = ['company_id', 'name'];
}
