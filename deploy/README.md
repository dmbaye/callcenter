# Deployment

Four long-lived services run next to PHP-FPM, all as `www-data`:

| Unit                         | Role                                                        |
| ---------------------------- | ----------------------------------------------------------- |
| `callcenter-asterisk-ami`    | AMI listener daemon (live call state, spy, transfers)       |
| `callcenter-reverb`          | Reverb WebSocket server: pushes "monitoring changed" pings  |
| `callcenter-scheduler`       | `schedule:work` — runs `agents:prune-presence` every minute |
| `callcenter-queue`           | Queue worker (mail, notifications)                          |
| `callcenter-campaign-dialer` | Outbound campaign pacing (`campaigns:dial`)                 |

```bash
sudo cp deploy/callcenter-*.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now callcenter-asterisk-ami callcenter-reverb callcenter-scheduler callcenter-queue callcenter-campaign-dialer
```

## AMI listener

The web application never opens an AMI TCP connection per request. The
daemon keeps one authenticated AMI session, refreshes channel and queue
state, and executes monitoring/transfer commands; PHP-FPM talks to it over a
Unix socket. The unit creates `/run/callcenter` (`RuntimeDirectory=`), so set:

```dotenv
ASTERISK_AMI_SOCKET=/run/callcenter/asterisk-ami.sock
ASTERISK_AMI_SNAPSHOT_FILE=/run/callcenter/asterisk-ami-snapshot.json
ASTERISK_AMI_IPC_TIMEOUT=5
ASTERISK_AMI_SNAPSHOT_INTERVAL=2
```

PHP-FPM must run as `www-data` (or a member of its group) to use the socket.

Live state (channels, queues, members) is read from the snapshot file the
daemon rewrites after each refresh, not over the socket; the socket only
carries commands (originate, spy, transfer, dialplan reload). If the file is
older than `max(10, 3 × interval)` seconds it is ignored.

`IPC client left before the reply` in the daemon log means a request gave up
while the loop was busy: the line before it (`… took N s`) names the slow
step (AMI refresh across the network, Reverb push, event handling).

## Reverb

```dotenv
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=...        # random values, see .env.example
REVERB_APP_KEY=...
REVERB_APP_SECRET=...
REVERB_HOST=callcenter.example.com   # what the browser reaches
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_SERVER_HOST=127.0.0.1
REVERB_SERVER_PORT=8080              # proxied by nginx at /app and /apps
```

Rebuild the frontend (`npm run build`) after changing any `VITE_*` value.

## Asterisk side

Each team (tenant) uses the realtime dialplan context `t_{asterisk_key}`.
Asterisk only consults a realtime context declared in `extensions.conf`:

```bash
php artisan asterisk:contexts-config > /etc/asterisk/extensions_tenants.conf
# extensions.conf:  #include extensions_tenants.conf
asterisk -rx "dialplan reload"
```

Re-run it after creating a team. Route each inbound DID with
`Goto(t_{asterisk_key},{queue asterisk_name},1)`; that queue entry sets the CDR
accountcode, applies the opening hours and enters the queue (transfers use
the same entry).

`ps_endpoints` needs the `accountcode` column (stock realtime schema) so
calls are attributed to their team; the PJSIP settings page warns when it is
missing.

## Agent status (Prêt, Non prêt, Post-appel…)

The status menu in the app header sets what the agent chose; the softphone
still decides whether they are online at all.

| State | queue_members | Notes |
| --- | --- | --- |
| Prêt (Ctrl+Alt+R) | `paused = 0` | |
| Non prêt (Ctrl+Alt+N), Non prêt – motif | `paused = 1`, `reason_paused` = motif | motifs in Paramètres › Motifs de pause |
| Traitement post-appel (Ctrl+Alt+Z) | `paused = 1`, `Post-appel` | |
| Ne pas déranger | `paused = 1`, `Ne pas déranger` | direct calls are declined by the softphone |
| Déconnexion | row removed | the heartbeat no longer re-joins; « Connexion » puts the agent back in Prêt |

The state lives in `agent_states` and survives reloads, a second tab and
softphone reconnects. Each period is logged in `agent_state_logs`
(`started_at` / `ended_at`) for time-in-pause reports. `reason_paused` also
appears in `queue show` and in the QueueMemberPause AMI events.

## Subscriptions (PayDunya)

Plans, prices and limits live in `config/billing.php` (XOF). Payments go
through **PayDunya** (Orange Money Mali, Moov Money Mali, cards). CinetPay
is kept as an alternative driver for countries where it accepts merchants
(`BILLING_GATEWAY=cinetpay`); each payment remembers the provider it was
made with.

