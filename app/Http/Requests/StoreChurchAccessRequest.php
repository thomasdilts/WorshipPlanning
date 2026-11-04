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
            'address' => ['required', 'string', 'max:500'],
            'country' => ['required', 'string', Rule::in(array_keys(CountryOptions::all()))],
            'phone' => ['required', 'string', 'max:40'],
            'email' => ['required', 'email', 'max:255'],
            'expected_users' => ['required', Rule::enum(ExpectedUserRange::class)],
            'required_languages' => ['required', 'string', 'max:255'],
            'special_requests' => ['nullable', 'string', 'max:2000'],
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
            'address' => __('Address'),
            'country' => __('Country'),
            'phone' => __('Telephone'),
            'email' => __('Email'),
            'expected_users' => __('Expected users'),
            'required_languages' => __('Required languages'),
            'special_requests' => __('Special requests'),
        ];
    }
}
