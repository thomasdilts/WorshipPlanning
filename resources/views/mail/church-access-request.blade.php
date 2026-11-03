<x-mail::message>
# {{ __('New church access request') }}

{{ __('A church has asked for permission to use WorshipPlanning.') }}

- **{{ __('Church name') }}:** {{ $churchName }}
- **{{ __('Address') }}:** {{ $address }}
- **{{ __('Country') }}:** {{ $country }}
- **{{ __('Telephone') }}:** {{ $phone }}
- **{{ __('Email') }}:** {{ $email }}
- **{{ __('Expected users') }}:** {{ $expectedUsers }}
- **{{ __('Special requests') }}:** {{ $specialRequests }}

{{ __('Reply to this message to contact the applicant.') }}
</x-mail::message>