```dotenv
BILLING_GATEWAY=paydunya
PAYDUNYA_MODE=test           # test = sandbox keys and API, live = real payments
PAYDUNYA_MASTER_KEY=...      # also verifies the IPN (data[hash] = SHA-512 of it)
PAYDUNYA_PRIVATE_KEY=...     # test_private_... in test mode, live_private_... in live
PAYDUNYA_TOKEN=...
PAYDUNYA_CHANNELS=           # optional, comma-separated PayDunya channel names; empty = all enabled
BILLING_TRIAL_DAYS=14
```

1. Create a business account on PayDunya and an **integration** (application)
   in its dashboard: it gives the master key, private key and token (sandbox
   and live). Enable Orange Money Mali, Moov Money Mali and cards on it.
2. Start in `PAYDUNYA_MODE=test` with the sandbox keys and PayDunya's test
   customers, then switch to `live` with the live keys once the account is
   validated. A sandbox payment is never accepted in live mode.

- IPN URL (sent with each invoice as `callback_url`):
  `https://<APP_URL>/webhooks/paydunya`. It must be reachable from the
  internet over HTTPS.
- Return and cancel URL: `https://<APP_URL>/billing/return/{transaction}`
  (CSRF-exempt, works without a session; PayDunya adds `?token=`).
- A payment is only activated after a server-side `checkout-invoice/confirm`
  (status `completed`, amount at least the price, our transaction id in
  `custom_data`), never from the IPN fields alone. `billing:process`
  (hourly, run by `callcenter-scheduler`) sends reminders 7 days before the
  end of a period, makes unpaid teams read-only after the grace period
  (agents leave the queues) and re-checks pending payments whose IPN was lost.
- `php artisan migrate` puts every existing team on the trial so nothing
  stops on deploy.

CinetPay settings, if used: `CINETPAY_API_KEY`, `CINETPAY_SITE_ID`,
`CINETPAY_SECRET_KEY` (verifies the x-token), notification URL
`https://<APP_URL>/webhooks/cinetpay`.

## Video meetings (LiveKit, self-hosted)

1. Install the `livekit-server` binary, create a `livekit` system user, copy
   `deploy/livekit.yaml` to `/etc/livekit/livekit.yaml` and fill in keys,
   TURN credentials and the webhook URL.
2. `sudo cp deploy/livekit.service /etc/systemd/system/ && sudo systemctl enable --now livekit`
3. Open the firewall: TCP 7881 and UDP 50000-60000 (media). Port 7880 stays
   local; nginx proxies the browser WebSocket:

```nginx
server {
    server_name video.example.com;
    listen 443 ssl http2;
    # ssl_certificate ...;
    location / {
        proxy_pass http://127.0.0.1:7880;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_read_timeout 3600s;
    }
}
```

4. `.env`:

```dotenv
LIVEKIT_URL=wss://video.example.com        # browsers
LIVEKIT_API_URL=http://127.0.0.1:7880      # Laravel -> server API
LIVEKIT_API_KEY=APIxxxxxxxxxxxx
LIVEKIT_API_SECRET=...
```

Bandwidth: video is sent at 360p with simulcast (180p/360p) and each viewer
only receives the layer it displays. Plan on about 0.3-0.8 Mbit/s per
participant downstream for a grid, and size the TURN server's outbound
bandwidth for users who cannot reach UDP 50000-60000 directly.

Recording (Entreprise) needs the LiveKit Egress service (plus Redis, as
Egress requires) and `LIVEKIT_RECORDING_ENABLED=true`; files are written to
`LIVEKIT_RECORDING_PATH` on the egress host.

## Outbound campaigns

How a call flows:

| Mode                | Who is called first                                                                                                       | Placed by                               |
| ------------------- | ------------------------------------------------------------------------------------------------------------------------- | --------------------------------------- |
| Preview / callbacks | the agent's softphone, then the customer once the agent answers                                                           | the agent (Call button)                 |
| Progressive         | the customer; once answered (and past AMD) the call enters the campaign queue                                             | `campaigns:dial`, 1 call per free agent |
| Power               | same, `dial_ratio` calls per free agent; falls back to 1:1 while the last hour's abandon rate is above the campaign limit | `campaigns:dial`                        |

`campaigns:dial` decides, the AMI daemon (`asterisk:ami-listen`) originates
and reads the results (OriginateResponse, AgentConnect and `UserEvent`s sent
by the campaign dialplan). Both services must run.

