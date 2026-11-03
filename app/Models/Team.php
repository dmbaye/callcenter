<?php

namespace App\Models;

use App\Concerns\GeneratesUniqueTeamSlugs;
use App\Data\Plan;
use App\Enums\BillingCycle;
use App\Enums\TeamRole;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $uuid
 * @property string|null $asterisk_key
 * @property bool $is_personal
 * @property string $usage_mode 'call_center' or 'internal' (internal calls and video only)
 * @property string|null $legal_name
 * @property string|null $industry
 * @property string|null $company_size
 * @property int|null $planned_agents
 * @property string|null $use_case
 * @property string|null $country
 * @property string|null $city
 * @property string|null $address
 * @property string|null $phone
 * @property string|null $contact_email
 * @property string|null $website
 * @property string|null $rccm
 * @property string|null $nif
 * @property string|null $acquisition_source
 * @property bool $marketing_opt_in
 * @property Carbon|null $profile_completed_at
 * @property Carbon|null $onboarded_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, TeamInvitation> $invitations
 * @property-read Collection<int, Membership> $memberships
 * @property-read Collection<int, User> $members
 * @property-read Collection<int, PhoneExtension> $phoneExtensions
 * @property-read Collection<int, CallQueue> $callQueues
 * @property-read Subscription|null $subscription
 * @property-read Collection<int, Payment> $payments
 * @property-read Collection<int, Meeting> $meetings
 * @property-read Collection<int, Campaign> $campaigns
 * @property-read Collection<int, DncNumber> $dncNumbers
 * @property-read Collection<int, CallRecording> $callRecordings
 */
