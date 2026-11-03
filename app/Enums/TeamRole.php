<?php

namespace App\Enums;

enum TeamRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Supervisor = 'supervisor';
    case Planner = 'planner';
    case Member = 'member';

    /**
     * Get the display label for the role.
     */
    public function label(): string
    {
        return match ($this) {
            self::Owner => __('Propriétaire'),
            self::Admin => __('Administrateur'),
            self::Supervisor => __('Superviseur'),
            self::Planner => __('Planificateur'),
            self::Member => __('Membre'),
        };
    }

    /**
     * Get all the permissions for this role.
     *
     * @return array<TeamPermission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => TeamPermission::cases(),
            self::Admin => [
                TeamPermission::UpdateTeam,
                TeamPermission::AddMember,
                TeamPermission::UpdateMember,
                TeamPermission::RemoveMember,
                TeamPermission::CreateInvitation,
                TeamPermission::CancelInvitation,
                TeamPermission::MonitorAgents,
            ],
            self::Supervisor => [
                TeamPermission::MonitorAgents,
            ],
            // Workforce only (see managesWorkforce): no live monitoring, no campaigns.
            self::Planner => [],
            self::Member => [],
        };
    }

    /**
     * Determine if the role has the given permission.
     */
    public function hasPermission(TeamPermission $permission): bool
    {
        return in_array($permission, $this->permissions());
    }

    /**
     * Get the hierarchy level for this role.
     * Higher numbers indicate higher privileges.
     */
    public function level(): int
    {
        return match ($this) {
            self::Owner => 4,
            self::Admin => 3,
            self::Supervisor => 2,
            // Same rank as a member outside the Planning module.
            self::Planner => 1,
            self::Member => 1,
        };
    }

    /** Can open the roster and review planning requests (swaps, leave, timesheets). */
    public function canPlan(): bool
    {
        return $this->isAtLeast(self::Supervisor) || $this === self::Planner;
    }

    /**
     * In charge of workforce for the whole team: every agent's requests,
     * shift blocks, labour rules and leave types.
     */
    public function managesWorkforce(): bool
    {
        return $this->isAtLeast(self::Admin) || $this === self::Planner;
    }

    /**
     * Check if this role is at least as privileged as another role.
     */
    public function isAtLeast(TeamRole $role): bool
    {
        return $this->level() >= $role->level();
    }

    /**
     * Get the roles that can be assigned to team members (excludes Owner).
     *
     * @return array<array{value: string, label: string}>
     */
    public static function assignable(): array
    {
        return collect(self::cases())
            ->filter(fn (self $role) => $role !== self::Owner)
            ->map(fn (self $role) => [
                'value' => $role->value,
                'label' => $role->label(),
            ])
            ->values()
            ->toArray();
    }
}
