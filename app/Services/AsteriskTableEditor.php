<?php

namespace App\Services;

use App\Services\Concerns\UsesAsteriskConnection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Generic editor over a fixed list of Asterisk realtime tables, for platform
 * admins (Administration → Plateforme → Tables Asterisk).
 *
 * The columns come from the database itself, so the editor follows whatever
 * schema your Asterisk version created. Rows are identified by the primary
 * key, or by all their values when the table has none (cdr, queue_log…).
 * Binary columns (voicemail recordings) are never sent to the browser or
 * written: they can only be downloaded. Every write is logged.
 */
class AsteriskTableEditor
{
    use UsesAsteriskConnection;

    /** Table => [group, read-mostly log written by Asterisk]. */
    public const TABLES = [
        'ps_endpoints' => ['PJSIP', false],
        'ps_aors' => ['PJSIP', false],
        'ps_auths' => ['PJSIP', false],
        'ps_endpoint_id_ips' => ['PJSIP', false],
        'ps_registrations' => ['PJSIP', false],
        'ps_transports' => ['PJSIP', false],
        'queue_members' => ['Files d\'attente', false],
        'queue_rules' => ['Files d\'attente', false],
        'musiconhold' => ['Musique d\'attente', false],
        'musiconhold_entry' => ['Musique d\'attente', false],
        'voicemail' => ['Messagerie vocale', false],
        'voicemail_messages' => ['Messagerie vocale', false],
        'cdr' => ['Journaux', true],
        'cel' => ['Journaux', true],
        'queue_log' => ['Journaux', true],
    ];

    private const BINARY_TYPES = ['bytea', 'blob', 'tinyblob', 'mediumblob', 'longblob', 'binary', 'varbinary'];

    /** @var array<string, list<array<string, mixed>>> */
    private array $columnCache = [];

    /**
     * The whitelisted tables that exist in this Asterisk database.
     *
     * @return list<array{name: string, group: string, log: bool}>
     */
    public function tables(): array
    {
        $schema = $this->connection()->getSchemaBuilder();

        return collect(self::TABLES)
            ->filter(fn (array $meta, string $table) => $schema->hasTable($table))
            ->map(fn (array $meta, string $table) => ['name' => $table, 'group' => $meta[0], 'log' => $meta[1]])
            ->values()
            ->all();
    }

    public function isLog(string $table): bool
    {
        return self::TABLES[$table][1] ?? false;
    }

    /**
     * @return list<array{name: string, type: string, nullable: bool, binary: bool, auto: bool, key: bool}>
     */
    public function columns(string $table): array
    {
        $this->guard($table);

        if (isset($this->columnCache[$table])) {
            return $this->columnCache[$table];
        }

        $schema = $this->connection()->getSchemaBuilder();
        $primary = $this->primaryKey($table);

        return $this->columnCache[$table] = collect($schema->getColumns($table))
            ->map(fn (array $column) => [
                'name' => (string) $column['name'],
                'type' => (string) $column['type_name'],
                'nullable' => (bool) $column['nullable'],
                'binary' => in_array(strtolower((string) $column['type_name']), self::BINARY_TYPES, true),
                'auto' => (bool) ($column['auto_increment'] ?? false),
                'key' => in_array($column['name'], $primary, true),
            ])
            ->values()
            ->all();
    }

    /** @return list<string> */
    public function primaryKey(string $table): array
    {
        $this->guard($table);

        $primary = collect($this->connection()->getSchemaBuilder()->getIndexes($table))
            ->first(fn (array $index) => (bool) ($index['primary'] ?? false));

        return array_values($primary['columns'] ?? []);
    }

    /**
     * One page of rows, newest first when the table has an obvious order.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function rows(string $table, ?string $search, int $perPage = 25): LengthAwarePaginator
    {
        $columns = $this->columns($table);
        $plain = array_values(array_map(fn ($c) => $c['name'], array_filter($columns, fn ($c) => ! $c['binary'])));
        $binary = array_values(array_map(fn ($c) => $c['name'], array_filter($columns, fn ($c) => $c['binary'])));

        $query = $this->connection()->table($table)->select($plain);

        foreach ($binary as $name) {
            // Size only: the content stays in the database (download separately).
            $query->selectRaw($this->lengthExpression($name).' as '.$this->wrap('__size_'.$name));
        }

        if ($search !== null && trim($search) !== '') {
            $this->applySearch($query, $columns, trim($search));
        }

        $this->applyOrder($query, $table, $plain);

        return $query->paginate($perPage)->through(fn ($row) => (array) $row);
    }

    /** @param  array<string, mixed>  $values */
    public function create(string $table, array $values, string $actor): void
    {
        $data = $this->writable($table, $values, creating: true);

        if ($data === []) {
            throw ValidationException::withMessages(['values' => __('Fill in at least one column.')]);
        }

        $this->connection()->table($table)->insert($data);
        $this->audit('insert', $table, $actor, ['values' => $data]);
    }