#[Fillable([
    'name', 'slug', 'is_personal', 'usage_mode', 'uuid', 'asterisk_key',
    'legal_name', 'industry', 'company_size', 'planned_agents', 'use_case',
    'country', 'city', 'address', 'phone', 'contact_email', 'website',
    'rccm', 'nif', 'acquisition_source', 'marketing_opt_in',
    'service_level_enabled', 'service_level_target',
])]
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use GeneratesUniqueTeamSlugs, HasFactory, SoftDeletes;

    /** Full call center: queues, IVR, numbers, campaigns, supervision, agent states. */
    public const USAGE_CALL_CENTER = 'call_center';

    /** Internal phone system and video meetings only: no call-center screens. */
    public const USAGE_INTERNAL = 'internal';

    public const USAGE_MODES = [self::USAGE_CALL_CENTER, self::USAGE_INTERNAL];

    /** Company profile fields edited on the organization page. */
    public const PROFILE_FIELDS = [
        'usage_mode',
        'legal_name', 'industry', 'company_size', 'planned_agents', 'use_case',
        'country', 'city', 'address', 'phone', 'contact_email', 'website',
        'rccm', 'nif', 'acquisition_source', 'marketing_opt_in',
    ];

    /** The fields that make a profile usable for segmentation. */
    public const PROFILE_REQUIRED_FIELDS = ['industry', 'company_size', 'country', 'city', 'phone'];

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Team $team) {
            if (empty($team->slug)) {
                $team->slug = static::generateUniqueTeamSlug($team->name);
            }
            $team->uuid ??= (string) Str::uuid();
            $team->asterisk_key ??= static::generateAsteriskKey();
        });

        // Every new team gets the free trial once.
        static::created(function (Team $team) {
            $team->subscription()->create([
                'plan' => (string) config('billing.trial_plan', 'pro'),
                'billing_cycle' => BillingCycle::Monthly,
                'on_trial' => true,
                'ends_at' => now()->addDays((int) config('billing.trial_days', 14)),
            ]);

            $team->pauseReasons()->createMany(collect(config('agents.default_pause_reasons', []))
                ->values()
                ->map(fn (string $name, int $index) => ['name' => $name, 'position' => $index])
                ->all());
        });

        static::updating(function (Team $team) {
            if ($team->isDirty('asterisk_key') && $team->getOriginal('asterisk_key') !== null) {
                throw new LogicException('The Asterisk key is immutable.');
            }
            if ($team->isDirty('uuid') && $team->getOriginal('uuid') !== null) {
                throw new LogicException('The team UUID is immutable.');
            }
            if ($team->isDirty('name')) {
                $team->slug = static::generateUniqueTeamSlug($team->name, $team->id);
            }
        });
    }

    /**
     * The company profile as the organization page and the welcome wizard edit it.
     *
     * @return array<string, mixed>
     */
    public function profilePayload(): array
    {
        return [
            ...$this->only(self::PROFILE_FIELDS),
            'country' => $this->country ?? config('organization.default_country'),
            'completedAt' => $this->profile_completed_at?->toIso8601String(),
            'isComplete' => $this->hasCompleteProfile(),
        ];
    }

    /**
     * Choices offered for the profile fields (config/organization.php).
     *
     * @return array<string, list<array{value: string, label: string}>>
     */
    public static function profileOptions(): array
    {
        // Accent-aware alphabetical order in the reader's language ("Éducation" next to "E-commerce").
        $collator = new \Collator(app()->getLocale());

        return collect(['industries', 'sizes', 'use_cases', 'acquisition_sources', 'countries'])
            ->mapWithKeys(function (string $list) use ($collator) {
                $options = collect((array) config("organization.{$list}"))
                    ->map(fn (string $label, string $value) => ['value' => $value, 'label' => __($label)])
                    ->values();

                // Sizes keep their natural order (1–10, 11–50…).
                if ($list !== 'sizes') {
                    $options = $options
                        ->sort(fn (array $a, array $b) => $collator->compare($a['label'], $b['label']))
                        // "Autre" / "Other" always last.
                        ->sortBy(fn (array $option) => $option['value'] === 'other' ? 1 : 0)
                        ->values();
                }

                return [$list => $options->all()];
            })
            ->all();
    }

    public function isCallCenter(): bool
    {
        return ($this->usage_mode ?? self::USAGE_CALL_CENTER) === self::USAGE_CALL_CENTER;
    }

    public function isInternalOnly(): bool
    {
        return ! $this->isCallCenter();
    }

    /** Whether the owner still has to go through the welcome wizard. */
    public function needsOnboarding(): bool
    {
        return $this->onboarded_at === null;
    }

    /** Whether the fields needed for segmentation are all filled in. */
    public function hasCompleteProfile(): bool
    {
        foreach (self::PROFILE_REQUIRED_FIELDS as $field) {
            if (blank($this->{$field})) {
                return false;
            }
        }

        return true;
    }

    public function phoneExtensions(): HasMany
    {
        return $this->hasMany(PhoneExtension::class);
    }

    public function callQueues(): HasMany
    {
        return $this->hasMany(CallQueue::class);
    }

    /** "Non prêt" reasons offered in the agent status menu, in menu order. */
    public function pauseReasons(): HasMany
    {
        return $this->hasMany(PauseReason::class)->orderBy('position')->orderBy('id');
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(Meeting::class);
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    public function dncNumbers(): HasMany
    {
        return $this->hasMany(DncNumber::class);
    }

    public function ivrMenus(): HasMany
    {
        return $this->hasMany(IvrMenu::class);
    }

    public function shiftTemplates(): HasMany
    {
        return $this->hasMany(ShiftTemplate::class)->orderBy('position')->orderBy('start_time');
    }

    public function shiftAssignments(): HasMany
    {
        return $this->hasMany(ShiftAssignment::class);
    }

    public function shiftSwaps(): HasMany
    {
        return $this->hasMany(ShiftSwap::class);
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    public function leaveTypes(): HasMany
    {
        return $this->hasMany(LeaveType::class)->orderBy('position')->orderBy('id');
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function workforceSetting(): HasOne
    {
        return $this->hasOne(WorkforceSetting::class);
    }

    public function inboundNumbers(): HasMany
    {
        return $this->hasMany(InboundNumber::class);
    }

    /** The number shown to customers by default: the platform one first, else the first own number. */
    public function mainNumber(): ?string
    {
        return $this->inboundNumbers()
            ->orderByRaw("case when source = 'pool' then 0 else 1 end")
            ->orderBy('id')
            ->value('number');
    }

    public function surveys(): HasMany
    {
        return $this->hasMany(CallSurvey::class);
    }

    /** @return HasMany<HoldMusicTrack, $this> */
    public function holdMusicTracks(): HasMany
    {
        return $this->hasMany(HoldMusicTrack::class)->orderBy('position')->orderBy('id');
    }

    public function callRecordings(): HasMany
    {
        return $this->hasMany(CallRecording::class);
    }

    /** The plan whose limits apply now (an expired team keeps its plan, read-only). */
    public function plan(): Plan
    {
        return $this->subscription?->planDetails()
            ?? Plan::findOrFail((string) config('billing.trial_plan', 'pro'));
    }

    public function hasUsableSubscription(): bool
    {
        return (bool) $this->subscription?->isUsable();
    }

    public function asteriskContext(): string
    {
        return 't_'.$this->asterisk_key;
    }

    private static function generateAsteriskKey(): string
    {
        do {
            $key = Str::lower(Str::random(10));
        } while (static::withTrashed()->where('asterisk_key', $key)->exists());

        return $key;
    }

    /**
     * Get the team owner.
     */
    public function owner(): ?Model
    {
        return $this->members()
            ->wherePivot('role', TeamRole::Owner->value)
            ->first();
    }

    /**
     * Get all members of this team.
     *
     * @return BelongsToMany<User, $this, Membership, 'pivot'>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_members', 'team_id', 'user_id')
            ->using(Membership::class)
            ->withPivot(['role', 'supervisor_id'])
            ->withTimestamps();
    }

    /**
     * Get all memberships for this team.
     *
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * Get all invitations for this team.
     *
     * @return HasMany<TeamInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_personal' => 'boolean',
            'planned_agents' => 'integer',
            'marketing_opt_in' => 'boolean',
            'service_level_enabled' => 'boolean',
            'service_level_target' => 'integer',
            'profile_completed_at' => 'datetime',
            'onboarded_at' => 'datetime',
        ];
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
