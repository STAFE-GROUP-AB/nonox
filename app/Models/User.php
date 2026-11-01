<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Modules\ModuleManager;
use App\Modules\ModuleRegistry;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Jetstream\HasProfilePhoto;
use Laravel\Jetstream\HasTeams;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property ?list<string> $hidden_panels
 * @property ?CarbonImmutable $sessions_valid_from
 * @property ?CarbonImmutable $anonymised_at
 */
class User extends Authenticatable
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasProfilePhoto;
    use HasTeams {
        hasTeamPermission as jetstreamHasTeamPermission;
    }
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
            'sessions_valid_from' => 'immutable_datetime',
            'anonymised_at' => 'immutable_datetime',
            'hidden_panels' => 'array',
        ];
    }

    /**
     * Addresses are kept in lowercase, so that a user is found however the
     * address is typed (security review 2026-11-01).
     *
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => self::normaliseEmail($value));
    }

    public static function normaliseEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeByEmail(Builder $query, string $email): Builder
    {
        return $query->whereRaw('lower(email) = ?', [self::normaliseEmail($email)]);
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

    /**
     * Jetstream's check, with modules: a permission that belongs to a module
     * is false while the module is off for the company, except the module's
     * archive permissions, and the owner gets only the module permissions the
     * module gives owners (PAYROLL_MODULE.md A.7).
     *
     * @param  mixed  $team
     */
    public function hasTeamPermission($team, string $permission): bool
    {
        $module = app(ModuleRegistry::class)->moduleForPermission($permission);

        if ($module === null || ! $team instanceof Company) {
            return $this->jetstreamHasTeamPermission($team, $permission);
        }

        $enabled = app(ModuleManager::class)->isEnabled($module->key(), $team);

        if (! $enabled && ! in_array($permission, $module->archivePermissions(), true)) {
            return false;
        }

        if ($this->ownsTeam($team)) {
            return in_array($permission, $module->rolePermissions()['owner'] ?? [], true);
        }

        return $this->jetstreamHasTeamPermission($team, $permission);
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
