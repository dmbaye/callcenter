<?php

namespace App\Http\Requests\Teams;

use App\Rules\TeamName;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * One step of the welcome wizard. The company profile rules are those of
 * the organization page; the step makes its essential fields required
 * (Team::PROFILE_REQUIRED_FIELDS), so a finished wizard leaves a complete
 * profile.
 */
class SaveOnboardingRequest extends UpdateOrganizationProfileRequest
{
    public const STEPS = ['company', 'contact'];

    /** Required fields, per step. */
    private const REQUIRED = [
        'company' => ['industry', 'company_size'],
        'contact' => ['country', 'city', 'phone'],
    ];

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $step = (string) $this->input('step');

        foreach (self::REQUIRED[$step] ?? [] as $field) {
            $rules[$field] = ['required', ...array_values(array_diff($rules[$field], ['nullable']))];
        }

        return [
            'step' => ['required', Rule::in(self::STEPS)],
            'name' => [$step === 'company' ? 'required' : 'sometimes', 'string', 'max:255', new TeamName],
            ...$rules,
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [...parent::attributes(), 'name' => 'nom de l\'équipe'];
    }
}
