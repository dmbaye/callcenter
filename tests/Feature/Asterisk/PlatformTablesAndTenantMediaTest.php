<?php

use App\Models\Team;
use App\Models\User;
use App\Services\AsteriskTableEditor;
use App\Services\TeamHoldMusic;
use App\Services\TeamVoicemail;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    createAsteriskTables();
    createAsteriskProvisioningTables();

    Schema::create('ps_transports', function (Blueprint $table) {
        $table->string('id', 40)->primary();
        $table->string('protocol')->nullable();
        $table->string('bind')->nullable();
    });
    Schema::create('voicemail', function (Blueprint $table) {
        $table->increments('uniqueid');
        $table->string('context', 80);
        $table->string('mailbox', 80);
        $table->string('password', 80);
        $table->string('fullname')->nullable();
        $table->string('email')->nullable();
        $table->string('attach')->nullable();
    });
    Schema::create('voicemail_messages', function (Blueprint $table) {
        $table->string('dir');
        $table->integer('msgnum');
        $table->string('callerid')->nullable();
        $table->string('origtime')->nullable();
        $table->string('duration')->nullable();
        $table->binary('recording')->nullable();
        $table->primary(['dir', 'msgnum']);
    });
    Schema::create('musiconhold', function (Blueprint $table) {
        $table->string('name', 80)->primary();
        $table->string('mode')->nullable();
        $table->string('sort')->nullable();
    });
    Schema::create('musiconhold_entry', function (Blueprint $table) {
        $table->string('name', 80);
        $table->integer('position');
        $table->string('entry', 1024);
        $table->primary(['name', 'position']);
    });

    $this->admin = User::factory()->create(['is_platform_admin' => true]);
});

/* --- Platform table editor ---------------------------------------------- */

it('lets only platform admins open the Asterisk tables', function () {
    $this->actingAs(User::factory()->create())->get(route('admin.asterisk-tables.index'))->assertForbidden();
    $this->actingAs($this->admin)->get(route('admin.asterisk-tables.index', ['table' => 'ps_transports']))->assertOk();
});

it('adds, edits and deletes a transport row', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.asterisk-tables.store', 'ps_transports'), ['values' => ['id' => 'transport-wss', 'protocol' => 'wss', 'bind' => '0.0.0.0']])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->admin)
        ->put(route('admin.asterisk-tables.update', 'ps_transports'), [
            'row' => ['id' => 'transport-wss'],
            'values' => ['id' => 'transport-wss', 'protocol' => 'wss', 'bind' => '0.0.0.0:8089'],
        ])
        ->assertSessionHasNoErrors();

    expect(DB::table('ps_transports')->where('id', 'transport-wss')->value('bind'))->toBe('0.0.0.0:8089');

    $this->actingAs($this->admin)
        ->delete(route('admin.asterisk-tables.destroy', 'ps_transports'), ['row' => ['id' => 'transport-wss']])
        ->assertSessionHasNoErrors();

    expect(DB::table('ps_transports')->count())->toBe(0);
});

it('identifies rows of a keyless log table by all their values', function () {
    DB::table('cdr')->insert([
        ['uniqueid' => 'a', 'src' => '7001', 'disposition' => 'ANSWERED', 'start' => '2026-11-01 10:00:00'],
        ['uniqueid' => 'b', 'src' => '7002', 'disposition' => 'ANSWERED', 'start' => '2026-11-01 11:00:00'],
    ]);

    $row = collect(app(AsteriskTableEditor::class)->rows('cdr', '7002')->items())->sole();

    $this->actingAs($this->admin)
        ->delete(route('admin.asterisk-tables.destroy', 'cdr'), ['row' => $row])
        ->assertSessionHasNoErrors();

    expect(DB::table('cdr')->pluck('uniqueid')->all())->toBe(['a']);
});