1. **Dialplan.** Write the shared realtime context and declare it:

    ```bash
    php artisan asterisk:campaign-dialplan
    php artisan asterisk:contexts-config > /etc/asterisk/extensions_tenants.conf   # now includes [cc-campaign]
    asterisk -rx "dialplan reload"
    ```

    It uses `AMD()` (app_amd), `Queue()`, `Dial()`, `UserEvent()` and hangup
    handlers: all stock Asterisk modules.

2. **AMI permissions.** The listener now also needs `agent` and `user` events:

    ```ini
    [callcenter]
    read = system,call,agent,user
    write = originate,call,command
    ```

    and `ASTERISK_AMI_EVENTS=call,agent,user` (the default).

3. **Trunk.** Customer calls go out as `PJSIP/{prefix}{number}@{trunk}`.
   Create your SIP provider endpoint in PJSIP (realtime or file), then choose
   it in **Administration → Plateforme** (see "Outside calls" below).
   `ASTERISK_CAMPAIGN_TRUNK` is only the fallback until a trunk is saved there:

    ```dotenv
    ASTERISK_CAMPAIGN_TRUNK=orange_trunk        # fallback until a trunk is chosen in Administration → Plateforme
    ASTERISK_CAMPAIGN_DIAL_PREFIX=00            # 00 + 22376123456
    ASTERISK_CAMPAIGN_COUNTRY_CODE=223          # added to 8-digit national numbers on import
    ASTERISK_CAMPAIGN_NATIONAL_LENGTH=8
    ```

4. **Sounds (optional).** The abandon message and the answering-machine
   message are Asterisk sound names (e.g. `custom/campagne-desole`), placed in
   the Asterisk sounds directory.

5. **Service:** `deploy/callcenter-campaign-dialer.service`. In development
   `composer dev` (`php artisan dev`) starts it with the AMI listener, Reverb
   and the scheduler.

### Progressive / power campaign places no call?

```bash
php artisan campaigns:dial --explain
```

It prints, for each progressive/power campaign, what stops it. The agent
desktop (Poste agent) shows the same list under the campaign. Usual causes:

- `campaigns:dial` is not running (nothing dials without it; preview mode
  does not need it);
- `asterisk:ami-listen` is not running, so the dialer has no view of the agents;
- the campaign is not **started**, or it is outside its calling hours;
- the agent is not a member of the campaign queue, is paused / Non prêt, or
  the softphone is not registered (queue member state "unavailable");
- the agent has not qualified the previous campaign call (wrap-up);
- no contact is due (list done, retries scheduled later, Do-Not-Call).

Compliance: numbers on the team's Do-Not-Call list are never dialled, calls
only go out inside each campaign's calling hours (campaign timezone), and in
power mode the abandon-rate limit protects customers from silent calls.

## Outside calls (one trunk for everything)

Every outgoing call uses the trunk chosen by a platform admin and stored in
the database (`platform_settings.outbound_trunk`): campaign calls and numbers
dialled by hand in the softphone.

1. **Platform admin.** Grant the right from the server:

    ```bash
    php artisan platform:admin you@example.com            # --revoke to remove it
    ```

    A **Plateforme** entry then appears in the sidebar for that user.

2. **Dialplan.** Write the shared `cc-outbound` context and every team's
   `_00X.` route, then declare the context:

    ```bash
    php artisan migrate
    php artisan asterisk:campaign-dialplan
    php artisan asterisk:contexts-config > /etc/asterisk/extensions_tenants.conf   # now includes [cc-outbound]
    asterisk -rx "dialplan reload"
    ```

3. **Choose the trunk** in Administration → Plateforme. Saving rewrites the
   `cc-outbound` row; the next call (campaign or manual) uses it. No restart.

How a manual call goes out: the softphone sends an outside number as
`00` + international number (`76 12 34 56` → `0022376123456`). The team
context matches `_00X.`, stamps its accountcode and main number as caller ID,
then `Goto(cc-outbound,${EXTEN},1)` dials `PJSIP/{dial_prefix}{international}@{trunk}`.
Extensions may not start with `00`.

**Metering.** Outside calls are read from the CDR: the carrier leg of each
answered call (manual calls, campaign preview, progressive and power), from
first answer to last hang-up. They are billed per started minute at the
price in force when the call was answered: 100 F/min to start, changed in
Administration → Plateforme (each change is kept with its date, so past calls
keep their price). A plan's `outbound_minutes` are free each month. Teams see
their usage in Settings → Facturation; platform admins see every team's on the
Plateforme page. Nothing is charged automatically yet.

