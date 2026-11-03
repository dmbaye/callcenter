<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * A platform-wide setting, editable by platform admins (not by teams).
 *
 * @property string $key
 * @property string|null $value
 */
#[Fillable(['key', 'value'])]
class PlatformSetting extends Model
{
    public const OUTBOUND_TRUNK = 'outbound_trunk';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = Cache::rememberForever(self::cacheKey($key), fn () => static::query()->whereKey($key)->value('value') ?? '');

        return $value === '' ? $default : $value;
    }

    public static function put(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget(self::cacheKey($key));
    }

    private static function cacheKey(string $key): string
    {
        return 'platform_setting:'.$key;
    }
}
