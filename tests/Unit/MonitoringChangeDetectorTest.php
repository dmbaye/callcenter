<?php

use App\Services\MonitoringChangeDetector;

function monitoringSnapshot(array $overrides = []): array
{
    return array_replace_recursive([
        'channels' => [
            ['Channel' => 'PJSIP/abcdefghij_1001-00000001', 'ChannelStateDesc' => 'Up', 'CallerIDNum' => '5551234', 'BridgeId' => 'b1', 'Duration' => '00:00:10'],
        ],
        'queues' => [
            'entries' => [['Queue' => 'abcdefghij_support', 'Position' => '1', 'CallerIDNum' => '5559999', 'Wait' => '4']],
            'queues' => ['abcdefghij_support' => ['Calls' => '1', 'Completed' => '3', 'Abandoned' => '0', 'Holdtime' => '12']],
            'members' => [['Queue' => 'abcdefghij_support', 'StateInterface' => 'PJSIP/abcdefghij_1001', 'Paused' => '0', 'Status' => '1', 'InCall' => '1']],
        ],
    ], $overrides);
}

it('reports every tenant on the first snapshot', function () {
    expect((new MonitoringChangeDetector)->changedKeys(monitoringSnapshot()))->toBe(['abcdefghij']);
});

it('ignores volatile counters such as call duration and queue wait', function () {
    $detector = new MonitoringChangeDetector;
    $detector->changedKeys(monitoringSnapshot());

    $later = monitoringSnapshot();
    $later['channels'][0]['Duration'] = '00:05:00';
    $later['queues']['entries'][0]['Wait'] = '300';
    $later['queues']['queues']['abcdefghij_support']['Holdtime'] = '99';

    expect($detector->changedKeys($later))->toBe([]);
});

it('detects a new caller waiting in the tenant queue', function () {
    $detector = new MonitoringChangeDetector;
    $detector->changedKeys(monitoringSnapshot());

    $next = monitoringSnapshot();
    $next['queues']['entries'][] = ['Queue' => 'abcdefghij_support', 'Position' => '2', 'CallerIDNum' => '5558888', 'Wait' => '0'];

    expect($detector->changedKeys($next))->toBe(['abcdefghij']);
});

it('detects an agent being paused', function () {
    $detector = new MonitoringChangeDetector;
    $detector->changedKeys(monitoringSnapshot());

    $next = monitoringSnapshot();
    $next['queues']['members'][0]['Paused'] = '1';

    expect($detector->changedKeys($next))->toBe(['abcdefghij']);
});

it('only reports the tenant that changed', function () {
    $detector = new MonitoringChangeDetector;
    $base = monitoringSnapshot();
    $base['channels'][] = ['Channel' => 'PJSIP/klmnopqrst_2001-00000002', 'ChannelStateDesc' => 'Up', 'CallerIDNum' => '1', 'BridgeId' => 'b2'];
    $detector->changedKeys($base);

    $next = $base;
    $next['channels'][1]['ChannelStateDesc'] = 'Ringing';

    expect($detector->changedKeys($next))->toBe(['klmnopqrst']);
});

it('reports a tenant whose last call just ended', function () {
    $detector = new MonitoringChangeDetector;
    $detector->changedKeys(monitoringSnapshot());

    $empty = ['channels' => [], 'queues' => ['entries' => [], 'queues' => [], 'members' => []]];

    expect($detector->changedKeys($empty))->toBe(['abcdefghij']);
});
