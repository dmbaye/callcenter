<?php

use App\Services\AsteriskAmi;

beforeEach(function () {
    $this->file = sys_get_temp_dir().'/ami-snapshot-'.uniqid().'.json';
    config([
        'asterisk.ami.snapshot_file' => $this->file,
        'asterisk.ami.socket' => sys_get_temp_dir().'/missing-'.uniqid().'.sock',
    ]);
});

afterEach(fn () => @unlink($this->file));

it('reads the snapshot published by the listener without the socket', function () {
    file_put_contents($this->file, json_encode([
        'channels' => [],
        'queues' => ['entries' => [], 'queues' => [], 'members' => [['Queue' => 'q1']], 'updated_at' => time()],
    ]));

    expect((new AsteriskAmi)->snapshot()['queues']['members'][0]['Queue'])->toBe('q1');
});

it('ignores a stale snapshot file and asks the listener', function () {
    file_put_contents($this->file, json_encode(['channels' => [], 'queues' => []]));
    touch($this->file, time() - 60);

    (new AsteriskAmi)->snapshot();
})->throws(RuntimeException::class, 'Asterisk AMI listener is unavailable');
