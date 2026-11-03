<?php

declare(strict_types=1);

namespace App\Modules\Crm\Models;

use App\Modules\Crm\Domain\PersonRole;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A person's place at an account, with their role there. Created through
 * LinkPerson only, never by attach(), so the company is always set.
 *
 * @property int $id
 * @property int $company_id
 * @property int $account_id
 * @property int $person_id
 * @property PersonRole $role
 */
class AccountPerson extends Pivot
{
    use BelongsToCompany;

    protected $table = 'crm_account_person';

    public $incrementing = true;

    /** @var list<string> */
    protected $fillable = ['company_id', 'account_id', 'person_id', 'role'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['role' => PersonRole::class];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
