<?php

namespace App\Http\Controllers\Settings\Asterisk;

use App\Enums\RouteDestination;
use App\Http\Controllers\Controller;
use App\Models\InboundNumber;
use App\Models\IvrMenu;
use App\Models\Team;
use App\Services\AsteriskDialplan;
use App\Services\NumberPool;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Voice menus (IVR) and inbound numbers (DIDs) of the current team.
 */
class IvrController extends Controller
{
    use AuthorizesAsteriskSettings, ValidatesDestinations;

    private const SOUND = ['string', 'max:120', 'regex:/^[A-Za-z0-9_\/.-]+$/'];

    public function __construct(private AsteriskDialplan $dialplan, private NumberPool $pool) {}

    public function index(Request $request): Response
    {
        $team = $this->authorizedTeam($request);

        return Inertia::render('settings/asterisk/Ivr', [
            'menus' => $team->ivrMenus()->orderBy('name')->get()->map(fn (IvrMenu $menu) => [
                'id' => $menu->id,
                'name' => $menu->name,
                'extension' => $menu->extension(),
                'greeting_sound' => $menu->greeting_sound,
                'invalid_sound' => $menu->invalid_sound,
                'timeout' => $menu->timeout,
                'max_retries' => $menu->max_retries,
                'options' => $menu->options,
                'fallback_type' => $menu->fallback_type,
                'fallback_target' => $menu->fallback_target,
            ]),
            'numbers' => $team->inboundNumbers()->orderByRaw("case when source = 'pool' then 0 else 1 end")->orderBy('number')
                ->get(['id', 'number', 'source', 'label', 'destination_type', 'destination_target']),
            'pool' => [
                'available' => $this->pool->available(),
                'hasPoolNumber' => $team->inboundNumbers()->where('source', InboundNumber::SOURCE_POOL)->exists(),
            ],
            'queues' => $team->callQueues()->orderBy('name')->get(['id', 'name']),
            'destinations' => RouteDestination::options(),
            'inboundContext' => config('asterisk.inbound.context'),
            'teamContext' => $team->asteriskContext(),
        ]);
    }

    public function storeMenu(Request $request): RedirectResponse
    {
        $team = $this->authorizedTeam($request);
        $team->ivrMenus()->create($this->validatedMenu($request, $team));
        $this->dialplan->syncTeamRouting($team);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Voice menu saved.')]);

        return back();
    }

    public function updateMenu(Request $request, IvrMenu $menu): RedirectResponse
    {
        $team = $this->authorizedTeam($request);
        abort_unless($menu->team_id === $team->id, 404);

        $menu->update($this->validatedMenu($request, $team));
        $this->dialplan->syncTeamRouting($team);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Voice menu saved.')]);

        return back();
    }

    public function destroyMenu(Request $request, IvrMenu $menu): RedirectResponse
    {
        $team = $this->authorizedTeam($request);
        abort_unless($menu->team_id === $team->id, 404);

        $menu->setRelation('team', $team);
        $this->dialplan->removeIvr($menu);
        $menu->delete();
        $this->dialplan->syncTeamRouting($team);

        return back();
    }

    /** The customer's own number (a number of the platform pool is taken with claimNumber). */
    public function storeNumber(Request $request): RedirectResponse
    {
        $team = $this->authorizedTeam($request);
        $number = $team->inboundNumbers()->create([
            ...$this->validatedNumber($request, $team),
            'source' => InboundNumber::SOURCE_OWN,
        ]);
        $number->setRelation('team', $team);
        $this->dialplan->syncInboundNumber($number);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Number saved.')]);

        return back();
    }

    public function updateNumber(Request $request, InboundNumber $number): RedirectResponse
    {
        $team = $this->authorizedTeam($request);
        abort_unless($number->team_id === $team->id, 404);

        $previous = $number->number;
        $data = $this->validatedNumber($request, $team, $number->id, $number->fromPool() ? $number->number : null);

        // A number lent by the platform keeps its digits: replace it instead.
        if ($number->fromPool()) {
            unset($data['number']);
        }

        $number->update($data);
        $number->setRelation('team', $team);
        $this->dialplan->syncInboundNumber($number, $previous);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Number saved.')]);

        return back();
    }

    public function destroyNumber(Request $request, InboundNumber $number): RedirectResponse
    {
        $team = $this->authorizedTeam($request);
        abort_unless($number->team_id === $team->id, 404);

        $this->pool->remove($number);

        return back();
    }

