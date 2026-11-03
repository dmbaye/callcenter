<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Asterisk database connection
    |--------------------------------------------------------------------------
    |
    | Connection (see config/database.php) that holds the Asterisk realtime
    | tables: cdr, queues, queue_members, ps_endpoints, ps_auths, ps_aors,
    | extensions.
    |
    */

    'connection' => env('ASTERISK_DB_CONNECTION', 'asterisk'),

    /*
    |--------------------------------------------------------------------------
    | AMI daemon (php artisan asterisk:ami-listen)
    |--------------------------------------------------------------------------
    |
    | Web requests talk to the long-lived listener over a Unix socket. The
    | socket directory must be writable by the daemon and readable by PHP-FPM
    | (same user/group). The systemd unit in deploy/ creates /run/callcenter.
    |
    */

    'ami' => [
        'host' => env('ASTERISK_AMI_HOST', '127.0.0.1'),
        'port' => (int) env('ASTERISK_AMI_PORT', 5038),
        'username' => env('ASTERISK_AMI_USERNAME'),
        'secret' => env('ASTERISK_AMI_SECRET'),
        'monitor_context' => env('ASTERISK_MONITOR_CONTEXT', 'supervisor-monitor'),
        'monitor_extension' => env('ASTERISK_MONITOR_EXTENSION', 'spy'),
        'monitor_caller_id' => env('ASTERISK_MONITOR_CALLER_ID', 'Supervisor Monitor'),
        'originate_timeout' => (int) env('ASTERISK_AMI_ORIGINATE_TIMEOUT', 30000),
        'socket' => env('ASTERISK_AMI_SOCKET', storage_path('app/asterisk-ami.sock')),
        // Seconds a request waits for the listener (originate, spy, transfer…).
        'ipc_timeout' => (int) env('ASTERISK_AMI_IPC_TIMEOUT', 5),
        // Live snapshot published by the listener; readers use it instead of the socket.
        'snapshot_file' => env('ASTERISK_AMI_SNAPSHOT_FILE', storage_path('app/asterisk-ami-snapshot.json')),
        // Periodic refresh; also used to decide when a snapshot is stale.
        'snapshot_interval' => (int) env('ASTERISK_AMI_SNAPSHOT_INTERVAL', 2),
        // AMI event classes the daemon subscribes to. The AMI user needs `read = call,agent`
        // in manager.conf; without it the daemon still works, just at snapshot_interval latency.
        'events' => env('ASTERISK_AMI_EVENTS', 'call,agent,user'),
        // Wait this long after an event before re-reading Asterisk (coalesces event bursts).
        'debounce_ms' => (int) env('ASTERISK_AMI_DEBOUNCE_MS', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring webhook
    |--------------------------------------------------------------------------
    |
    | POST /webhooks/asterisk/monitoring {"team_key": "<asterisk_key>"} with an
    | X-Signature header (hex HMAC-SHA256 of the raw body using this secret)
    | tells connected supervisors to refresh. Empty secret = endpoint disabled.
    |
    */

    'webhook' => [
        'secret' => env('ASTERISK_WEBHOOK_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | PJSIP provisioning defaults
    |--------------------------------------------------------------------------
    |
    | Used when an extension is created without explicit values (member
    | creation, agents:assign, asterisk:provision-team). The endpoint context
    | is always the team context, t_{asterisk_key}.
    |
    */

    'provisioning' => [
        'transport' => env('ASTERISK_PJSIP_TRANSPORT', 'transport-wss'),
        'allow' => env('ASTERISK_PJSIP_ALLOW', 'opus,ulaw,alaw'),
        'max_contacts' => (int) env('ASTERISK_PJSIP_MAX_CONTACTS', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | SIP / WebRTC
    |--------------------------------------------------------------------------
    |
    | domain: SIP domain / PBX host used in sip: URIs.
    | ws_url: full WebSocket URL of Asterisk's HTTP server, e.g.
    |         wss://pbx.example.com:8089/ws (must be wss:// when the app is
    |         served over HTTPS).
    | stun_url / turn_*: ICE servers handed to the browser softphone.
    |
    */

    'sip' => [
        'domain' => env('ASTERISK_SIP_DOMAIN'),
        'ws_url' => env('ASTERISK_SIP_WS_URL'),
        'stun_url' => env('ASTERISK_STUN_URL'),
        'turn_url' => env('ASTERISK_TURN_URL'),
        'turn_username' => env('ASTERISK_TURN_USERNAME'),
        'turn_credential' => env('ASTERISK_TURN_CREDENTIAL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Agent presence
    |--------------------------------------------------------------------------
    |
    | The softphone joins the team queues once registered and sends a
    | heartbeat every heartbeat_interval seconds. agents:prune-presence
    | (scheduled every minute) removes agents silent for stale_after seconds.
    |
    */

    'presence' => [
        'heartbeat_interval' => (int) env('ASTERISK_PRESENCE_HEARTBEAT', 60),
        'stale_after' => (int) env('ASTERISK_PRESENCE_STALE_AFTER', 180),
    ],

    /*
    |--------------------------------------------------------------------------
    | Realtime dialplan (extensions table)
    |--------------------------------------------------------------------------
    |
    | Each team owns the context t_{asterisk_key}. Each queue has an exact
    | extension named after its Asterisk name that sets the CDR accountcode,
    | applies the opening hours (closed_sound is played outside them) and
    | enters the queue. Route inbound DIDs with
    | Goto(t_{asterisk_key},{queue asterisk_name},1); transfers use the same
    | entry. realtime_family is the extconfig.conf family of the extensions
    | table, used by asterisk:contexts-config.
    |
    */

    'dialplan' => [
        'closed_sound' => env('ASTERISK_CLOSED_SOUND', 'callcenter-closed'),
        'dial_timeout' => (int) env('ASTERISK_DIAL_TIMEOUT', 30),
        'realtime_family' => env('ASTERISK_REALTIME_EXTENSIONS_FAMILY', 'extensions'),
    ],


    /*
    |--------------------------------------------------------------------------
    | Outbound campaigns
    |--------------------------------------------------------------------------
    |
    | The dialer daemon (php artisan campaigns:dial) asks the AMI daemon to
    | originate calls into the shared realtime context `context`
    | (php artisan asterisk:campaign-dialplan writes it). Customer calls go out
    | through `{trunk}`: the platform trunk (see "Outside calls" above),
    | shared by every campaign of every team. Numbers are stored as international digits: a national
    | number of `national_length` digits gets `country_code` prepended.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Outside calls
    |--------------------------------------------------------------------------
    |
    | Every outgoing call (campaigns and manual calls from the softphone)
    | goes through one carrier trunk, chosen by a platform admin
    | (Administration → Plateforme) and stored in the database.
    | ASTERISK_CAMPAIGN_TRUNK is only the fallback until one is chosen.
    | Manual calls enter the shared realtime context `context`.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Hold music of the teams
    |--------------------------------------------------------------------------
    |
    | Files uploaded by a team are kept on `disk` and played by Asterisk in
    | playlist mode from a signed URL of the app (`base_url`, reachable from
    | the Asterisk server; needs res_http_media_cache). Uploads other than an
    | 8 kHz mono 16-bit WAV are converted with `ffmpeg` when it is installed.
    |
    */

    'moh' => [
        'disk' => env('ASTERISK_MOH_DISK', 'local'),
        'base_url' => env('ASTERISK_MOH_BASE_URL', env('APP_URL')),
        'ffmpeg' => env('ASTERISK_MOH_FFMPEG', 'ffmpeg'),
        'max_tracks' => (int) env('ASTERISK_MOH_MAX_TRACKS', 10),
        // Largest upload, in kilobytes.
        'max_upload_kb' => (int) env('ASTERISK_MOH_MAX_KB', 20480),
    ],

    'outbound' => [
        'context' => env('ASTERISK_OUTBOUND_CONTEXT', 'cc-outbound'),
        'dial_timeout' => (int) env('ASTERISK_OUTBOUND_DIAL_TIMEOUT', 60),
    ],

    'campaigns' => [
        'context' => env('ASTERISK_CAMPAIGN_CONTEXT', 'cc-campaign'),
        'trunk' => env('ASTERISK_CAMPAIGN_TRUNK'),
        'dial_prefix' => env('ASTERISK_CAMPAIGN_DIAL_PREFIX', '00'),
        'country_code' => env('ASTERISK_CAMPAIGN_COUNTRY_CODE', '223'),
        'national_length' => (int) env('ASTERISK_CAMPAIGN_NATIONAL_LENGTH', 8),
        'tick_seconds' => (int) env('ASTERISK_CAMPAIGN_TICK', 2),
        'max_calls_per_tick' => (int) env('ASTERISK_CAMPAIGN_MAX_CALLS_PER_TICK', 20),
        // Preview reservations and unanswered wrap-ups are released after these.
        'reservation_minutes' => 15,
        'wrapup_auto_close_minutes' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Number pool
    |--------------------------------------------------------------------------
    |
    | DIDs of the SIP trunk (php artisan numbers:pool add …). With
    | auto_assign, a team gets one when it creates its first queue; it can
    | still replace it with its own number on the Numbers page.
    |
    */

    'numbers' => [
        'auto_assign' => (bool) env('ASTERISK_NUMBERS_AUTO_ASSIGN', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Inbound routing
    |--------------------------------------------------------------------------
    |
    | DIDs land in the shared realtime context `context` (set it as the
    | context of your provider trunk endpoint). Sound names are Asterisk
    | sound files; record them in French in your sounds directory.
    |
    */

    'inbound' => [
        'context' => env('ASTERISK_INBOUND_CONTEXT', 'cc-inbound'),
        'sounds' => [
            'callback_offer' => env('ASTERISK_SOUND_CALLBACK_OFFER', 'callcenter-callback-offer'),
            'callback_confirm' => env('ASTERISK_SOUND_CALLBACK_CONFIRM', 'callcenter-callback-confirm'),
            'survey' => env('ASTERISK_SOUND_SURVEY', 'callcenter-survey'),
            'survey_thanks' => env('ASTERISK_SOUND_SURVEY_THANKS', 'callcenter-survey-thanks'),
            // "Cet appel peut être enregistré…", played before recording starts.
            'recording_notice' => env('ASTERISK_SOUND_RECORDING_NOTICE', 'callcenter-recording-notice'),
        ],
        // Closures are written as day/month checks: only the coming year is kept.
        'closure_horizon_days' => 365,
    ],

    /*
    |--------------------------------------------------------------------------
    | Call recording
    |--------------------------------------------------------------------------
    |
    | Queues with recording on (Pro and Entreprise plans) start MixMonitor
    | through the shared cc-record context. When a recording ends, Asterisk
    | runs `command` (deploy/asterisk/cc-recording-upload), which posts the
    | file to /webhooks/asterisk/recordings with `token` and deletes it. The
    | file name carries the account, uniqueid and linkedid of the call.
    |
    | Recordings are kept on `disk` for the plan's recording_days, then
    | deleted by recordings:prune.
    |
    */

    'recordings' => [
        'context' => env('ASTERISK_RECORDING_CONTEXT', 'cc-record'),
        'directory' => env('ASTERISK_RECORDING_DIR', '/var/spool/asterisk/monitor/callcenter'),
        'command' => env('ASTERISK_RECORDING_COMMAND', '/usr/local/bin/cc-recording-upload'),
        'token' => env('ASTERISK_RECORDING_TOKEN'),
        'disk' => env('ASTERISK_RECORDING_DISK', 'local'),
        // Largest accepted upload, in kilobytes (8 kHz WAV ≈ 1 MB per minute).
        'max_upload_kb' => (int) env('ASTERISK_RECORDING_MAX_KB', 204800),
        // Kept this long when the team's plan has no call recording (after a downgrade).
        'fallback_days' => 30,
    ],
];
