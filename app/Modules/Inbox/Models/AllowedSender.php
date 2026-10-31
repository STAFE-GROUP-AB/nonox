<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * One entry on the company's allowlist: an exact address
 * ("kassa@kassasystem.se") or a whole domain ("@kassasystem.se"). Only mail
 * from a sender on the list is interpreted; mail to the shared address is
 * routed to the company whose list has the sender.
 *
 * @property int $id
 * @property int $company_id
 * @property string $pattern
 * @property ?string $note
 * @property ?int $created_by
 */
class AllowedSender extends Model
{
    use BelongsToCompany;

    protected $table = 'inbox_allowed_senders';

    /** @var list<string> */
    protected $fillable = ['company_id', 'pattern', 'note', 'created_by'];

    public function isDomain(): bool
    {
        return str_starts_with($this->pattern, '@');
    }

    /**
     * The patterns that would allow an address: the address itself and its
     * domain, both lower case.
     *
     * @return list<string>
     */
    public static function patternsFor(string $email): array
    {
        $email = mb_strtolower(trim($email));
        $at = strrpos($email, '@');

        return $at === false ? [$email] : [$email, substr($email, $at)];
    }

    /**
     * A normalised pattern, or null when the input is neither an address nor
     * a domain.
     */
    public static function normalise(string $input): ?string
    {
        $input = mb_strtolower(trim($input));

        if (preg_match('/^@?[a-z0-9.-]+\.[a-z]{2,}$/', $input) === 1 && ! str_contains($input, '..')) {
            return str_starts_with($input, '@') ? $input : '@'.$input;
        }

        return filter_var($input, FILTER_VALIDATE_EMAIL) === false ? null : $input;
    }
}
