<?php

use App\Enums\TeamRole;
use App\Models\CallRecording;
use App\Models\Team;
use App\Services\AsteriskDialplan;
use App\Services\CallRecordings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    createAsteriskTables();
    Storage::fake('local');
    config(['asterisk.recordings.token' => 'rec-token', 'asterisk.recordings.disk' => 'local']);

    // New teams start on the Pro trial, which includes call recording.
    $this->team = Team::factory()->create();
    $this->key = $this->team->asterisk_key;
    $this->support = teamQueue($this->team, 'Support');
    $this->dialplan = app(AsteriskDialplan::class);
});

/** A small 8 kHz, 16-bit mono WAV of the given length. */
function fakeWav(int $seconds = 3): UploadedFile
{
    $data = str_repeat("\0\0", 8000 * $seconds);
    $header = 'RIFF'.pack('V', 36 + strlen($data)).'WAVE'
        .'fmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16)
        .'data'.pack('V', strlen($data));

    return UploadedFile::fake()->createWithContent('call.wav', $header.$data);
}

function uploadRecording(array $fields, ?string $token = 'rec-token'): TestResponse
{
    return test()->withHeaders($token ? ['Authorization' => 'Bearer '.$token] : [])
        ->post('/webhooks/asterisk/recordings', $fields);
}

function onEssentiel(Team $team): void
{
    $team->subscription->update(['plan' => 'essentiel', 'on_trial' => false, 'ends_at' => now()->addMonth()]);
    $team->unsetRelation('subscription');
}

it('records a queue through cc-record, with the caller notice, when the plan allows it', function () {
    $this->support->update(['record_calls' => true]);

    $rows = $this->dialplan->syncQueue($this->support->fresh());
    $apps = array_column($rows, 'app');
    $gosub = array_search('Gosub', $apps, true);

    expect($gosub)->not->toBeFalse()
        ->and($rows[$gosub]['appdata'])->toBe('cc-record,s,1('.config('asterisk.inbound.sounds.recording_notice').')')
        ->and($rows[$gosub + 1]['app'])->toBe('Queue');

    // The shared subroutine was written on first use.
    $record = DB::table('extensions')->where('context', 'cc-record')->orderBy('priority')->get();
    expect($record)->toHaveCount(4)
        ->and($record[2]->app)->toBe('MixMonitor')
        ->and($record[2]->appdata)->toContain('${CHANNEL(accountcode)}__${UNIQUEID}__${CHANNEL(linkedid)}.wav,b,')
        ->and($record[2]->appdata)->toContain(config('asterisk.recordings.command'));
});

it('records without the notice when it is switched off', function () {
    $this->support->update(['record_calls' => true, 'recording_notice' => false]);

    $rows = $this->dialplan->syncQueue($this->support->fresh());

    expect(collect($rows)->firstWhere('app', 'Gosub')['appdata'])->toBe('cc-record,s,1()');
});

it('does not record on a plan without call recording', function () {
    onEssentiel($this->team);
    $this->support->update(['record_calls' => true]);

    $rows = $this->dialplan->syncQueue($this->support->fresh());

    expect(array_column($rows, 'app'))->not->toContain('Gosub');
});

it('keeps every campaign jump on an existing priority', function () {
    $rows = collect($this->dialplan->campaignRows())->groupBy('exten');

    foreach ($rows as $exten => $extenRows) {
        $priorities = $extenRows->pluck('priority')->all();

        foreach ($extenRows->where('app', 'GotoIf') as $row) {
            if (preg_match('/\?(\d+)$/', $row['appdata'], $m)) {
                expect($priorities)->toContain((int) $m[1]);
            }
        }
    }

    expect($rows['s']->firstWhere('app', 'GosubIf')['appdata'])->toContain('cc-record,s,1(${CC_REC_NOTICE})')
        ->and($rows['preview']->firstWhere('app', 'GosubIf')['priority'])
        ->toBeLessThan($rows['preview']->firstWhere('app', 'Dial')['priority']);
});

it('sends the answered customer of a preview call to the answered hook, not the progressive flow', function () {
    $rows = collect($this->dialplan->campaignRows());

    // Dial U(context^args) always starts at s,1 of the context.
    expect($rows->firstWhere('app', 'Dial')['appdata'])->toEndWith('U(cc-campaign^answered)')
        ->and($rows->first(fn ($row) => $row['exten'] === 's' && $row['priority'] === 1)['appdata'])
        ->toBe('$["${ARG1}" = "answered"]?answered,1')
        ->and($rows->first(fn ($row) => $row['exten'] === 'answered' && $row['priority'] === 2)['app'])->toBe('Return');
});

