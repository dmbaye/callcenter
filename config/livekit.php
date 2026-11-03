<?php

/*
|--------------------------------------------------------------------------
| LiveKit (self-hosted SFU for video meetings)
|--------------------------------------------------------------------------
|
| `url` is what browsers connect to (wss://, through nginx or LiveKit's own
| TLS). `api_url` is how Laravel reaches the server API (usually the local
| http port). Key and secret must match the `keys:` entry of livekit.yaml.
|
*/

return [
    'url' => env('LIVEKIT_URL'),
    'api_url' => env('LIVEKIT_API_URL', 'http://127.0.0.1:7880'),
    'api_key' => env('LIVEKIT_API_KEY'),
    'api_secret' => env('LIVEKIT_API_SECRET'),

    // Lifetime of a join token. Joining needs a fresh token; an open call is not cut.
    'token_ttl' => (int) env('LIVEKIT_TOKEN_TTL', 3600),

    // Seconds an empty room is kept before LiveKit closes it.
    'empty_timeout' => (int) env('LIVEKIT_EMPTY_TIMEOUT', 300),

    'timeout' => 5,

    // Room composite recordings (Entreprise). Needs the LiveKit Egress service;
    // the path is inside the egress container/host.
    'recording' => [
        'enabled' => (bool) env('LIVEKIT_RECORDING_ENABLED', false),
        'filepath' => env('LIVEKIT_RECORDING_PATH', '/recordings/{room_name}-{time}.mp4'),
        'layout' => env('LIVEKIT_RECORDING_LAYOUT', 'grid'),
    ],
];
