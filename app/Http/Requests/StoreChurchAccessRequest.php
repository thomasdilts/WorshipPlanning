<?php

namespace App\Http\Requests;

use App\Enums\ExpectedUserRange;
use App\Services\TurnstileVerifier;
use App\Support\CountryOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreChurchAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'church_name' => ['required', 'string', 'max:150'],
            'street_address' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:150'],
            'postal_code' => ['required', 'string', 'max:30'],
            'country' => ['required', 'string', Rule::in(array_keys(CountryOptions::all()))],
            'admin_name' => ['required', 'string', 'max:150'],
            'admin_position' => ['required', 'string', 'max:150'],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'phone' => ['required', 'string', 'max:40'],
            'email' => ['required', 'email', 'max:255'],
            'expected_users' => ['required', Rule::enum(ExpectedUserRange::class)],
            'required_languages' => ['required', 'string', 'max:255'],
            'special_requests' => ['required', 'string', 'max:2000'],
        ];
    }

    /**
     * When Turnstile is configured, the form only goes through with a token Cloudflare accepts.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $turnstile = app(TurnstileVerifier::class);

                if ($turnstile->isEnabled() && ! $turnstile->passes($this->input('cf-turnstile-response'), $this->ip())) {
                    $validator->errors()->add('turnstile', __('We could not check that you are not a robot. Wait a moment and try again.'));
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'church_name' => __('Church name'),
            'street_address' => __('Street address'),
            'city' => __('City'),
            'postal_code' => __('Postal code'),
            'country' => __('Country'),
            'admin_name' => __('Administrator name'),
            'admin_position' => __('Position in the church'),
            'website' => __('Church website'),
            'phone' => __('Telephone'),
            'email' => __('Email'),
            'expected_users' => __('Expected users'),
            'required_languages' => __('Required languages'),
            'special_requests' => __('Special requests'),
        ];
    }
}
