<?php

use App\Models\Team;
use App\Services\AsteriskDialplan;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    createAsteriskTables();
    $this->travelTo(now()->setDate(2026, 11, 2)->setTime(10, 0));

    $this->team = Team::factory()->create();
    $this->key = $this->team->asterisk_key;
    $this->support = teamQueue($this->team, 'Support');
    $this->sales = teamQueue($this->team, 'Ventes');
    $this->dialplan = app(AsteriskDialplan::class);
});

/** Every jump of the extension lands on an existing priority. */
function assertJumpsResolve(array $rows): void
{
    $max = count($rows);

    foreach ($rows as $row) {
        if (in_array($row['app'], ['GotoIf', 'GotoIfTime'], true)) {
            preg_match('/\?(\d+)(?::(\d+))?$/', $row['appdata'], $m);
            expect($m)->not->toBeEmpty();
            foreach (array_filter([$m[1] ?? null, $m[2] ?? null]) as $target) {
                expect((int) $target)->toBeGreaterThan(0)->toBeLessThanOrEqual($max);
            }
        }

        if ($row['app'] === 'Goto' && ctype_digit($row['appdata'])) {
            expect((int) $row['appdata'])->toBeLessThanOrEqual($max);
        }
    }
}

function rowsByApp(array $rows, string $app): array
{
    return array_values(array_filter($rows, fn ($row) => $row['app'] === $app));
}

it('builds the full in-queue flow: wait limit, callback offer, overflow, survey, closures', function () {
    $this->support->update([
        'max_wait' => 90,
        'overflow_type' => 'queue',
        'overflow_target' => (string) $this->sales->id,
        'callback_offer' => true,
        'survey_enabled' => true,
    ]);
    $this->support->closures()->create(['date' => '2026-12-25', 'label' => 'Noël']);
    $this->support->closures()->create(['date' => '2026-10-01', 'label' => 'passé']);
    $this->support->closures()->create(['date' => '2027-12-25', 'label' => 'trop loin']);

    $rows = $this->dialplan->syncQueue($this->support->fresh());
    $name = $this->support->asterisk_name;

    assertJumpsResolve($rows);

    expect(rowsByApp($rows, 'Queue')[0]['appdata'])->toBe("{$name},Trc,,,90")
        ->and(rowsByApp($rows, 'GotoIfTime'))->toHaveCount(1)
        ->and(rowsByApp($rows, 'GotoIfTime')[0]['appdata'])->toStartWith('*,*,25,dec?')
        ->and(collect($rows)->pluck('appdata')->contains("t_{$this->key},{$this->sales->asterisk_name},1"))->toBeTrue()
        ->and(collect($rows)->contains(fn ($r) => $r['app'] === 'Read' && str_starts_with($r['appdata'], 'CC_DIGIT,')))->toBeTrue()
        ->and(collect($rows)->contains(fn ($r) => $r['app'] === 'UserEvent' && str_starts_with($r['appdata'], "CcCallback,Queue: {$name},")))->toBeTrue()
        ->and(collect($rows)->contains(fn ($r) => $r['app'] === 'UserEvent' && str_starts_with($r['appdata'], "CcSurvey,Queue: {$name},")))->toBeTrue();

    // The closure jumps to the closed message.
    $closedPriority = (int) substr(rowsByApp($rows, 'GotoIfTime')[0]['appdata'], strrpos(rowsByApp($rows, 'GotoIfTime')[0]['appdata'], '?') + 1);
    expect($rows[$closedPriority - 1]['app'])->toBe('Playback')
        ->and($rows[$closedPriority - 1]['appdata'])->toBe(config('asterisk.dialplan.closed_sound'));
});

