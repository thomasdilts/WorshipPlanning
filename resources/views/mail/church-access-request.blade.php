<x-mail::message>
# {{ __('New church access request') }}

{{ __('A church has asked for permission to use WorshipPlanning.') }}

- **{{ __('Church name') }}:** {{ $churchName }}
- **{{ __('Street address') }}:** {{ $streetAddress }}
- **{{ __('City') }}:** {{ $city }}
- **{{ __('Postal code') }}:** {{ $postalCode }}
- **{{ __('Country') }}:** {{ $country }}
- **{{ __('Administrator name') }}:** {{ $adminName }}
- **{{ __('Position in the church') }}:** {{ $adminPosition }}
@if ($website)
- **{{ __('Church website') }}:** {{ $website }}
@endif
- **{{ __('Telephone') }}:** {{ $phone }}
- **{{ __('Email') }}:** {{ $email }}
- **{{ __('Expected users') }}:** {{ $expectedUsers }}
- **{{ __('Required languages') }}:** {{ $requiredLanguages }}
- **{{ __('Special requests') }}:** {{ $specialRequests }}

{{ __('Reply to this message to contact the applicant.') }}
</x-mail::message>
