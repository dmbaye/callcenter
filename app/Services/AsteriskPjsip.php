<?php

namespace App\Services;

use App\Models\Team;
use App\Services\Concerns\UsesAsteriskConnection;
use RuntimeException;

/**
 * Low-level access to the PJSIP realtime tables (ps_endpoints, ps_auths,
 * ps_aors). Endpoints are always team-scoped: id = {asterisk_key}_{extension}
 * and context = the team context. Application code should go through
 * AsteriskProvisioner so phone_extensions and Asterisk stay in sync.
 */
class AsteriskPjsip
{
    use UsesAsteriskConnection;

    /**
     * Endpoints of one tenant, with their AOR limit. Passwords are never
     * returned: the secret lives (encrypted) in phone_extensions.
     *
     * @return list<array<string, mixed>>
     */
    public function endpointsForTeam(Team $team): array
    {
        $prefix = $team->asterisk_key.'_';

        return $this->connection()->table('ps_endpoints as e')
            ->leftJoin('ps_aors as a', 'a.id', '=', 'e.id')
            ->where('e.id', 'like', $prefix.'%') // keys are fixed-length, so '_' needs no escaping
            ->orderBy('e.id')
            ->get(['e.id', 'e.transport', 'e.context', 'e.allow', 'e.aors', 'e.auth', 'a.max_contacts'])
            ->map(fn (object $row): array => (array) $row)
            ->all();
    }

    public function endpointId(Team $team, string $extension): string
    {
        if (! preg_match('/^\d{2,20}$/', $extension)) {
            throw new RuntimeException('Invalid SIP extension.');
        }

        $id = $team->asterisk_key.'_'.$extension;

        if (strlen($id) > 40) {
            throw new RuntimeException('The generated Asterisk endpoint id exceeds the 40-character realtime limit.');
        }

        return $id;
    }

    public function exists(string $id): bool
    {
        return $this->connection()->table('ps_endpoints')->where('id', $id)->exists();
    }

    /** Whether CDR tenancy can be stamped on endpoints (ps_endpoints.accountcode exists). */
    public function supportsAccountcode(): bool
    {
        return in_array('accountcode', $this->asteriskColumns('ps_endpoints'), true);
    }

    /**
     * @param  array{id: string, transport: string, context: string, allow: string, password: string, max_contacts: int, accountcode?: string}  $data
     */
    public function create(array $data): void
    {
        // Upserts: a leftover ps_auths / ps_aors row (e.g. an endpoint deleted
        // by hand in Asterisk) must be overwritten, not block the creation.
        $this->connection()->transaction(function () use ($data): void {
            $endpoint = [
                'id' => $data['id'],
                'transport' => $data['transport'],
                'aors' => $data['id'],
                'auth' => $data['id'],
                'context' => $data['context'],
                'disallow' => $data['disallow'] ?? 'all',
                'allow' => $data['allow'],
                'direct_media' => $data['direct_media'] ?? 'no',
                'use_avpf' => $data['use_avpf'] ?? 'yes',
                'media_encryption' => $data['media_encryption'] ?? 'dtls',
                'webrtc' => $data['webrtc'] ?? 'yes',
                ...$this->accountcodeValues($data),
            ];
            $this->connection()->table('ps_endpoints')->upsert($endpoint, ['id'], array_keys($endpoint));

            $auth = [
                'id' => $data['id'],
                'auth_type' => $data['auth_type'] ?? 'userpass',
                'username' => $data['id'],
                'password' => $data['password'],
            ];
            $this->connection()->table('ps_auths')->upsert($auth, ['id'], array_keys($auth));

            $aor = [
                'id' => $data['id'],
                'max_contacts' => $data['max_contacts'],
                'remove_existing' => $data['remove_existing'] ?? 'yes',
            ];
            $this->connection()->table('ps_aors')->upsert($aor, ['id'], array_keys($aor));
        });
    }

    /**
     * Partial update; only provided keys are written.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(string $id, array $data): void
    {
        if (! $this->exists($id)) {
            throw new RuntimeException("Endpoint [{$id}] not found.");
        }

        $this->connection()->transaction(function () use ($id, $data): void {
            $endpoint = array_filter(
                array_intersect_key($data, array_flip(['transport', 'context', 'allow'])),
                fn ($value) => $value !== null && $value !== '',
            );
            $endpoint = [...$endpoint, ...$this->accountcodeValues($data)];

            if ($endpoint !== []) {
                $this->connection()->table('ps_endpoints')->where('id', $id)->update($endpoint);
            }

            if (! empty($data['password'])) {
                $this->connection()->table('ps_auths')->where('id', $id)->update(['password' => $data['password']]);
            }

            if (isset($data['max_contacts'])) {
                $this->connection()->table('ps_aors')->where('id', $id)->update(['max_contacts' => (int) $data['max_contacts']]);
            }
        });
    }

    public function delete(string $id): void
    {
        $this->connection()->transaction(function () use ($id): void {
            $this->connection()->table('ps_endpoints')->where('id', $id)->delete();
            $this->connection()->table('ps_auths')->where('id', $id)->delete();
            $this->connection()->table('ps_aors')->where('id', $id)->delete();
        });
    }

    /**
     * Remove every endpoint of a tenant.
     */
    public function deleteTeam(Team $team): int
    {
        $like = $team->asterisk_key.'_%';

        return $this->connection()->transaction(function () use ($like): int {
            $count = $this->connection()->table('ps_endpoints')->where('id', 'like', $like)->delete();
            $this->connection()->table('ps_auths')->where('id', 'like', $like)->delete();
            $this->connection()->table('ps_aors')->where('id', 'like', $like)->delete();

            return $count;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function accountcodeValues(array $data): array
    {
        if (! isset($data['accountcode']) || ! $this->supportsAccountcode()) {
            return [];
        }

        return ['accountcode' => substr((string) $data['accountcode'], 0, 20)];
    }
}
