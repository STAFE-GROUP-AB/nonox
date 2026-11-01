<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tasks\TaskFrequency;
use App\Domain\Tasks\TaskType;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A task that comes back every month, quarter or year, such as "Moms varje
 * månad". tasks:generate-recurring creates one task per period.
 *
 * @property int $id
 * @property int $company_id
 * @property string $title
 * @property ?string $description
 * @property TaskType $type
 * @property TaskFrequency $frequency
 * @property int $due_day
 * @property ?int $assignee_id
 * @property bool $active
 * @property int $created_by
 */
class TaskRecurrence extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = ['company_id', 'title', 'description', 'type', 'frequency', 'due_day', 'assignee_id', 'active', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => TaskType::class,
            'frequency' => TaskFrequency::class,
            'due_day' => 'integer',
            'active' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }
}
