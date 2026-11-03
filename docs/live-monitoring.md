# Live monitoring: push instead of polling

Supervisors no longer poll `/monitoring/status` every 3 s. Changes are pushed.

```
Asterisk ──AMI events──▶ asterisk:ami-listen ──▶ MonitoringChangeDetector
                                                        │ (only tenants whose state really changed)
Signed webhook ─────────────────────────────────────────┤
Pause / resume / login / logout ────────────────────────┤
                                                        ▼
                                          MonitoringChanged  (private-team.{id}.monitoring)
                                                        │  Reverb WebSocket
                                                        ▼
                                  browser: ping ─▶ GET /{team}/monitoring/status (authorized)
```

The WebSocket only carries a ping (`{at: <timestamp>}`); the data is still fetched through the
authorized endpoint, so no call data ever goes over the socket. If the socket is not configured or
drops, the page falls back to the old polling until it reconnects.

## Setup

1. `composer install && npm install` (laravel/reverb is already required).
2. In `.env`: set `BROADCAST_CONNECTION=reverb` (with `log` or `null` nothing is pushed), `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` (any random values) and
   `REVERB_HOST` / `REVERB_PORT` / `REVERB_SCHEME` to what the **browser** can reach (use `wss` + 443
   behind a reverse proxy that forwards to `REVERB_SERVER_PORT`).
   Keep `VITE_REVERB_*` pointing at those values (`VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"`, …).
3. Run `php artisan reverb:start` next to `php artisan asterisk:ami-listen` (`composer dev` starts both;
   in production use deploy/callcenter-reverb.service).
4. `npm run build`.
5. Asterisk `manager.conf`: the AMI user needs `read = call,agent,...` so events are delivered.
   Without it everything still works, with up to `ASTERISK_AMI_SNAPSHOT_INTERVAL` seconds of latency.

## Signed webhook

For anything that is not the daemon (dialplan hook, external script):

```bash
BODY='{"team_key":"<team asterisk_key>"}'
SIG=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "$ASTERISK_WEBHOOK_SECRET" | awk '{print $2}')
curl -X POST https://your-app/webhooks/asterisk/monitoring \
  -H "Content-Type: application/json" -H "X-Signature: $SIG" -d "$BODY"
```

Empty `ASTERISK_WEBHOOK_SECRET` disables the endpoint. It always answers `202` for a valid signature,
whether or not the tenant exists.
