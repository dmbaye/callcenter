<?php

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use App\Http\Middleware\SetLocale;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * Name and e-mail use the sign-up rules; the company name of the
     * sign-up form is not part of the profile.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => $this->nameRules(),
            'email' => $this->emailRules($this->user()->id),
            'gender' => ['nullable', 'string', Rule::in(User::GENDERS)],
            'job_title' => ['nullable', 'string', 'max:100'],
            'department' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9 ().-]{6,30}$/'],
            'locale' => ['nullable', 'string', Rule::in(SetLocale::SUPPORTED)],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['gender', 'job_title', 'department', 'phone', 'locale'] as $field) {
            if ($this->has($field) && trim((string) $this->input($field)) === '') {
                $this->merge([$field => null]);
            }
        }
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'gender' => __('gender'),
            'job_title' => __('job title'),
            'department' => __('department'),
            'phone' => __('phone'),
            'locale' => __('language'),
        ];
    }
}
