<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A user's own named set of filters on a page that spans companies, such as
 * Att göra on Mina företag. Belongs to the user, not to a company: the
 * filters only narrow what the user's memberships already allow.
 *
 * @property int $id
 * @property int $user_id
 * @property string $page
 * @property string $name
 * @property array<string, string> $filters
 */
class SavedView extends Model
{
    public const int MAX_PER_PAGE = 20;

    /** @var list<string> */
    protected $fillable = ['user_id', 'page', 'name', 'filters'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['filters' => 'array'];
    }
}
