<?php

namespace App\Enums;

/**
 * Preview: the agent sees the contact, then the call is placed (agent first).
 * Progressive: one customer call per free agent, answered calls are queued to agents.
 * Power: several calls per free agent (dial ratio), throttled by the abandon rate.
 */
enum DialMode: string
{
    case Preview = 'preview';
    case Progressive = 'progressive';
    case Power = 'power';

    public function label(): string
    {
        return match ($this) {
            self::Preview => __('Aperçu (preview)'),
            self::Progressive => __('Progressif'),
            self::Power => __('Power (multi-lignes)'),
        };
    }

    public function dialsAutomatically(): bool
    {
        return $this !== self::Preview;
    }
}
