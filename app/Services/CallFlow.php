<?php

namespace App\Services;

use App\Enums\RouteDestination;
use App\Models\CallQueue;
use App\Models\InboundNumber;
use App\Models\IvrMenu;
use App\Models\Team;

/**
 * The team's inbound call plan as a readable tree, built from the same
 * settings the dialplan is generated from: number → menu → keys → queues,
 * with each queue's hours, agents and overflow. For people who should not
 * have to read dialplan rows.
 */
class CallFlow
{
    private const DAYS = [
        'mon' => 'lun',
        'tue' => 'mar',
        'wed' => 'mer',
        'thu' => 'jeu',
        'fri' => 'ven',
        'sat' => 'sam',
        'sun' => 'dim',
    ];

    /** @var array<string, true> nodes already expanded on the current path */
    private array $visited = [];

    /** @var array<string, true> everything reachable from a number */
    private array $reached = [];

    public function __construct(private QueueOpeningHours $hours) {}

    /**
     * @return array{numbers: list<array<string, mixed>>, unreached: list<array<string, mixed>>}
     */
    public function forTeam(Team $team): array
    {
        $this->reached = [];
        $team->loadMissing(['callQueues.hours', 'callQueues.closures', 'callQueues.members:users.id', 'ivrMenus', 'phoneExtensions.user:id,name']);

        $numbers = $team->inboundNumbers()->orderBy('number')->get()->map(function (InboundNumber $number) use ($team) {
            $this->visited = [];

            return $this->node('number', 'Numéro '.$number->number.($number->label ? ' — '.$number->label : ''), [], [
                $this->destination($team, $number->destination_type, $number->destination_target),
            ], 'ivr');
        })->all();

        // Queues and menus no number leads to (still reachable by transfer).
        $unreached = [];

        foreach ($team->ivrMenus as $menu) {
            if (! isset($this->reached['ivr:'.$menu->id])) {
                $this->visited = [];
                $unreached[] = $this->menu($team, $menu);
            }
        }

        foreach ($team->callQueues as $queue) {
            if (! isset($this->reached['queue:'.$queue->id])) {
                $this->visited = [];
                $unreached[] = $this->queue($team, $queue);
            }
        }

        return ['numbers' => $numbers, 'unreached' => $unreached];
    }

    /** @return array<string, mixed> */
    private function destination(Team $team, ?string $type, ?string $target): array
    {
        return match (RouteDestination::tryFrom((string) $type)) {
            RouteDestination::Queue => ($queue = $team->callQueues->firstWhere('id', (int) $target))
                ? $this->queue($team, $queue)
                : $this->missing(),
            RouteDestination::Ivr => ($menu = $team->ivrMenus->firstWhere('id', (int) $target))
                ? $this->menu($team, $menu)
                : $this->missing(),
            RouteDestination::Extension => $this->node('extension', 'Poste '.$target.$this->extensionOwner($team, (string) $target), [], [], 'pjsip'),
            RouteDestination::Callback => $this->node('callback', 'Enregistrer une demande de rappel'.(($queue = $team->callQueues->firstWhere('id', (int) $target)) ? ' pour « '.$queue->name.' »' : ''), ['Puis raccrocher.']),
            RouteDestination::Message => $this->node('message', 'Message « '.$target.' » puis raccrocher'),
            default => $this->node('hangup', 'Raccrocher'),
        };
    }

    /** @return array<string, mixed> */
    private function menu(Team $team, IvrMenu $menu): array
    {
        $key = 'ivr:'.$menu->id;
        $this->reached[$key] = true;

        if (isset($this->visited[$key])) {
            return $this->node('ivr', 'Retour au menu « '.$menu->name.' »', [], [], 'ivr');
        }

        $this->visited[$key] = true;

        $children = collect($menu->options)->map(fn (array $option) => $this->node(
            'key',
            'Touche '.$option['digit'].(($option['label'] ?? '') !== '' ? ' — '.$option['label'] : ''),
            [],
            [$this->destination($team, $option['type'] ?? null, isset($option['target']) ? (string) $option['target'] : null)],
        ))->all();

        $children[] = $this->node('key', 'Aucune touche ou touche invalide ('.$menu->max_retries.' essais)', [], [
            $this->destination($team, $menu->fallback_type, $menu->fallback_target),
        ]);

        unset($this->visited[$key]);

        return $this->node('ivr', 'Menu vocal « '.$menu->name.' »', ['Message d\'accueil : '.$menu->greeting_sound], $children, 'ivr');
    }

