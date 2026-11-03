<?php

namespace App\Enums;

/** Where an inbound call can be sent: DIDs, IVR options and fallbacks, queue overflow. */
enum RouteDestination: string
{
    case Queue = 'queue';
    case Ivr = 'ivr';
    case Extension = 'extension';
    /** Register a callback request on a queue and hang up. */
    case Callback = 'callback';
    /** Play a sound, then hang up. */
    case Message = 'message';
    case Hangup = 'hangup';

    public function label(): string
    {
        return match ($this) {
            self::Queue => __("File d'attente"),
            self::Ivr => __('Menu vocal (SVI)'),
            self::Extension => __('Poste'),
            self::Callback => __('Demande de rappel'),
            self::Message => __('Message puis raccrocher'),
            self::Hangup => __('Raccrocher'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $d) => ['value' => $d->value, 'label' => $d->label()], self::cases());
    }
}