it('stores an uploaded recording with its duration', function () {
    uploadRecording([
        'account' => $this->key,
        'uniqueid' => '1730120000.42',
        'linkedid' => '1730119990.40',
        'file' => fakeWav(3),
    ])->assertCreated();

    $recording = CallRecording::sole();

    expect($recording->team_id)->toBe($this->team->id)
        ->and($recording->linkedid)->toBe('1730119990.40')
        ->and($recording->duration)->toBe(3);
    Storage::disk('local')->assertExists($recording->path);
});

it('rejects uploads without the right token, and is off without one', function () {
    uploadRecording(['account' => $this->key, 'uniqueid' => '1.1', 'file' => fakeWav()], 'wrong')->assertUnauthorized();

    config(['asterisk.recordings.token' => '']);
    uploadRecording(['account' => $this->key, 'uniqueid' => '1.1', 'file' => fakeWav()])->assertStatus(503);

    expect(CallRecording::count())->toBe(0);
});

it('acknowledges but does not keep recordings of a plan without call recording', function () {
    onEssentiel($this->team);

    uploadRecording(['account' => $this->key, 'uniqueid' => '1.1', 'file' => fakeWav()])
        ->assertStatus(202)
        ->assertJson(['stored' => false]);

    expect(CallRecording::count())->toBe(0);
});

it('shows recordings in the call history and lets the agent of the call listen', function () {
    $agent = teamAgent($this->team, '7001');
    $other = teamAgent($this->team, '7002');
    $supervisor = teamAgent($this->team, '7009', TeamRole::Supervisor);

    DB::table('cdr')->insert([
        'uniqueid' => '1730120000.42', 'linkedid' => '1730120000.42', 'src' => '76123456',
        'channel' => 'PJSIP/orange-0001', 'dstchannel' => "PJSIP/{$this->key}_7001-0002",
        'start' => now()->subMinutes(5), 'disposition' => 'ANSWERED', 'billsec' => 60, 'duration' => 70,
        'accountcode' => $this->key,
    ]);

    $recording = app(CallRecordings::class)->store($this->team, '1730120000.42', '1730120000.42', fakeWav(2));
    $url = route('calls.recordings.show', [$this->team, $recording]);

    $this->actingAs($supervisor)->get('/'.$this->team->slug.'/calls')
        ->assertInertia(fn (Assert $page) => $page
            ->where('calls.data.0.recording.url', $url)
            ->where('calls.data.0.recording.duration', 2));

    $this->actingAs($supervisor)->get($url)->assertOk();
    $this->actingAs($agent)->get($url)->assertOk();
    $this->actingAs($other)->get($url)->assertNotFound();
});

it('does not serve another team\'s recording', function () {
    $foreign = Team::factory()->create();
    $recording = app(CallRecordings::class)->store($foreign, '9.9', null, fakeWav());
    $supervisor = teamAgent($this->team, '7009', TeamRole::Supervisor);

    $this->actingAs($supervisor)->get(route('calls.recordings.show', [$this->team, $recording]))->assertNotFound();
});

it('deletes recordings older than the plan retention', function () {
    $old = app(CallRecordings::class)->store($this->team, '1.1', null, fakeWav());
    $old->forceFill(['created_at' => now()->subDays(91)])->save();
    $recent = app(CallRecordings::class)->store($this->team, '2.2', null, fakeWav());

    $this->artisan('recordings:prune')->assertSuccessful();

    expect(CallRecording::pluck('id')->all())->toBe([$recent->id]);
    Storage::disk('local')->assertMissing($old->path);
});

it('refuses to switch recording on for a plan without it', function () {
    onEssentiel($this->team);
    $admin = teamAgent($this->team, '7009', TeamRole::Admin);

    $this->actingAs($admin)
        ->put('/settings/asterisk/queues', [
            'id' => $this->support->id,
            'name' => $this->support->name,
            'record_calls' => true,
        ])
        ->assertSessionHasErrors('record_calls');

    expect($this->support->fresh()->record_calls)->toBeFalse();
});
