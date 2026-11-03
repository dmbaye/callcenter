<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AsteriskTableEditor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

/**
 * Administration → Tables Asterisk: browse and edit the realtime tables
 * (trunks, transports, hold music, voicemail, queue rules) and the call logs.
 * Platform admins only (see routes/web.php).
 */
class AsteriskTablesController extends Controller
{
    public function __construct(private AsteriskTableEditor $editor) {}

    public function index(Request $request): Response
    {
        try {
            $tables = $this->editor->tables();
        } catch (Throwable $exception) {
            report($exception);

            return Inertia::render('admin/AsteriskTables', ['error' => $exception->getMessage(), 'tables' => []]);
        }

        $names = array_column($tables, 'name');
        $table = in_array($request->query('table'), $names, true) ? (string) $request->query('table') : ($names[0] ?? null);
        $search = $request->string('search')->toString();

        return Inertia::render('admin/AsteriskTables', [
            'error' => null,
            'tables' => $tables,
            'table' => $table,
            'search' => $search,
            'isLog' => $table !== null && $this->editor->isLog($table),
            'columns' => $table !== null ? $this->editor->columns($table) : [],
            'primaryKey' => $table !== null ? $this->editor->primaryKey($table) : [],
            'rows' => $table !== null ? $this->editor->rows($table, $search, min(100, max(10, $request->integer('per_page', 25))))->withQueryString() : null,
        ]);
    }

    public function store(Request $request, string $table): RedirectResponse
    {
        $this->editor->create($table, (array) $request->input('values', []), $this->actor($request));

        return $this->saved(__('Row added.'));
    }

    public function update(Request $request, string $table): RedirectResponse
    {
        $this->editor->update($table, (array) $request->input('row', []), (array) $request->input('values', []), $this->actor($request));

        return $this->saved(__('Row saved.'));
    }

    public function destroy(Request $request, string $table): RedirectResponse
    {
        $this->editor->delete($table, (array) $request->input('row', []), $this->actor($request));

        return $this->saved(__('Row deleted.'));
    }

    /** A binary column (a voicemail recording), as a download. */
    public function binary(Request $request, string $table): HttpResponse
    {
        $row = json_decode((string) $request->query('row', '{}'), true);
        $column = (string) $request->query('column', '');
        $content = $this->editor->binary($table, is_array($row) ? $row : [], $column);

        abort_if($content === null, 404);

        $name = $table.'-'.substr(sha1(json_encode(Arr::sortRecursive((array) $row))), 0, 8).'.bin';

        return response($content, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }

    private function saved(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()?->email;
    }
}
