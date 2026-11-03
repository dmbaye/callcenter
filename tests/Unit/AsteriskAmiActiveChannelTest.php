<?php

use App\Services\AsteriskAmi;

// Shapes below match what Asterisk 12+ returns for CoreShowChannels:
// there is no "BridgedChannel" field, a bridged call carries a "BridgeId".

it('finds an answered, bridged channel and its peer', function () {
    $channels = [
        ['Channel' => 'PJSIP/trunk-00000001', 'ChannelStateDesc' => 'Up', 'BridgeId' => 'bridge-1'],
        ['Channel' => 'PJSIP/7002-00000002', 'ChannelStateDesc' => 'Up', 'BridgeId' => 'bridge-1'],
    ];

    expect((new AsteriskAmi)->findActiveChannel($channels, '7002'))->toBe([
        'channel' => 'PJSIP/7002-00000002',
        'bridged_channel' => 'PJSIP/trunk-00000001',
    ]);
});

it('ignores ringing channels', function () {
    $channels = [['Channel' => 'PJSIP/7003-00000003', 'ChannelStateDesc' => 'Ringing', 'BridgeId' => '']];

    expect((new AsteriskAmi)->findActiveChannel($channels, '7003'))->toBeNull();
});

it('does not confuse extensions that share a prefix', function () {
    $channels = [['Channel' => 'PJSIP/70020-00000009', 'ChannelStateDesc' => 'Up', 'BridgeId' => 'b']];

    expect((new AsteriskAmi)->findActiveChannel($channels, '7002'))->toBeNull();
});

it('still understands the legacy BridgedChannel field', function () {
    $channels = [['Channel' => 'PJSIP/7002-00000002', 'ChannelStateDesc' => 'Up', 'BridgedChannel' => 'PJSIP/x-1']];

    expect((new AsteriskAmi)->findActiveChannel($channels, '7002')['bridged_channel'])->toBe('PJSIP/x-1');
});

it('returns null when there are no channels at all', function () {
    expect((new AsteriskAmi)->findActiveChannel([], '7002'))->toBeNull();
});
