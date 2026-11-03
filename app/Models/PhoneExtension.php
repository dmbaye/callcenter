<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['team_id', 'user_id', 'extension', 'secret', 'label', 'is_primary'])]
class PhoneExtension extends Model
{
    use HasFactory;

    protected $table = 'phone_extensions';

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'is_primary' => 'boolean',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function asteriskName(): string
    {
        return $this->team->asterisk_key.'_'.$this->extension;
    }

    /**
     * Credentials handed to the browser softphone (SIP.js).
     *
     * @return array{username:string,pjsip_name:string,extension:string,password:string,domain:mixed,wsServer:mixed}|null
     */
    public function sipCredentials(): ?array
    {
        if ($this->secret === '' || $this->secret === null) {
            return null;
        }

        $name = $this->asteriskName();

        return [
            'username' => $name,
            'pjsip_name' => $name,
            'extension' => $this->extension,
            'password' => $this->secret,
            'domain' => config('asterisk.sip.domain'),
            'wsServer' => config('asterisk.sip.ws_url'),
            'iceServers' => self::iceServers(),
        ];
    }

    /**
     * STUN/TURN servers for the browser softphone, from config.
     *
     * @return list<array{urls: string, username?: string, credential?: string}>
     */
    public static function iceServers(): array
    {
        $servers = [];

        if ($stun = config('asterisk.sip.stun_url')) {
            $servers[] = ['urls' => (string) $stun];
        }

        if ($turn = config('asterisk.sip.turn_url')) {
            $servers[] = array_filter([
                'urls' => (string) $turn,
                'username' => config('asterisk.sip.turn_username'),
                'credential' => config('asterisk.sip.turn_credential'),
            ], fn ($value) => $value !== null && $value !== '');
        }

        return $servers;
    }
}