it('plays the queue’s own closed message outside opening hours', function () {
    foreach ([0, 1] as $weekday) {
        \App\Models\QueueHour::factory()->create(['call_queue_id' => $this->support->id, 'weekday' => $weekday, 'opens_at' => '08:00:00', 'closes_at' => '17:00:00']);
    }
    $this->support->update(['closed_sound' => 'custom/mbayelab-ferme']);

    $rows = $this->dialplan->syncQueue($this->support->fresh());

    assertJumpsResolve($rows);
    // Days are an « & » list: « | » would split the GotoIfTime fields.
    expect(rowsByApp($rows, 'GotoIfTime')[0]['appdata'])->toStartWith('08:00-17:00,mon&tue,*,*?')
        ->and(collect($rows)->contains(fn ($r) => $r['app'] === 'Playback' && $r['appdata'] === 'custom/mbayelab-ferme'))->toBeTrue()
        ->and(collect($rows)->contains(fn ($r) => $r['appdata'] === config('asterisk.dialplan.closed_sound')))->toBeFalse();
});

it('closes a queue immediately in an emergency', function () {
    $this->support->update(['emergency_closed' => true, 'emergency_sound' => 'custom/urgence']);

    $rows = $this->dialplan->syncQueue($this->support->fresh());

    assertJumpsResolve($rows);
    expect($rows[2]['app'])->toBe('Goto');
    expect($rows[(int) $rows[2]['appdata'] - 1])->toMatchArray(['app' => 'Playback', 'appdata' => 'custom/urgence']);
});

it('never sends a queue to a destination of another team', function () {
    $foreign = teamQueue(Team::factory()->create(), 'Ailleurs');
    $this->support->update(['overflow_type' => 'queue', 'overflow_target' => (string) $foreign->id]);

    $rows = $this->dialplan->syncQueue($this->support->fresh());

    expect(collect($rows)->pluck('appdata')->filter(fn ($data) => str_contains($data, $foreign->asterisk_name)))->toBeEmpty()
        ->and(end($rows)['app'])->toBe('Hangup');
});

it('writes an IVR menu with options, retries and a fallback', function () {
    $menu = $this->team->ivrMenus()->create([
        'name' => 'Accueil',
        'greeting_sound' => 'custom/accueil',
        'invalid_sound' => 'custom/invalide',
        'timeout' => 5,
        'max_retries' => 2,
        'options' => [
            ['digit' => '1', 'label' => 'Ventes', 'type' => 'queue', 'target' => (string) $this->sales->id],
            ['digit' => '2', 'label' => 'Rappel', 'type' => 'callback', 'target' => (string) $this->support->id],
        ],
        'fallback_type' => 'extension',
        'fallback_target' => '7001',
    ]);

    $rows = $this->dialplan->ivrRows($menu->fresh('team'));

    assertJumpsResolve($rows);

    expect($rows[0]['exten'])->toBe('ivr-'.$menu->id)
        ->and($rows[1])->toMatchArray(['app' => 'Read', 'appdata' => 'CC_IVR,custom/accueil,1,,1,5'])
        ->and(collect($rows)->pluck('appdata')->all())->toContain(
            '$["${CC_IVR}" = "1"]?'.(collect($rows)->firstWhere('appdata', 'CC_IVR_CHOICE=Ventes')['priority']),
            "t_{$this->key},{$this->sales->asterisk_name},1",
            "t_{$this->key},7001,1",
            '$[${CC_TRY} < 2]?2',
        )
        ->and(collect($rows)->contains(fn ($r) => str_starts_with($r['appdata'], "CcCallback,Queue: {$this->support->asterisk_name},")))->toBeTrue();
});

it('routes a DID through the shared inbound context', function () {
    $menu = $this->team->ivrMenus()->create([
        'name' => 'Accueil', 'greeting_sound' => 'custom/accueil', 'timeout' => 5, 'max_retries' => 2,
        'options' => [], 'fallback_type' => 'queue', 'fallback_target' => (string) $this->support->id,
    ]);
    $number = $this->team->inboundNumbers()->create(['number' => '22320202020', 'destination_type' => 'ivr', 'destination_target' => (string) $menu->id]);

    $this->dialplan->syncInboundNumber($number->fresh('team'));

    expect(DB::table('extensions')->where('context', 'cc-inbound')->where('exten', '22320202020')->orderBy('priority')->pluck('appdata')->all())
        ->toBe(["CHANNEL(accountcode)={$this->key}", '__CC_DID=22320202020', "t_{$this->key},ivr-{$menu->id},1"]);
});