    /**
     * @param  array<string, mixed>  $identity  the row as it was shown (key columns, or every column)
     * @param  array<string, mixed>  $values
     */
    public function update(string $table, array $identity, array $values, string $actor): void
    {
        $data = $this->writable($table, $values, creating: false);
        $affected = $this->matching($table, $identity)->update($data);

        $this->audit('update', $table, $actor, ['row' => $identity, 'values' => $data, 'affected' => $affected]);
    }

    /** @param  array<string, mixed>  $identity */
    public function delete(string $table, array $identity, string $actor): void
    {
        $affected = $this->matching($table, $identity)->delete();

        $this->audit('delete', $table, $actor, ['row' => $identity, 'affected' => $affected]);
    }

    /**
     * Content of a binary column of one row (a voicemail recording).
     *
     * @param  array<string, mixed>  $identity
     */
    public function binary(string $table, array $identity, string $column): ?string
    {
        $meta = collect($this->columns($table))->firstWhere('name', $column);

        if ($meta === null || ! $meta['binary']) {
            throw new RuntimeException("[{$column}] is not a binary column of [{$table}].");
        }

        $value = $this->matching($table, $identity)->value($column);

        return is_resource($value) ? (string) stream_get_contents($value) : ($value !== null ? (string) $value : null);
    }

    /**
     * The query for exactly one row: its primary key, or every value shown
     * when the table has no key. Refuses when that matches several rows.
     *
     * @param  array<string, mixed>  $identity
     */
    private function matching(string $table, array $identity): Builder
    {
        $columns = collect($this->columns($table));
        $key = $this->primaryKey($table);
        $match = $key !== []
            ? $key
            : $columns->reject(fn ($c) => $c['binary'])->pluck('name')->all();

        $query = $this->connection()->table($table);

        foreach ($match as $name) {
            if (! array_key_exists($name, $identity)) {
                throw ValidationException::withMessages(['row' => __('The row to change is incomplete. Reload the page.')]);
            }

            $identity[$name] === null
                ? $query->whereNull($name)
                : $query->where($name, $identity[$name]);
        }

        $count = (clone $query)->count();

        if ($count !== 1) {
            throw ValidationException::withMessages(['row' => $count === 0
                ? __('This row no longer exists or was changed meanwhile. Reload the page.')
                : __('Several identical rows match: change them directly in the database.')]);
        }

        return $query;
    }

    /**
     * Only real, non-binary columns; empty strings become NULL where allowed;
     * an empty auto-increment key is left to the database.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function writable(string $table, array $values, bool $creating): array
    {
        $data = [];

        foreach ($this->columns($table) as $column) {
            $name = $column['name'];

            if ($column['binary'] || ! array_key_exists($name, $values)) {
                continue;
            }

            $value = $values[$name];
            $value = is_string($value) ? $value : ($value === null ? null : (string) $value);

            if ($value === '' || $value === null) {
                if ($creating && ($column['auto'] || ! $column['nullable'])) {
                    continue; // let the database fill it (default, sequence)
                }

                $value = $column['nullable'] ? null : '';
            }

            $data[$name] = $value;
        }

        return $data;
    }

    /** @param  list<array<string, mixed>>  $columns */
    private function applySearch(Builder $query, array $columns, string $search): void
    {
        $pgsql = $this->connection()->getDriverName() === 'pgsql';

        $query->where(function (Builder $where) use ($columns, $search, $pgsql) {
            foreach ($columns as $column) {
                if ($column['binary']) {
                    continue;
                }

                $pgsql
                    ? $where->orWhereRaw('CAST('.$this->wrap($column['name']).' AS TEXT) ILIKE ?', ['%'.$search.'%'])
                    : $where->orWhere($column['name'], 'like', '%'.$search.'%');
            }
        });
    }

    /** @param  list<string>  $plain */
    private function applyOrder(Builder $query, string $table, array $plain): void
    {
        foreach (['start', 'eventtime', 'time', 'calldate', 'origtime', 'created_at'] as $time) {
            if (in_array($time, $plain, true)) {
                $query->orderByDesc($time);

                return;
            }
        }

        foreach ($this->primaryKey($table) as $key) {
            $query->orderBy($key);
        }

        if ($this->primaryKey($table) === [] && $plain !== []) {
            $query->orderBy($plain[0]);
        }
    }

    private function lengthExpression(string $column): string
    {
        return match ($this->connection()->getDriverName()) {
            'pgsql' => 'octet_length('.$this->wrap($column).')',
            'sqlite' => 'length('.$this->wrap($column).')',
            default => 'LENGTH('.$this->wrap($column).')',
        };
    }

    private function wrap(string $identifier): string
    {
        return $this->connection()->getQueryGrammar()->wrap($identifier);
    }

    private function guard(string $table): void
    {
        if (! array_key_exists($table, self::TABLES)) {
            throw new RuntimeException("Table [{$table}] is not editable.");
        }
    }

    /** @param  array<string, mixed>  $context */
    private function audit(string $action, string $table, string $actor, array $context): void
    {
        Log::info("[asterisk-table] {$action} {$table} by {$actor}", $context);
    }
}
