<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Jetstream\HasProfilePhoto;
use Laravel\Jetstream\HasTeams;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property ?list<string> $hidden_panels
 */
class User extends Authenticatable
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasProfilePhoto;
    use HasTeams;
    use Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'email_verified_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'remember_token',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var list<string>
     */
    protected $appends = [
        'profile_photo_url',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'hidden_panels' => 'array',
        ];
    }

    /**
     * The selected company, but only while the user is still a member of it.
     */
    public function currentCompany(): ?Company
    {
        $company = $this->currentTeam;

        if (! $company instanceof Company || ! $this->belongsToTeam($company)) {
            return null;
        }

        return $company;
    }

    /** @return Collection<int, Company> */
    public function companies(): Collection
    {
        return $this->allTeams()
            ->filter(fn (mixed $company): bool => $company instanceof Company)
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    public function switchCompany(Company $company): bool
    {
        $previous = $this->current_team_id;
        $switched = (bool) $this->switchTeam($company);

        CurrentCompany::forget();

        if ($switched && $previous !== $company->id) {
            Audit::record('company.switched', $company, ['from_company_id' => $previous], $company);
        }

        return $switched;
    }

    public function hidesPanel(string $panel, Company $company): bool
    {
        return in_array("{$panel}:{$company->id}", (array) $this->hidden_panels, true);
    }

    public function setPanelHidden(string $panel, Company $company, bool $hidden): void
    {
        $key = "{$panel}:{$company->id}";
        $panels = array_values(array_diff((array) $this->hidden_panels, [$key]));

        if ($hidden) {
            $panels[] = $key;
        }

        $this->forceFill(['hidden_panels' => $panels])->save();
    }
}
