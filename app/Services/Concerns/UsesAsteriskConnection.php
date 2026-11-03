<?php

namespace App\Services\Concerns;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Shared access to the Asterisk realtime database, with per-process caching
 * of the column listings (schema introspection is one query per call).
 */
trait UsesAsteriskConnection
{
    /** @var array<string, list<string>> */
    private static array $asteriskColumns = [];

    public function connection(): ConnectionInterface
    {
        return DB::connection(config('asterisk.connection'));
    }

    /** @return list<string> */
    protected function asteriskColumns(string $table): array
    {
        $key = config('asterisk.connection').'.'.$table;

        return self::$asteriskColumns[$key] ??= $this->connection()->getSchemaBuilder()->getColumnListing($table);
    }

    public static function flushAsteriskColumnCache(): void
    {
        self::$asteriskColumns = [];
    }
}
