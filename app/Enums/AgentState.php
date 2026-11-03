<?php

namespace App\Enums;

/**
 * What the agent chose in the status menu. The softphone decides whether
 * the agent is online at all; this decides whether queue calls reach them.
 */
enum AgentState: string
{
    /** Takes queue calls. */
    case Ready = 'ready';

    /** Paused, optionally with a reason (Déjeuner, Formation…). */
    case NotReady = 'not_ready';

    /** Paused to finish the notes of the last call. */
    case WrapUp = 'wrap_up';

    /** Paused, and direct calls are declined too. */
    case DoNotDisturb = 'dnd';

    /** Out of every queue even while the softphone stays registered. */
    case LoggedOut = 'logged_out';

    public function label(): string
    {
        return match ($this) {
            self::Ready => __('Prêt'),
            self::NotReady => __('Non prêt'),
            self::WrapUp => __('Traitement post-appel'),
            self::DoNotDisturb => __('Ne pas déranger'),
            self::LoggedOut => __('Déconnecté'),
        };
    }

    /** Whether the agent sits in the queues but paused. */
    public function pausesQueues(): bool
    {
        return in_array($this, [self::NotReady, self::WrapUp, self::DoNotDisturb], true);
    }

    /** The reason Asterisk shows for the pause (queue show, QueueMemberStatus events). */
    public function asteriskReason(?string $reason): string
    {
        return match ($this) {
            self::NotReady => $reason !== null && $reason !== '' ? $reason : 'Non prêt',
            self::WrapUp => 'Post-appel',
            self::DoNotDisturb => 'Ne pas déranger',
            default => '',
        };
    }

    /** "Non prêt – Déjeuner", or the plain label. */
    public function labelWith(?string $reason): string
    {
        return $this === self::NotReady && $reason !== null && $reason !== ''
            ? $this->label().' – '.$reason
            : $this->label();
    }
}
