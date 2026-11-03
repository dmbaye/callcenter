<?php

use App\Models\Team;
use App\Services\AsteriskDialplan;
use App\Services\AsteriskProvisioner;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    createAsteriskTables();
    createAsteriskProvisioningTables();
});

it('writes the extension-to-extension rule when an extension is saved', function () {
    $team = Team::factory()->internalOnly()->create();

    app(AsteriskProvisioner::class)->saveExtension($team, '7001');
    app(AsteriskProvisioner::class)->saveExtension($team, '7002');

    $rows = DB::table('extensions')
        ->where('context', $team->asteriskContext())
        ->where('exten', AsteriskDialplan::INTERNAL_PATTERN)
        ->orderBy('priority')
        ->get()
        ->keyBy('priority');

    // Written once, not once per extension.
    expect($rows)->toHaveCount(17)
        ->and($rows[5]->app)->toBe('Dial')
        ->and($rows[5]->appdata)->toStartWith('PJSIP/'.$team->asterisk_key.'_${EXTEN},')
        // An agent already on a call gets busy, not a second call.
        ->and($rows[2]->appdata)->toBe('CC_DEV=${DEVICE_STATE(PJSIP/'.$team->asterisk_key.'_${EXTEN})}')
        ->and($rows[3]->appdata)->toContain('INUSE')->toEndWith('?9')
        ->and($rows[10]->app)->toBe('Busy')
        // A softphone that is not registered: « unavailable » (SIP 480), no Dial error.
        ->and($rows[4]->appdata)->toContain('UNAVAILABLE')->toEndWith('?12')
        ->and($rows[13]->app)->toBe('Hangup')
        ->and($rows[13]->appdata)->toBe('20')
        // No answer, busy or offline: the extension's voicemail box when it has one.
        ->and($rows[7]->appdata)->toBe('$[${VM_INFO(${EXTEN}@'.$team->asterisk_key.',exists)}]?14')
        ->and($rows[14]->app)->toBe('VoiceMail')
        ->and($rows[14]->appdata)->toBe('${EXTEN}@'.$team->asterisk_key.',u')
        ->and($rows[16]->appdata)->toBe('${EXTEN}@'.$team->asterisk_key.',b');

    // *97: the agent's own voicemail.
    expect(DB::table('extensions')->where('context', $team->asteriskContext())->where('exten', '*97')->where('app', 'VoiceMailMain')->value('appdata'))
        ->toBe('${CUT(CHANNEL(endpoint),_,2)}@'.$team->asterisk_key.',s');
});

it('upgrades a dial rule written without the busy check', function () {
    $team = Team::factory()->internalOnly()->create();
    $context = $team->asteriskContext();

    DB::table('extensions')->insert([
        ['context' => $context, 'exten' => AsteriskDialplan::INTERNAL_PATTERN, 'priority' => 1, 'app' => 'Set', 'appdata' => 'CHANNEL(accountcode)='.$team->asterisk_key],
        ['context' => $context, 'exten' => AsteriskDialplan::INTERNAL_PATTERN, 'priority' => 2, 'app' => 'Dial', 'appdata' => 'PJSIP/'.$team->asterisk_key.'_${EXTEN},30'],
        ['context' => $context, 'exten' => AsteriskDialplan::INTERNAL_PATTERN, 'priority' => 3, 'app' => 'Hangup', 'appdata' => ''],
    ]);

    $this->artisan('asterisk:busy-agents')->assertSuccessful();

    expect(DB::table('extensions')->where('context', $context)->where('exten', AsteriskDialplan::INTERNAL_PATTERN)->count())->toBe(17)
        ->and(DB::table('extensions')->where('context', $context)->where('app', 'Busy')->exists())->toBeTrue();
});
