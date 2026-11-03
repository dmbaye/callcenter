<?php

namespace App\Enums;

enum CampaignStatus: string
{
    case Draft = 'draft';
    case Running = 'running';
    case Paused = 'paused';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Brouillon'),
            self::Running => __('En cours'),
            self::Paused => __('En pause'),
            self::Completed => __('Terminée'),
        };
    }
}
