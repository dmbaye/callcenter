<?php

namespace App\Http\Requests\Teams;

use App\Models\Team;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Company profile of the organization. Choice fields only accept the keys
 * of config/organization.php.
 */
class UpdateOrganizationProfileRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $options = fn (string $list) => Rule::in(array_keys((array) config("organization.{$list}", [])));

        return [
            // Only sent by the forms that offer the choice; absent = unchanged.
            'usage_mode' => ['sometimes', 'required', 'string', Rule::in(Team::USAGE_MODES)],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'industry' => ['nullable', 'string', $options('industries')],
            'company_size' => ['nullable', 'string', $options('sizes')],
            'planned_agents' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'use_case' => ['nullable', 'string', $options('use_cases')],
            'country' => ['nullable', 'string', $options('countries')],
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9 ().-]{6,30}$/'],
            'contact_email' => ['nullable', 'string', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:255', 'url:http,https'],
            'rccm' => ['nullable', 'string', 'max:50'],
            'nif' => ['nullable', 'string', 'max:50'],
            'acquisition_source' => ['nullable', 'string', $options('acquisition_sources')],
            'marketing_opt_in' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $website = trim((string) $this->input('website', ''));

        // "mbayelab.ml" is accepted as https://mbayelab.ml.
        if ($website !== '' && ! preg_match('#^https?://#i', $website)) {
            $website = 'https://'.$website;
        }

        $this->merge([
            'website' => $website === '' ? null : $website,
            'marketing_opt_in' => $this->boolean('marketing_opt_in'),
        ]);
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'usage_mode' => 'utilisation',
            'legal_name' => 'raison sociale',
            'industry' => 'secteur d\'activité',
            'company_size' => 'taille',
            'planned_agents' => 'nombre d\'agents prévus',
            'use_case' => 'usage principal',
            'country' => 'pays',
            'city' => 'ville',
            'address' => 'adresse',
            'phone' => 'téléphone',
            'contact_email' => 'e-mail de contact',
            'website' => 'site web',
            'acquisition_source' => 'origine',
        ];
    }
}
