<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Models;

use App\Models\Article;
use App\Models\User;
use App\Modules\Kitchen\Domain\MealReason;
use App\Modules\Kitchen\Domain\MealType;
use App\Modules\Payroll\Models\Employee;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * One line of the staff-meal log (ADR-073 §7): meals served on a day for
 * the staff, at representation or to the owner. Its INTERNAL_USE movements
 * carry it as their source. An employee is named only while payroll is
 * on. Append-only: a wrong entry is corrected by a new entry with the
 * opposite quantity.
 *
 * @property int $id
 * @property int $company_id
 * @property CarbonImmutable $served_on
 * @property MealType $meal
 * @property ?int $article_id
 * @property int $quantity_milli
 * @property MealReason $reason
 * @property ?int $employee_id
 * @property int $paid_per_meal_ore
 * @property ?int $location_id
 * @property ?int $raw_cost_ore
 * @property ?int $corrects_meal_id
 * @property ?int $created_by
 * @property CarbonImmutable $created_at
 */
class StaffMeal extends Model
{
    use BelongsToCompany;

    public const null UPDATED_AT = null;

    protected $table = 'kitchen_staff_meals';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'served_on',
        'meal',
        'article_id',
        'quantity_milli',
        'reason',
        'employee_id',
        'paid_per_meal_ore',
        'location_id',
        'raw_cost_ore',
        'corrects_meal_id',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'served_on' => 'immutable_date',
            'meal' => MealType::class,
            'quantity_milli' => 'integer',
            'reason' => MealReason::class,
            'paid_per_meal_ore' => 'integer',
            'raw_cost_ore' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('The staff-meal log cannot be changed; register a correction.');
        });

        static::deleting(function (): never {
            throw new LogicException('The staff-meal log cannot be deleted; register a correction.');
        });
    }

    /** @return BelongsTo<Article, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasOne<self, $this> */
    public function correction(): HasOne
    {
        return $this->hasOne(self::class, 'corrects_meal_id');
    }
}