## Asterisk tables (platform admins)

**Plateforme → Tables Asterisk** browses and edits, straight in the realtime
database: `ps_endpoints`, `ps_aors`, `ps_auths`, `ps_endpoint_id_ips`,
`ps_registrations`, `ps_transports` (add a trunk = one row in each of
endpoints / aors / identify, plus auths and registrations if the carrier
needs them), `queue_members`, `queue_rules`, `musiconhold`,
`musiconhold_entry`, `voicemail`, `voicemail_messages` and the logs `cdr`,
`cel`, `queue_log`. Columns are read from the database, so the editor follows
your Asterisk version's schema; only tables that exist are listed. Binary
columns (voicemail recordings) are download-only. Every change is written to
the Laravel log (`[asterisk-table]`, with the admin's email). Editing the logs
changes call history and outside-call billing: the page asks for confirmation.

## Voicemail and hold music (teams)

Teams manage both in Settings → **Messagerie vocale** and **Musique d'attente**.

1. **extconfig.conf** (realtime mappings, next to the ps_* ones):

    ```ini
    voicemail => odbc,asterisk,voicemail
    musiconhold => odbc,asterisk,musiconhold
    musiconhold_entry => odbc,asterisk,musiconhold_entry
    ```

2. **voicemail.conf**: store messages in the database, so the app can list
   and play them:

    ```ini
    [general]
    format=wav
    odbcstorage=asterisk
    odbctable=voicemail_messages
    ```

    Each team's boxes live in the voicemail context named after its key
    (`{key}`), mailbox = extension. Unanswered, busy and offline calls go to
    the box when it exists (`VM_INFO`); agents dial **\*97** to listen.
    Without `odbcstorage` the boxes still work; the app just can't list messages.

3. **Hold music** is played from the app over HTTP: load
   `res_http_media_cache` (`module load res_http_media_cache.so`), and make
   `ASTERISK_MOH_BASE_URL` (default `APP_URL`) reachable from the Asterisk
   server. A team's files form the playlist class `moh_{key}`: its queues
   use it (the first upload switches the queues still on "default") and its
   softphones suggest it on hold (`moh_suggest`). Install **ffmpeg** on the
   app server to accept MP3/OGG/…; without it only 8 kHz mono 16-bit WAV is
   accepted.

4. **Apply**: `php artisan migrate`, restart the AMI listener (it now runs
   `moh reload` after a change), then rewrite every team's dial rule so
   voicemail and \*97 are in place:

    ```bash
    php artisan asterisk:busy-agents
    ```

## Number pool (DIDs of the SIP trunk)

Teams do not type their number in: load the DIDs you received from the
provider into the pool, and each team gets one automatically when it creates
its first queue (routed to that queue). An admin can also click **Obtenir un
numéro** on *Numéros et SVI*, or replace the platform number by the
company's own number (ported or forwarded to the trunk) with the same
routing; the platform number then goes back to the pool. Deleting a team also
returns its numbers.

```bash
php artisan numbers:pool add 22320201000-22320201099 --note="Orange lot 1"
php artisan numbers:pool import storage/app/dids.csv   # one number per line / first column
php artisan numbers:pool list --free
php artisan numbers:pool assign <team-slug>             # give one now
php artisan numbers:pool remove 22320201099             # only when free
```

Store numbers exactly as the provider sends them in the request URI (that is
the `exten` matched in `cc-inbound`). `ASTERISK_NUMBERS_AUTO_ASSIGN=false`
turns the automatic assignment off (the button and the command still work).
Campaigns with no *Numéro présenté* show the team's main number.

## Inbound routing (numbers, IVR, queues)

Flow of an inbound call:

```text
provider trunk → cc-inbound/{DID} → t_{key}: IVR menu (ivr-{id}) or queue entry
queue entry: accountcode → screen-pop event → [emergency closure] → [closure days]
             → [opening hours] → Queue (max wait, announcements)
             → agent: survey (1-5) after the agent hangs up
             → no agent in time / full / empty: "press 1 to be called back" → overflow
closed:      closed message → [callback offer] → hang up
```

1. **Trunk.** Set `context=cc-inbound` on your provider's PJSIP endpoint, then
   declare the context (included in `php artisan asterisk:contexts-config`) and
   reload. Numbers are matched exactly as the provider sends them (check
   `pjsip set logger on`): enter them that way under Settings → Numbers & IVR.
2. **Sounds.** Record these in French in the Asterisk sounds directory (names can
   be changed in `.env`):

    | Purpose                      | Default name                   |
    | ---------------------------- | ------------------------------ |
    | "Press 1 to be called back"  | `callcenter-callback-offer`    |
    | "We will call you back"      | `callcenter-callback-confirm`  |
    | "Rate this call from 1 to 5" | `callcenter-survey`            |
    | "Thank you"                  | `callcenter-survey-thanks`     |
    | Closed message               | `callcenter-closed` (existing) |

    IVR greetings, overflow/emergency messages and queue announcements use the
    sound names you type in the settings pages.

3. **AMI.** The listener reads `AgentCalled` (screen pop) and the `CcInbound`,
   `CcCallback`, `CcSurvey` user events: keep `read = system,call,agent,user`.
4. **Closure days** are written as day/month checks for the coming year;
   `asterisk:sync-queues` runs daily (scheduler) to keep them exact.

Callback requests become contacts of the queue's callback list (a preview
list in Campaigns, kind "Rappels entrants"): the queue's agents call them back
from the agent workspace, on every plan.

