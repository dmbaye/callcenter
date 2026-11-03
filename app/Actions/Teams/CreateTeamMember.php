<?php

namespace App\Actions\Teams;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Services\AsteriskProvisioner;
use App\Services\PlanLimits;
use Illuminate\Support\Facades\DB;

/**
 * Admin-side creation of a ready-to-work member: account, membership and,
 * for those who take calls, extension + PJSIP endpoint and (optionally) a
 * first queue. Supervisors, planners and admins may have no phone at all.
 * Input is validated by CreateTeamMemberRequest.
 */
class CreateTeamMember
{
    public function __construct(private AsteriskProvisioner $provisioner, private PlanLimits $limits) {}

    /**
     * @param  array{name: string, email: string, password: string, role: string, queue?: string|null, sip_extension?: string|null, sip_secret?: string|null}  $input
     */
    public function handle(Team $team, array $input): User
    {
        $role = TeamRole::from($input['role']);

        abort_if($role === TeamRole::Owner, 422, __('The owner role cannot be assigned to a new member.'));

        $this->limits->ensureCanSeat($team, $role);

        return DB::transaction(function () use ($team, $input, $role) {
            // Created by an admin: the address is trusted (email_verified_at is not fillable).
            $user = User::query()->create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);
            $user->forceFill(['email_verified_at' => now(), 'current_team_id' => $team->id])->save();

            $team->memberships()->create([
                'user_id' => $user->id,
                'role' => $role,
            ]);

            if (empty($input['sip_extension'])) {
                return $user;
            }

            if (! empty($input['queue'])) {
                $team->callQueues()->where('name', $input['queue'])->firstOrFail()
                    ->members()->attach($user->id, ['penalty' => 0]);
            }

            $this->provisioner->saveExtension($team, $input['sip_extension'], [
                'user_id' => $user->id,
                'label' => $input['name'],
                'secret' => $input['sip_secret'],
            ]);

            return $user;
        });
    }
}