it('refuses tables outside the list', function () {
    $this->actingAs($this->admin)
        ->post('/admin/asterisk-tables/users', ['values' => ['name' => 'x']])
        ->assertNotFound();
});

/* --- Tenant voicemail --------------------------------------------------- */

it('creates a voicemail box per extension in the team context', function () {
    $team = Team::factory()->create();

    app(TeamVoicemail::class)->saveBox($team, '7001', ['pin' => '1234', 'email' => 'a@b.ml']);

    expect(DB::table('voicemail')->where('context', $team->asterisk_key)->where('mailbox', '7001')->first())
        ->password->toBe('1234')
        ->attach->toBe('yes');
});

it('shows and streams only the team’s own messages', function () {
    $team = Team::factory()->create();
    $other = Team::factory()->create();

    DB::table('voicemail_messages')->insert([
        ['dir' => '/var/spool/asterisk/voicemail/'.$team->asterisk_key.'/7001/INBOX', 'msgnum' => 0, 'callerid' => '"Awa" <22376000001>', 'origtime' => '1793530000', 'duration' => '12', 'recording' => 'RIFFdata'],
        ['dir' => '/var/spool/asterisk/voicemail/'.$other->asterisk_key.'/7001/INBOX', 'msgnum' => 0, 'callerid' => 'x', 'origtime' => '1793530000', 'duration' => '5', 'recording' => 'RIFFother'],
    ]);

    $voicemail = app(TeamVoicemail::class);
    $messages = $voicemail->messages($team);

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['mailbox'])->toBe('7001')
        ->and($messages[0]['folder'])->toBe('INBOX')
        ->and($voicemail->recording($team, $messages[0]['id']))->toBe('RIFFdata');

    $foreign = base64_encode('/var/spool/asterisk/voicemail/'.$other->asterisk_key.'/7001/INBOX|0');

    expect(fn () => $voicemail->recording($team, $foreign))->toThrow(Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
});

/* --- Tenant hold music -------------------------------------------------- */

it('turns an uploaded WAV into a playlist class used by the team', function () {
    Storage::fake('local');
    $team = Team::factory()->create();
    DB::table('ps_endpoints')->insert(['id' => $team->asterisk_key.'_7002']);

    // 1 s of silence, 8 kHz mono 16-bit PCM.
    $pcm = str_repeat("\0", 16000);
    $wav = 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16).'data'.pack('V', strlen($pcm)).$pcm;
    $file = UploadedFile::fake()->createWithContent('Jingle accueil.wav', $wav);

    $track = app(TeamHoldMusic::class)->add($team, $file, null);
    $class = 'moh_'.$team->asterisk_key;

    expect($track->duration)->toBe(1)
        ->and(DB::table('musiconhold')->where('name', $class)->value('mode'))->toBe('playlist')
        ->and(DB::table('musiconhold_entry')->where('name', $class)->value('entry'))
        ->toEndWith('/webhooks/asterisk/moh/'.$track->id.'/'.$track->signature().'.wav');

    // Asterisk fetches it with the signature; anything else is refused.
    $this->get('/webhooks/asterisk/moh/'.$track->id.'/'.$track->signature().'.wav')->assertOk();
    $this->get('/webhooks/asterisk/moh/'.$track->id.'/'.str_repeat('a', 40).'.wav')->assertNotFound();

    app(TeamHoldMusic::class)->remove($track);

    expect(DB::table('musiconhold')->where('name', $class)->exists())->toBeFalse();
});

it('refuses another team’s hold music on a queue', function () {
    $team = Team::factory()->create();
    $owner = User::factory()->create();
    $team->members()->attach($owner, ['role' => App\Enums\TeamRole::Owner->value]);
    $owner->switchTeam($team);

    $this->actingAs($owner)
        ->post('/settings/asterisk/queues', ['name' => 'Support', 'musiconhold' => 'moh_zzzzzzzzzz'])
        ->assertSessionHasErrors('musiconhold');
});
