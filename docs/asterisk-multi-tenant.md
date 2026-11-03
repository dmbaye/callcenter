# Asterisk Multi-Tenant Provisioning

The call-center application now treats each team as an Asterisk tenant. The tenant identity is **not** derived from the editable team name or slug.

## Immutable identifiers

Each team has:

- `teams.uuid`: application identity.
- `teams.asterisk_key`: 10-character lowercase alphanumeric key generated once.

The Asterisk naming contract is:

| Resource                | Pattern             | Example              |
| ----------------------- | ------------------- | -------------------- |
| PJSIP endpoint/auth/AOR | `{key}_{extension}` | `k3f9x2abq1_7001`    |
| Queue                   | `{key}_{slug}`      | `k3f9x2abq1_support` |
| Dialplan context        | `t_{key}`           | `t_k3f9x2abq1`       |
| CDR `accountcode`       | `{key}`             | `k3f9x2abq1`         |

The generated endpoint ID is at most 31 characters for a 20-digit extension, safely below the stock 40-character `ps_endpoints.id` limit. `accountcode` is capped at 20 characters when written to Asterisk.

## Laravel source of truth

- `phone_extensions`: team + user + extension + encrypted secret.
- `call_queues`: team + editable display name + immutable Asterisk queue name.
- `queue_memberships`: user-to-queue membership and penalty.

The old `users.sip_extension`, `users.sip_secret`, and `users.queue` columns remain only as a compatibility bridge during the rollout. New application code does not use them.

## Provisioning

After migrations, provision a team:

```bash
php artisan asterisk:provision-team TEAM_SLUG
```

This creates the tenant dialplan context and PJSIP endpoints for the team's `phone_extensions`. To intentionally replace an existing tenant extension pattern:

```bash
php artisan asterisk:provision-team TEAM_SLUG --replace-dialplan
```

The tenant context contains the internal extension route (any 2–20 digit extension; more specific patterns you add on the Dialplan page win):

```text
_X.,1,Set(CHANNEL(accountcode)={asterisk_key})
_X.,2,Dial(PJSIP/{asterisk_key}_${EXTEN},30)
_X.,3,Hangup()
```

It also contains one exact extension per queue, named after the queue's immutable Asterisk name. It stamps the CDR accountcode, applies the queue's opening hours (`GotoIfTime`; outside them the closed sound plays) and enters the queue:

```text
{key}_support,1,Set(CHANNEL(accountcode)={key})
{key}_support,2,GotoIfTime(08:00-16:00,mon|tue|wed|thu|fri,*,*?3:5)
{key}_support,3,Queue({key}_support,Tr)
{key}_support,4,Hangup()
{key}_support,5,Playback(callcenter-closed)
{key}_support,6,Hangup()
```

Inbound DIDs should `Goto(t_{key},{key}_support,1)`; blind transfers land on the same extension. The entry is rewritten whenever a queue is created or its opening hours change (`php artisan asterisk:sync-queues` rewrites all of them). Asterisk must declare every tenant context: see `php artisan asterisk:contexts-config` and deploy/README.md.

PJSIP auth usernames are the full endpoint IDs, not bare extensions.

## Existing Asterisk installation migration

Run a dry run first:

```bash
php artisan asterisk:migrate-tenancy --dry-run
```

Then perform the maintenance-window migration:

```bash
php artisan asterisk:migrate-tenancy
```

Use `--keep-legacy` if you want the old endpoint/queue rows retained for a staged cut-over.

The migration command:

1. Copies `PJSIP/{extension}` to `{key}_{extension}` across `ps_endpoints`, `ps_auths`, and `ps_aors`.
2. Sets the endpoint `accountcode` to the team's key when the Asterisk schema exposes that column.
3. Changes `ps_auths.username` to the full endpoint ID.
4. Changes realtime queue member interfaces to `PJSIP/{key}_{extension}`.
5. Copies each application-owned legacy queue into its immutable `{key}_{slug}` name.
6. Updates historical CDR rows for migrated extensions with the correct team `accountcode`.
7. Removes legacy rows after all tenant copies are made, unless `--keep-legacy` is used.

A legacy Asterisk queue that has no corresponding `call_queues` record cannot be assigned to a tenant automatically; map such queues to a team before removing the legacy row.

## WebRTC credentials

The current team's SIP credentials (and the configured STUN/TURN servers) are shared with every page, because the global softphone lives in the app layout. The browser registers with:

```text
username = {asterisk_key}_{extension}
```

The WebRTC composable prefers `pjsip_name` when present.

## CDR reporting

Team-wide reporting filters CDRs by:

```sql
accountcode = teams.asterisk_key
```

Agent extraction then strips `{asterisk_key}_` from the PJSIP channel to recover the human extension.

This removes the previous security and correctness problem of identifying a tenant by a list of globally unique user extensions.

## Queue behavior

Agents remain direct Asterisk queue members:

```text
PJSIP/{key}_{extension}
```

No `Local/` channels are introduced. Presence follows the softphone: once SIP is registered the browser joins every `queue_memberships` queue of the team (an existing pause is kept) and sends a heartbeat; unregistering, closing the tab, logging out or switching teams leaves them. `agents:prune-presence` (scheduled every minute) removes agents whose heartbeat stopped.

Multiple devices should use the same endpoint with `max_contacts > 1`, or separate `phone_extensions` when separate identities are required.

## Rollout order

1. Run Laravel migrations and verify every team has `uuid` and `asterisk_key`.
2. Verify `phone_extensions`, `call_queues`, and `queue_memberships` backfill results.
3. Check the actual Asterisk schema with `SHOW CREATE TABLE ps_endpoints;`, `SHOW CREATE TABLE ps_auths;`, `SHOW CREATE TABLE ps_aors;`, `SHOW CREATE TABLE queues;`, and `SHOW CREATE TABLE cdr;`.
4. Schedule a maintenance window; registrations will be interrupted while endpoint IDs change.
5. Run `asterisk:migrate-tenancy --dry-run`, review the mapping, then run it for real.
6. Run `asterisk:provision-team TEAM_SLUG` for each team.
7. Reload Asterisk's realtime/PJSIP/dialplan state as required by the installed realtime configuration.
8. Re-register WebRTC clients; they now use the full PJSIP endpoint ID.
9. Verify calls, queue membership, monitoring, transfers, CDR `accountcode`, and cross-team isolation.
10. The legacy `users.sip_extension`, `users.sip_secret` and `users.queue` columns are dropped by migration `2026_10_27_000003` once the backfill above has run.
