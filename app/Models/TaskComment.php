<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A comment on a task. Free text: may contain personal data
 * (docs/gdpr/registerforteckning.md).
 *
 * @property int $id
 * @property int $company_id
 * @property int $task_id
 * @property int $user_id
 * @property string $body
 * @property CarbonImmutable $created_at
 */
class TaskComment extends Model
{
    use BelongsToCompany;

    public const int BODY_MAX = 5000;

    /** @var list<string> */
    protected $fillable = ['company_id', 'task_id', 'user_id', 'body'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
