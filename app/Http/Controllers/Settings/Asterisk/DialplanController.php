<?php

namespace App\Http\Controllers\Settings\Asterisk;

use App\Http\Controllers\Controller;
use App\Services\AsteriskDialplan;
use App\Services\CallFlow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class DialplanController extends Controller
{
    use AuthorizesAsteriskSettings;

    private const CONTEXT_REGEX = '/^[\w-]+$/';

    // Asterisk extension grammar, including pattern matching: _[0-9a-z]., XZN, !, * # + - _
    private const EXTEN_REGEX = '/^[A-Za-z0-9*#+_\-.!\[\]]+$/';

    /**
     * "Plan d'appel": the call plan in plain words, plus an expert editor
     * for custom rows. Rows the app generates are shown but locked.
     */
    public function index(Request $request, AsteriskDialplan $dialplan, CallFlow $flow): Response
    {
        $team = $this->authorizedTeam($request);
        $managed = array_flip($dialplan->managedExtensions($team));

        return Inertia::render('settings/asterisk/Dialplan', [
            'flow' => $flow->forTeam($team),
            'rows' => array_map(fn (array $row) => [
                ...$row,
                'managed' => isset($managed[$row['exten']]) || str_starts_with((string) $row['exten'], 'ivr-'),
            ], $dialplan->rows($team->asteriskContext())),
            'teamContext' => $team->asteriskContext(),
        ]);
    }

    public function store(Request $request, AsteriskDialplan $dialplan): RedirectResponse
    {
        $this->authorizedTeam($request);

        $data = $this->validated($request);
        $this->assertTeamContext($request, $data['context']);
        $this->assertNotManaged($request, $dialplan, $data['exten']);

        if ($dialplan->rowExists($data['context'], $data['exten'], $data['priority'])) {
            throw ValidationException::withMessages([
                'priority' => __('A row with this context, extension and priority already exists.'),
            ]);
        }

        $dialplan->createRow($data);

        return back();
    }

    public function update(Request $request, AsteriskDialplan $dialplan): RedirectResponse
    {
        $this->authorizedTeam($request);

        $original = $request->validate([
            'original_context' => ['required', 'string', 'max:60'],
            'original_exten' => ['required', 'string', 'max:60'],
            'original_priority' => ['required', 'integer', 'between:1,999'],
        ]);

        $data = $this->validated($request);
        $this->assertTeamContext($request, $original['original_context']);
        $this->assertTeamContext($request, $data['context']);
        $this->assertNotManaged($request, $dialplan, $original['original_exten']);
        $this->assertNotManaged($request, $dialplan, $data['exten']);

        $keyChanged = $original['original_context'] !== $data['context']
            || $original['original_exten'] !== $data['exten']
            || (int) $original['original_priority'] !== $data['priority'];

        if ($keyChanged && $dialplan->rowExists($data['context'], $data['exten'], $data['priority'])) {
            throw ValidationException::withMessages([
                'priority' => __('A row with this context, extension and priority already exists.'),
            ]);
        }

        try {
            $dialplan->updateRow([
                'context' => $original['original_context'],
                'exten' => $original['original_exten'],
                'priority' => (int) $original['original_priority'],
            ], $data);
        } catch (RuntimeException) {
            abort(404);
        }

        return back();
    }

    public function destroy(Request $request, AsteriskDialplan $dialplan): RedirectResponse
    {
        $this->authorizedTeam($request);

        $data = $request->validate([
            'context' => ['required', 'string', 'max:60'],
            'exten' => ['required', 'string', 'max:60'],
            'priority' => ['required', 'integer', 'between:1,999'],
        ]);

        $this->assertTeamContext($request, $data['context']);
        $this->assertNotManaged($request, $dialplan, $data['exten']);

        try {
            $dialplan->deleteRow($data['context'], $data['exten'], $data['priority']);
        } catch (RuntimeException) {
            abort(404);
        }

        return back();
    }

    /**
     * @return array{context: string, exten: string, priority: int, app: string, appdata: string}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'context' => ['required', 'string', 'max:60', 'regex:'.self::CONTEXT_REGEX],
            'exten' => ['required', 'string', 'max:80', 'regex:'.self::EXTEN_REGEX],
            'priority' => ['required', 'integer', 'between:1,999'],
            'app' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z]\w*$/'],
            'appdata' => ['nullable', 'string', 'max:512'],
        ]);

        $data['priority'] = (int) $data['priority'];
        $data['appdata'] = (string) ($data['appdata'] ?? '');

        return $data;
    }

    private function assertNotManaged(Request $request, AsteriskDialplan $dialplan, string $exten): void
    {
        if ($dialplan->isManagedExtension($this->authorizedTeam($request), $exten)) {
            throw ValidationException::withMessages([
                'exten' => __('This line is managed by the app (internal calls, a queue or a voice menu). Change it from the Queues or Numbers & IVR page.'),
            ]);
        }
    }

    private function assertTeamContext(Request $request, string $context): void
    {
        abort_unless($context === $this->authorizedTeam($request)->asteriskContext(), 403);
    }
}
