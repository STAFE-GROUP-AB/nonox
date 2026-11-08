<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Modules\Connect\Support\ConnectAccess;
use App\Modules\ModuleManager;
use App\Modules\ModuleRegistry;
use App\Support\Audit\Audit;
use App\Support\Tenancy\ArchivedCompany;
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
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\PasskeyAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property ?list<string> $hidden_panels
 * @property ?CarbonImmutable $sessions_valid_from
 * @property ?CarbonImmutable $anonymised_at
 */
class User extends Authenticatable implements PasskeyUser
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasProfilePhoto;
    use HasTeams {
        hasTeamPermission as jetstreamHasTeamPermission;
    }
    use Notifiable;
    use PasskeyAuthenticatable;

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
     * module gives owners (PAYROLL_MODULE.md A.7). An archived company grants
     * only the permissions that read (ArchivedCompany::READ).
     *
     * @param  mixed  $team
     */
    public function hasTeamPermission($team, string $permission): bool
    {
        // On an API request: only the credential's own company and scopes (ADR-032).
        if (ConnectAccess::restricts($team, $permission)) {
            return false;
        }

        // An archived company can only be read (ADR-024).
        if ($team instanceof Company && $team->isArchived() && ! ArchivedCompany::allows($permission)) {
            return false;
        }

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

    /**
     * The initials on a coloured square, drawn here. Jetstream's default
     * fetches the image from ui-avatars.com, which would send every user's
     * name to a third party (and the content security policy blocks it).
     */
    protected function defaultProfilePhotoUrl(): string
    {
        $initials = collect(preg_split('/\s+/', trim((string) $this->name)) ?: [])
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"><rect width="64" height="64" fill="#FFE6DF"/>'
            .'<text x="50%" y="50%" dy=".35em" text-anchor="middle" font-family="Inter,system-ui,sans-serif" font-size="26" font-weight="700" fill="#B8381A">'
            .e($initials).'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
