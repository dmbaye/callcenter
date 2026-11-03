<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\Team;
use App\Services\Concerns\UsesAsteriskConnection;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The single carrier trunk every outgoing call uses: campaign calls (the app
 * originates PJSIP/{number}@{trunk}) and manual calls from the softphone
 * (the shared cc-outbound context dials through it).
 *
 * Chosen by a platform admin and stored in the database; ASTERISK_CAMPAIGN_TRUNK
 * is only the fallback until one is chosen.
 */
class OutboundTrunk
{
    use UsesAsteriskConnection;

    /** Agent endpoints are `{10-char team key}_{extension}`: never a trunk. */
    private const AGENT_ENDPOINT = '/^[a-z0-9]{10}_\d{2,20}$/';

    public function __construct(private AsteriskDialplan $dialplan) {}

    public function name(): string
    {
        return (string) PlatformSetting::get(
            PlatformSetting::OUTBOUND_TRUNK,
            (string) config('asterisk.campaigns.trunk'),
        );
    }

    /** Whether the trunk comes from the database or only from .env. */
    public function isStored(): bool
    {
        return PlatformSetting::get(PlatformSetting::OUTBOUND_TRUNK) !== null;
    }

    /**
     * PJSIP endpoints of the realtime database that can be a trunk
     * (not an agent softphone). Null when the Asterisk database is unreachable.
     *
     * @return list<array{id: string, context: string|null, contact: string|null}>|null
     */
    public function candidates(): ?array
    {
        try {
            $hasWebrtc = in_array('webrtc', $this->asteriskColumns('ps_endpoints'), true);
            $hasContact = in_array('contact', $this->asteriskColumns('ps_aors'), true);

            $rows = $this->connection()->table('ps_endpoints as e')
                ->leftJoin('ps_aors as a', 'a.id', '=', 'e.aors')
                ->orderBy('e.id')
                ->get(array_values(array_filter([
                    'e.id',
                    'e.context',
                    $hasContact ? 'a.contact' : null,
                    $hasWebrtc ? 'e.webrtc' : null,
                ])));
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        return $rows
            ->reject(fn ($row) => preg_match(self::AGENT_ENDPOINT, (string) $row->id)
                || in_array(strtolower((string) ($row->webrtc ?? '')), ['yes', 'true', '1'], true))
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'context' => $row->context !== null ? (string) $row->context : null,
                'contact' => isset($row->contact) ? (string) $row->contact : null,
            ])
            ->values()
            ->all();
    }

    /**
     * Save the trunk, then rewrite the shared outbound context and every
     * team's outbound route so the change applies to the next call.
     */
    public function set(string $trunk): void
    {
        $ids = array_column($this->candidates() ?? [], 'id');

        if (! in_array($trunk, $ids, true)) {
            throw ValidationException::withMessages([
                'trunk' => __('This endpoint is not a trunk of the Asterisk database.'),
            ]);
        }

        PlatformSetting::put(PlatformSetting::OUTBOUND_TRUNK, $trunk);

        $this->dialplan->provisionOutboundContext($trunk);

        Team::query()->whereNotNull('asterisk_key')->each(function (Team $team): void {
            rescue(fn () => $this->dialplan->ensureOutboundDialing($team), report: true);
        });
    }
}