### Where things are configured

| Page (Settings) | What it covers |
| --- | --- |
| Files d'attente | Per queue: settings, agents (primary / backup / last resort), opening hours, closure days, emergency closure, wait limit and overflow, callback offer, announcements, call recording, survey |
| Numéros et SVI | Inbound numbers and voice menus |
| Plan d'appel | Read-only view of what happens to a call, number by number. "Mode expert" lists the raw dialplan rows: only custom rows can be edited; rows the app writes (internal calls, queues, menus) are locked |
| Postes SIP | Extensions and their PJSIP endpoints |

## Call recording (Pro and Entreprise)

Turned on per queue (Settings → Files d'attente → "Enregistrement et
enquête"). Campaign calls are recorded when their campaign queue records.
Recordings appear in the call history (play / download), kept for the plan's
`recording_days` (Pro 90, Entreprise 365), then deleted by `recordings:prune`
(daily, scheduler). Supervisors and up hear every recording; agents only the
calls they took part in.

```text
queue entry → Gosub(cc-record,s,1(notice)) → [notice] → MixMonitor(…,b,upload) → Queue
call ends   → cc-recording-upload <account>__<uniqueid>__<linkedid>.wav
            → POST /webhooks/asterisk/recordings (Bearer token) → file deleted on Asterisk
```

1. **App `.env`:** `ASTERISK_RECORDING_TOKEN` (long random string,
   `openssl rand -hex 32`), `ASTERISK_RECORDING_DISK` (`local`, or `s3`…).
   Run `php artisan asterisk:campaign-dialplan` once: it also writes the
   `cc-record` context. Add its stanza to extensions.conf
   (`php artisan asterisk:contexts-config` includes it) and `dialplan reload`.
2. **Asterisk server:**

    ```bash
    sudo install -m 755 deploy/asterisk/cc-recording-upload /usr/local/bin/
    sudo install -d -o asterisk -g asterisk /var/spool/asterisk/monitor/callcenter
    sudo install -d /etc/callcenter
    sudo tee /etc/callcenter/recording.env >/dev/null <<'ENV'
    CC_APP_URL=https://app.example.com
    CC_RECORDING_TOKEN=<ASTERISK_RECORDING_TOKEN>
    CC_RECORDING_DIR=/var/spool/asterisk/monitor/callcenter
    ENV
    sudo chown root:asterisk /etc/callcenter/recording.env && sudo chmod 640 /etc/callcenter/recording.env
    # Retry uploads that failed (app down, network):
    echo '*/5 * * * * asterisk /usr/local/bin/cc-recording-upload' | sudo tee /etc/cron.d/cc-recording
    ```

    The script needs `curl`. MixMonitor runs it as the Asterisk user when the
    recording ends.
3. **Sound:** record `callcenter-recording-notice` ("Cet appel peut être
   enregistré pour des raisons de qualité"). The notice is on by default for
   each recording queue; informing callers is generally a legal requirement.
4. **Size:** 8 kHz WAV is about 1 MB per minute. Plan disk space (or use S3)
   accordingly; `ASTERISK_RECORDING_MAX_KB` caps a single upload (default
   200 MB). Raise PHP's `upload_max_filesize` / `post_max_size` and nginx's
   `client_max_body_size` for the webhook route to match.

A team whose plan loses call recording stops recording at the next dialplan
sync (immediately after a payment, otherwise daily); uploads for it are
acknowledged but not kept, and existing recordings stay 30 days.

