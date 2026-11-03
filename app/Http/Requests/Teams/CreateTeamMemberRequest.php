<?php

namespace App\Http\Requests\Teams;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class CreateTeamMemberRequest extends FormRequest
{
    public function rules(): array
    {
        $team = $this->route('team');

        abort_if(! $team instanceof Team, 404);

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)],
            'password' => ['required', 'string', Password::default(), 'confirmed'],
            'role' => ['required', 'string', Rule::in(array_column(TeamRole::assignable(), 'value'))],
            'with_phone' => ['sometimes', 'boolean'],
            // Without a phone (supervisor, planner, administrator): no extension, no queue.
            'queue' => [Rule::excludeIf(! $this->needsPhone()), 'nullable', 'string', Rule::exists('call_queues', 'name')->where('team_id', $team->id)],
            'sip_extension' => [Rule::excludeIf(! $this->needsPhone()), 'required', 'string', 'regex:/^\d{2,20}$/', 'not_regex:/^00/', Rule::unique('phone_extensions', 'extension')->where('team_id', $team->id)],
            'sip_secret' => [Rule::excludeIf(! $this->needsPhone()), 'required', 'string', 'min:8', 'max:80'],
        ];
    }

    /** Agents always take calls; other roles get a phone only when asked. */
    public function needsPhone(): bool
    {
        return $this->input('role') === TeamRole::Member->value || $this->boolean('with_phone', true);
    }
}
