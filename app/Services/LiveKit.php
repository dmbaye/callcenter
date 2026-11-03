<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Self-hosted LiveKit: join tokens and the server API (Twirp over HTTP).
 *
 * Access tokens are HS256 JWTs signed with the API secret; generated here
 * without a JWT library (the format is small and fixed).
 *
 * @see https://docs.livekit.io/home/get-started/authentication/
 */
class LiveKit
{
    public function isConfigured(): bool
    {
        return filled(config('livekit.url')) && filled(config('livekit.api_key')) && filled(config('livekit.api_secret'));
    }

    public function url(): string
    {
        return (string) config('livekit.url');
    }

    /**
     * Join token for one participant in one room.
     *
     * @param  array{canPublish?: bool, canSubscribe?: bool, canPublishData?: bool, roomAdmin?: bool}  $grants
     * @param  array<string, mixed>  $metadata
     */
    public function joinToken(
        string $room,
        string $identity,
        string $name,
        array $grants = [],
        array $metadata = [],
    ): string {
        return $this->sign([
            'sub' => $identity,
            'name' => $name,
            'metadata' => $metadata === [] ? '' : (string) json_encode($metadata),
            'video' => [
                'room' => $room,
                'roomJoin' => true,
                'canPublish' => $grants['canPublish'] ?? true,
                'canSubscribe' => $grants['canSubscribe'] ?? true,
                'canPublishData' => $grants['canPublishData'] ?? true,
                'roomAdmin' => $grants['roomAdmin'] ?? false,
            ],
        ], (int) config('livekit.token_ttl', 3600));
    }

    /**
     * Create the room, or return the existing one (CreateRoom is idempotent).
     * The participant cap is enforced by LiveKit itself.
     *
     * @return array<string, mixed>
     */
    public function createRoom(string $room, int $maxParticipants, array $metadata = []): array
    {
        return $this->call('livekit.RoomService', 'CreateRoom', [
            'name' => $room,
            'empty_timeout' => (int) config('livekit.empty_timeout', 300),
            'max_participants' => $maxParticipants,
            'metadata' => $metadata === [] ? '' : (string) json_encode($metadata),
        ], ['roomCreate' => true]);
    }

    /** Disconnect everyone and close the room. */
    public function deleteRoom(string $room): void
    {
        $this->call('livekit.RoomService', 'DeleteRoom', ['room' => $room], ['roomCreate' => true]);
    }

    /** @return list<array<string, mixed>> */
    public function participants(string $room): array
    {
        $response = $this->call('livekit.RoomService', 'ListParticipants', ['room' => $room], ['roomAdmin' => true, 'room' => $room]);

        return array_values((array) ($response['participants'] ?? []));
    }

    /** Start a composite (grid) MP4 recording; returns the egress id. */
    public function startRecording(string $room): string
    {
        $response = $this->call('livekit.Egress', 'StartRoomCompositeEgress', [
            'room_name' => $room,
            'layout' => (string) config('livekit.recording.layout', 'grid'),
            'file_outputs' => [[
                'file_type' => 'MP4',
                'filepath' => (string) config('livekit.recording.filepath'),
            ]],
        ], ['roomRecord' => true]);

        $egressId = $response['egress_id'] ?? $response['egressId'] ?? null;

        if (! is_string($egressId) || $egressId === '') {
            throw new RuntimeException('LiveKit did not return an egress id.');
        }

        return $egressId;
    }

    public function stopRecording(string $egressId): void
    {
        $this->call('livekit.Egress', 'StopEgress', ['egress_id' => $egressId], ['roomRecord' => true]);
    }

    /**
     * Verify a webhook: the Authorization header is a JWT signed with the API
     * secret whose `sha256` claim is the base64 SHA-256 of the raw body.
     *
     * @return array<string, mixed>|null the decoded event, null when invalid
     */
    public function verifyWebhook(string $body, ?string $authorization): ?array
    {
        if (! $this->isConfigured() || $authorization === null || $authorization === '') {
            return null;
        }

        $token = preg_replace('/^Bearer\s+/i', '', trim($authorization)) ?? '';
        $claims = $this->decode($token);

        if ($claims === null || ($claims['iss'] ?? null) !== config('livekit.api_key')) {
            return null;
        }

        if (! hash_equals((string) ($claims['sha256'] ?? ''), base64_encode(hash('sha256', $body, true)))) {
            return null;
        }

        $event = json_decode($body, true);

        return is_array($event) ? $event : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $videoGrant
     * @return array<string, mixed>
     */
    private function call(
        string $service,
        string $method,
        array $payload,
        array $videoGrant,
    ): array {
        if (! $this->isConfigured()) {
            throw new RuntimeException('LiveKit is not configured (LIVEKIT_URL, LIVEKIT_API_KEY, LIVEKIT_API_SECRET).');
        }

        try {
            $response = $this->http()
                ->withToken($this->sign(['video' => $videoGrant], 60))
                ->post("/twirp/{$service}/{$method}", $payload);
        } catch (ConnectionException $exception) {
            // Callers handle RuntimeException as "video server unreachable".
            throw new RuntimeException(sprintf('LiveKit %s/%s unreachable: %s', $service, $method, $exception->getMessage()), 0, $exception);
        }

        if (! $response->successful()) {
            throw new RuntimeException(sprintf('LiveKit %s/%s failed: HTTP %d %s', $service, $method, $response->status(), $response->body()));
        }

        return (array) $response->json();
    }

    /** @param  array<string, mixed>  $claims */
    private function sign(array $claims, int $ttl): string
    {
        $now = time();
        $payload = [
            'iss' => (string) config('livekit.api_key'),
            'nbf' => $now - 10,
            'exp' => $now + $ttl,
            ...$claims,
        ];

        $segments = [
            $this->base64Url((string) json_encode(['alg' => 'HS256', 'typ' => 'JWT'])),
            $this->base64Url((string) json_encode($payload, JSON_UNESCAPED_SLASHES)),
        ];
        $segments[] = $this->base64Url(hash_hmac('sha256', implode('.', $segments), (string) config('livekit.api_secret'), true));

        return implode('.', $segments);
    }

    /** @return array<string, mixed>|null claims of a valid, unexpired HS256 token */
    private function decode(string $token): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        [$header, $payload, $signature] = $parts;
        $headerData = json_decode($this->base64UrlDecode($header), true);

        if (! is_array($headerData) || ($headerData['alg'] ?? null) !== 'HS256') {
            return null;
        }

        $expected = $this->base64Url(hash_hmac('sha256', $header.'.'.$payload, (string) config('livekit.api_secret'), true));

        if (! hash_equals($expected, $signature)) {
            return null;
        }

        $claims = json_decode($this->base64UrlDecode($payload), true);

        if (! is_array($claims)) {
            return null;
        }

        $now = time();

        if ((isset($claims['exp']) && $now > (int) $claims['exp'] + 30) || (isset($claims['nbf']) && $now + 30 < (int) $claims['nbf'])) {
            return null;
        }

        return $claims;
    }

    private function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('livekit.api_url'), '/'))
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('livekit.timeout', 5));
    }
}
