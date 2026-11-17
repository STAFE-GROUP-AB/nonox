<?php

declare(strict_types=1);

namespace App\Modules\Planning\Models;

use App\Modules\Payroll\Models\Employee;
use App\Modules\Planning\Support\LocalTimestamp;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recipient of a schedule message, frozen when it was sent (ADR-037 gap
 * 5): a later schedule change does not widen or narrow the audience. Holds
 * when the employee was notified (null: no login, "nås inte"), read the
 * message and acknowledged it. A read receipt is personal data about work:
 * shown to the planner per message only, never summed up per employee, not
 * audited, and pruned with the message.
 *
 * @property int $id
 * @property int $company_id
 * @property int $message_id
 * @property int $employee_id
 * @property ?CarbonImmutable $notified_at
 * @property ?CarbonImmutable $read_at
 * @property ?CarbonImmutable $acknowledged_at
 */
class MessageRecipient extends Model
{
    use BelongsToCompany;

    protected $table = 'planning_message_recipients';

    /** The receipts are set only by SendMessage and MarkMessage. @var list<string> */
    protected $fillable = ['company_id', 'message_id', 'employee_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'message_id' => 'integer',
            'employee_id' => 'integer',
            'notified_at' => LocalTimestamp::class,
            'read_at' => LocalTimestamp::class,
            'acknowledged_at' => LocalTimestamp::class,
        ];
    }

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