    /** @return array<string, mixed> */
    private function queue(Team $team, CallQueue $queue): array
    {
        $key = 'queue:'.$queue->id;
        $this->reached[$key] = true;

        if (isset($this->visited[$key])) {
            return $this->node('queue', 'Retour à la file « '.$queue->name.' »', [], [], 'queues');
        }

        $this->visited[$key] = true;

        $details = [];

        if ($queue->emergency_closed) {
            $details[] = 'FERMÉE EN URGENCE : les appelants entendent le message de fermeture.';
        }

        $details[] = $this->hoursSummary($queue);

        $closures = $queue->closures->filter(fn ($closure) => $closure->date->gte(now()->startOfDay()));

        if ($closures->isNotEmpty()) {
            $details[] = 'Fermée le '.$closures->take(3)->map(fn ($c) => $c->date->format('d/m'))->implode(', ').($closures->count() > 3 ? '…' : '');
        }

        $agents = $queue->members->count();
        $details[] = $agents === 0 ? 'Aucun agent affecté !' : $agents.' agent'.($agents > 1 ? 's' : '').' affecté'.($agents > 1 ? 's' : '');

        if ($queue->record_calls && $team->plan()->allows('call_recording')) {
            $details[] = 'Conversations enregistrées'.($queue->recording_notice ? ' (appelant prévenu).' : '.');
        }

        if ($queue->survey_enabled) {
            $details[] = 'Enquête de satisfaction après l\'appel.';
        }

        $when = $queue->max_wait
            ? 'Personne ne répond en '.$queue->max_wait.' s, file pleine ou sans agent'
            : 'File pleine ou sans agent';

        $after = [$this->destination($team, $queue->overflow_type, $queue->overflow_target)];

        if ($queue->callback_offer) {
            array_unshift($after, $this->node('callback', 'Proposer « tapez 1 pour être rappelé »', ['Sinon :']));
        }

        $children = [$this->node('branch', $when, [], $after)];

        unset($this->visited[$key]);

        return $this->node('queue', 'File d\'attente « '.$queue->name.' »', $details, $children, 'queues', $agents === 0 || $queue->emergency_closed);
    }

    private function hoursSummary(CallQueue $queue): string
    {
        $groups = rescue(fn () => $this->hours->timeGroups($queue), [], false);

        if ($groups === []) {
            return 'Ouverte 24 h/24, 7 j/7.';
        }

        return 'Ouverte '.collect($groups)->map(fn (array $group) => implode(', ', array_map(
            fn (string $day) => self::DAYS[$day] ?? $day,
            explode('&', $group['days']),
        )).' de '.str_replace('-', ' à ', $group['times']))->implode(' ; ').'. Sinon : message de fermeture.';
    }

    private function extensionOwner(Team $team, string $extension): string
    {
        $name = $team->phoneExtensions->firstWhere('extension', $extension)?->user?->name;

        return $name ? ' ('.$name.')' : '';
    }

    /** @return array<string, mixed> */
    private function missing(): array
    {
        return $this->node('hangup', 'Destination supprimée : l\'appel est raccroché', ['À corriger.'], [], null, true);
    }

    /**
     * @param  list<string>  $details
     * @param  list<array<string, mixed>>  $children
     * @return array<string, mixed>
     */
    private function node(
        string $kind,
        string $label,
        array $details = [],
        array $children = [],
        ?string $link = null,
        bool $warning = false,
    ): array {
        return compact('kind', 'label', 'details', 'children', 'link', 'warning');
    }
}