    /** Take a number from the platform pool (when the team has none yet). */
    public function claimNumber(Request $request): RedirectResponse
    {
        $team = $this->authorizedTeam($request);

        if ($team->inboundNumbers()->where('source', InboundNumber::SOURCE_POOL)->exists()) {
            throw ValidationException::withMessages(['pool' => __('Your team already has a number from the platform.')]);
        }

        $number = $this->pool->ensureFor($team, force: true);

        if ($number === null) {
            throw ValidationException::withMessages(['pool' => __('No number is available right now. Contact support, or add your own number.')]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Number :number assigned to your team.', ['number' => $number->number])]);

        return back();
    }

    /** Keep the routing, swap the platform number for the customer's own one. */
    public function replaceNumber(Request $request, InboundNumber $number): RedirectResponse
    {
        $team = $this->authorizedTeam($request);
        abort_unless($number->team_id === $team->id, 404);

        $data = $request->validate([
            'own_number' => ['required', 'string', 'max:32', 'regex:/^\+?[0-9]{2,31}$/', Rule::unique('inbound_numbers', 'number'), $this->notInPool()],
        ]);

        $number->setRelation('team', $team);
        $replacement = $this->pool->replaceWithOwn($number, $data['own_number']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Calls to :number now follow the same routing.', ['number' => $replacement->number])]);

        return back();
    }

    /** Pool DIDs are handed out by the platform, never typed in. */
    private function notInPool(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if ($this->pool->isPoolNumber((string) $value)) {
                $fail(__('This number belongs to the platform pool and is assigned automatically.'));
            }
        };
    }

    /** @return array<string, mixed> */
    private function validatedMenu(Request $request, Team $team): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'greeting_sound' => ['required', ...self::SOUND],
            'invalid_sound' => ['nullable', ...self::SOUND],
            'timeout' => ['required', 'integer', 'min:1', 'max:30'],
            'max_retries' => ['required', 'integer', 'min:1', 'max:5'],
            'options' => ['array', 'max:11'],
            'options.*.digit' => ['required', 'string', 'regex:/^[0-9*]$/', 'distinct'],
            'options.*.label' => ['nullable', 'string', 'max:40'],
            'options.*.type' => ['required', Rule::enum(RouteDestination::class)],
            'options.*.target' => ['nullable'],
            'fallback_type' => ['required', Rule::enum(RouteDestination::class)],
            'fallback_target' => ['nullable'],
        ]);

        $options = [];

        foreach ($data['options'] ?? [] as $index => $option) {
            $options[] = [
                'digit' => $option['digit'],
                'label' => $option['label'] ?? null,
                'type' => $option['type'],
                'target' => $this->destinationTarget($team, $option['type'], $option['target'] ?? null, "options.{$index}.target"),
            ];
        }

        return [
            'name' => $data['name'],
            'greeting_sound' => $data['greeting_sound'],
            'invalid_sound' => $data['invalid_sound'] ?? null,
            'timeout' => $data['timeout'],
            'max_retries' => $data['max_retries'],
            'options' => $options,
            'fallback_type' => $data['fallback_type'],
            'fallback_target' => $this->destinationTarget($team, $data['fallback_type'], $data['fallback_target'] ?? null, 'fallback_target'),
        ];
    }

    /** @return array<string, mixed> */
    private function validatedNumber(Request $request, Team $team, ?int $ignoreId = null, ?string $poolNumber = null): array
    {
        $data = $request->validate([
            'number' => $poolNumber !== null
                ? ['nullable']
                : ['required', 'string', 'max:32', 'regex:/^\+?[0-9]{2,31}$/', Rule::unique('inbound_numbers', 'number')->ignore($ignoreId), $this->notInPool()],
            'label' => ['nullable', 'string', 'max:80'],
            'destination_type' => ['required', Rule::enum(RouteDestination::class)],
            'destination_target' => ['nullable'],
        ]);

        if (($data['destination_type'] ?? null) === RouteDestination::Hangup->value) {
            throw ValidationException::withMessages(['destination_type' => __('Choose where calls to this number go.')]);
        }

        return [
            'number' => $poolNumber ?? $data['number'],
            'label' => $data['label'] ?? null,
            'destination_type' => $data['destination_type'],
            'destination_target' => $this->destinationTarget($team, $data['destination_type'], $data['destination_target'] ?? null, 'destination_target'),
        ];
    }
}
