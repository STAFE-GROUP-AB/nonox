<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Jetstream\TeamInvitation as JetstreamTeamInvitation;

/**
 * @property ?string $name the invited person's name, when the inviter knows it
 */
class TeamInvitation extends JetstreamTeamInvitation
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'email',
        'role',
        'name',
    ];

    /**
     * Get the team that the invitation belongs to.
     */
    /** @return BelongsTo<Company, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'team_id');
    }
}
