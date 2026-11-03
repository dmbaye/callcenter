<?php

namespace App\Models;

use App\Concerns\HasTeams;
use App\Support\NotificationPreferences;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property bool $is_platform_admin
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $tour_completed_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property int|null $current_team_id
 * @property string|null $avatar_path
 * @property string|null $gender
 * @property string|null $job_title
 * @property string|null $department
 * @property string|null $phone
 * @property string|null $locale
 * @property array<string, array<string, bool>>|null $notification_preferences
 * @property-read string|null $avatar
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team|null $currentTeam
 * @property-read Collection<int, Team> $ownedTeams
 * @property-read Collection<int, Membership> $teamMemberships
 * @property-read Collection<int, Team> $teams
 * @property-read Collection<int, CallOutcome> $callOutcomes
 */
#[Fillable(['name', 'email', 'password', 'current_team_id', 'gender', 'job_title', 'department', 'phone', 'locale'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'avatar_path', 'notification_preferences'])]
class User extends Authenticatable implements HasLocalePreference, MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasTeams, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    public const GENDERS = ['female', 'male', 'other', 'unspecified'];

    /** @var list<string> */
    protected $appends = ['avatar'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'tour_completed_at' => 'datetime',
            'notification_preferences' => 'array',
            'is_platform_admin' => 'boolean',
        ];
    }

    /** URL of the profile photo (served by AvatarController), null = initials. */
    protected function avatar(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->avatar_path
            ? route('avatars.show', ['user' => $this->id, 'v' => substr(md5($this->avatar_path), 0, 8)], false)
            : null);
    }

    /** Mails and notifications go out in the user's language. */
    public function preferredLocale(): ?string
    {
        return $this->locale;
    }

    /** @param  'in_app'|'email'  $channel */
    public function wantsNotification(string $category, string $channel): bool
    {
        return NotificationPreferences::resolve($this->notification_preferences)[$category][$channel] ?? true;
    }

    public function phoneExtensions(): HasMany
    {
        return $this->hasMany(PhoneExtension::class);
    }

    public function queueMemberships(): HasMany
    {
        return $this->hasMany(QueueMembership::class);
    }

    /**
     * The user's extension in a team. Uses the eager-loaded phoneExtensions
     * relation when present (no query per call in lists).
     */
    public function phoneExtensionFor(?Team $team = null): ?PhoneExtension
    {
        $team ??= $this->currentTeam;

        if (! $team) {
            return null;
        }

        $extensions = $this->relationLoaded('phoneExtensions')
            ? $this->phoneExtensions
            : $this->phoneExtensions()->where('team_id', $team->id)->get();

        $extension = $extensions
            ->where('team_id', $team->id)
            ->sortBy([['is_primary', 'desc'], ['id', 'asc']])
            ->first();

        $extension?->setRelation('team', $team);

        return $extension;
    }

    public function currentPhoneExtension(): ?PhoneExtension
    {
        return $this->phoneExtensionFor($this->currentTeam);
    }

    public function callQueues(): BelongsToMany
    {
        return $this->belongsToMany(CallQueue::class, 'queue_memberships', 'user_id', 'call_queue_id')
            ->withPivot(['penalty'])
            ->withTimestamps();
    }

    /**
     * The user's queues in a team, ordered by name. Uses the eager-loaded
     * callQueues relation when present.
     *
     * @return Collection<int, CallQueue>
     */
    public function teamQueues(?Team $team = null): Collection
    {
        $team ??= $this->currentTeam;

        if (! $team) {
            return new Collection;
        }

        $queues = $this->relationLoaded('callQueues')
            ? $this->callQueues
            : $this->callQueues()->where('call_queues.team_id', $team->id)->get();

        return $queues->where('team_id', $team->id)->sortBy('name')->values();
    }

    /** @return list<string> display names */
    public function queueNames(?Team $team = null): array
    {
        return $this->teamQueues($team)->pluck('name')->all();
    }

    /** @return list<string> immutable Asterisk names */
    public function asteriskQueueNames(?Team $team = null): array
    {
        return $this->teamQueues($team)->pluck('asterisk_name')->all();
    }

    /**
     * @return HasMany<CallOutcome, $this>
     */
    public function callOutcomes(): HasMany
    {
        return $this->hasMany(CallOutcome::class);
    }

    /** In-app notifications are pushed on the user's private channel (see routes/channels.php). */
    public function receivesBroadcastNotificationsOn(): string
    {
        return 'users.'.$this->id;
    }
}
