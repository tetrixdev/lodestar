<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /** The user's personal-scope skill slots. @return MorphMany<Skill, $this> */
    public function skills(): MorphMany
    {
        return $this->morphMany(Skill::class, 'owner');
    }

    /** The user's own secret values. @return HasMany<PersonalSecret, $this> */
    public function personalSecrets(): HasMany
    {
        return $this->hasMany(PersonalSecret::class);
    }

    /** The user's linked GitHub accounts/tokens. @return HasMany<GithubConnection, $this> */
    public function githubConnections(): HasMany
    {
        return $this->hasMany(GithubConnection::class);
    }

    /** Teams this user belongs to (the owner is also a member). */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_user')
            ->withPivot(['role', 'can_approve_prompts'])
            ->withTimestamps();
    }

    /** Team ids this user belongs to (for project access scoping). */
    public function teamIds(): array
    {
        return $this->teams()->pluck('teams.id')->all();
    }

    public function isInTeam(int $teamId): bool
    {
        return in_array($teamId, $this->teamIds(), true);
    }
}
