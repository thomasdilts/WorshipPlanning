@extends('layouts.public')

@section('title', __('Connect your church').' — '.config('app.name'))

@section('meta')
    <meta name="description" content="{{ __('Request access to WorshipPlanning for your church. Share your church and administrator details so we can help you get started.') }}">
    <link rel="canonical" href="{{ route('partner.create') }}">
@endsection

@section('content')
    <div class="mb-8 max-w-xl">
        <p class="text-sm font-medium text-[var(--color-wp-muted)]">{{ __('Get started') }}</p>
        <h1 class="mt-2 font-serif text-4xl font-normal tracking-tight text-[var(--color-wp-text)] sm:text-5xl">
            {{ __('Connect your church') }}
        </h1>
        <p class="mt-3 text-base leading-relaxed text-[var(--color-wp-muted)]">
            {{ __('Tell us about your congregation. We will review your request and follow up by email.') }}
        </p>
    </div>

    @if (session('status'))
        <div class="mb-6 rounded-[10px] border border-[var(--color-wp-border)] bg-[var(--color-wp-accent-soft)] px-4 py-3 text-sm text-[var(--color-wp-accent-deep)]" role="status">
            {{ session('status') }}
        </div>
    @endif

    <form method="POST" action="{{ route('partner.store') }}" class="space-y-5 rounded-2xl border border-[var(--color-wp-border)] bg-[var(--color-wp-ground)] p-6 sm:p-8">
        @csrf

        <p class="text-sm text-[var(--color-wp-muted)]">{{ __('All fields are required except the church website.') }}</p>

        <div>
            <label for="church_name" class="block text-sm font-medium text-[var(--color-wp-text)]">{{ __('Church name') }}</label>
            <input id="church_name" name="church_name" type="text" value="{{ old('church_name') }}" required autocomplete="organization" maxlength="150"
                class="mt-1.5 w-full rounded-[10px] border border-[var(--color-wp-border)] bg-white px-3 py-2 text-sm focus:border-[var(--color-wp-accent)] focus:outline-none focus:ring-2 focus:ring-[var(--color-wp-accent)]/25 @error('church_name') border-red-400 @enderror">
            @error('church_name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="street_address" class="block text-sm font-medium text-[var(--color-wp-text)]">{{ __('Street address') }}</label>
            <input id="street_address" name="street_address" type="text" value="{{ old('street_address') }}" required autocomplete="street-address" maxlength="255"
                class="mt-1.5 w-full rounded-[10px] border border-[var(--color-wp-border)] bg-white px-3 py-2 text-sm focus:border-[var(--color-wp-accent)] focus:outline-none focus:ring-2 focus:ring-[var(--color-wp-accent)]/25 @error('street_address') border-red-400 @enderror">
            @error('street_address') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label for="city" class="block text-sm font-medium text-[var(--color-wp-text)]">{{ __('City') }}</label>
                <input id="city" name="city" type="text" value="{{ old('city') }}" required autocomplete="address-level2" maxlength="150"
                    class="mt-1.5 w-full rounded-[10px] border border-[var(--color-wp-border)] bg-white px-3 py-2 text-sm focus:border-[var(--color-wp-accent)] focus:outline-none focus:ring-2 focus:ring-[var(--color-wp-accent)]/25 @error('city') border-red-400 @enderror">
                @error('city') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="postal_code" class="block text-sm font-medium text-[var(--color-wp-text)]">{{ __('Postal code') }}</label>
                <input id="postal_code" name="postal_code" type="text" value="{{ old('postal_code') }}" required autocomplete="postal-code" maxlength="30"
                    class="mt-1.5 w-full rounded-[10px] border border-[var(--color-wp-border)] bg-white px-3 py-2 text-sm focus:border-[var(--color-wp-accent)] focus:outline-none focus:ring-2 focus:ring-[var(--color-wp-accent)]/25 @error('postal_code') border-red-400 @enderror">
                @error('postal_code') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>

        <div>
            <label for="country" class="block text-sm font-medium text-[var(--color-wp-text)]">{{ __('Country') }}</label>
            <select id="country" name="country" required autocomplete="country"
                class="mt-1.5 w-full rounded-[10px] border border-[var(--color-wp-border)] bg-white px-3 py-2 text-sm focus:border-[var(--color-wp-accent)] focus:outline-none focus:ring-2 focus:ring-[var(--color-wp-accent)]/25 @error('country') border-red-400 @enderror">
                <option value="">{{ __('Select a country') }}</option>
                @foreach ($countries as $code => $label)
                    <option value="{{ $code }}" @selected(old('country') === $code)>{{ $label }}</option>
                @endforeach
            </select>
            @error('country') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="website" class="block text-sm font-medium text-[var(--color-wp-text)]">{{ __('Church website (optional)') }}</label>
            <input id="website" name="website" type="url" value="{{ old('website') }}" autocomplete="url" maxlength="255" dir="ltr"
                class="mt-1.5 w-full rounded-[10px] border border-[var(--color-wp-border)] bg-white px-3 py-2 text-sm focus:border-[var(--color-wp-accent)] focus:outline-none focus:ring-2 focus:ring-[var(--color-wp-accent)]/25 @error('website') border-red-400 @enderror">
            @error('website') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label for="admin_name" class="block text-sm font-medium text-[var(--color-wp-text)]">{{ __('Administrator name') }}</label>
                <input id="admin_name" name="admin_name" type="text" value="{{ old('admin_name') }}" required autocomplete="name" maxlength="150"
                    class="mt-1.5 w-full rounded-[10px] border border-[var(--color-wp-border)] bg-white px-3 py-2 text-sm focus:border-[var(--color-wp-accent)] focus:outline-none focus:ring-2 focus:ring-[var(--color-wp-accent)]/25 @error('admin_name') border-red-400 @enderror">
                @error('admin_name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="admin_position" class="block text-sm font-medium text-[var(--color-wp-text)]">{{ __('Position in the church') }}</label>
                <input id="admin_position" name="admin_position" type="text" value="{{ old('admin_position') }}" required autocomplete="organization-title" maxlength="150"
                    class="mt-1.5 w-full rounded-[10px] border border-[var(--color-wp-border)] bg-white px-3 py-2 text-sm focus:border-[var(--color-wp-accent)] focus:outline-none focus:ring-2 focus:ring-[var(--color-wp-accent)]/25 @error('admin_position') border-red-400 @enderror">
                @error('admin_position') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>

        <div>
            <label for="phone" class="block text-sm font-medium text-[var(--color-wp-text)]">{{ __('Telephone') }}</label>
            <input id="phone" name="phone" type="tel" value="{{ old('phone') }}" required autocomplete="tel" maxlength="40" dir="ltr"
                class="mt-1.5 w-full rounded-[10px] border border-[var(--color-wp-border)] bg-white px-3 py-2 text-sm focus:border-[var(--color-wp-accent)] focus:outline-none focus:ring-2 focus:ring-[var(--color-wp-accent)]/25 @error('phone') border-red-400 @enderror">
            @error('phone') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="email" class="block text-sm font-medium text-[var(--color-wp-text)]">{{ __('Email') }}</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email" dir="ltr" maxlength="255"
                class="mt-1.5 w-full rounded-[10px] border border-[var(--color-wp-border)] bg-white px-3 py-2 text-sm focus:border-[var(--color-wp-accent)] focus:outline-none focus:ring-2 focus:ring-[var(--color-wp-accent)]/25 @error('email') border-red-400 @enderror">
            @error('email') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <fieldset>
            <legend class="text-sm font-medium text-[var(--color-wp-text)]">{{ __('Expected users') }}</legend>
            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                @foreach ($expectedUserRanges as $value => $label)
                    <label class="flex cursor-pointer items-center gap-2 rounded-[10px] border border-[var(--color-wp-border)] px-3 py-2 text-sm hover:border-[var(--color-wp-accent)] has-[:checked]:border-[var(--color-wp-accent)] has-[:checked]:bg-[var(--color-wp-accent-soft)]">
                        <input type="radio" name="expected_users" value="{{ $value }}" @checked(old('expected_users') === $value) required class="text-[var(--color-wp-accent)] focus:ring-[var(--color-wp-accent)]">
                        <span>{{ $label }}</span>
                    </label>
                @endforeach
            </div>
            @error('expected_users') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </fieldset>

        <div>
            <label for="required_languages" class="block text-sm font-medium text-[var(--color-wp-text)]">{{ __('Required languages') }}</label>
            <input id="required_languages" name="required_languages" type="text" value="{{ old('required_languages') }}" required maxlength="255"
                aria-describedby="required_languages_note"
                placeholder="{{ __('For example English, Swedish and Arabic') }}"
                class="mt-1.5 w-full rounded-[10px] border border-[var(--color-wp-border)] bg-white px-3 py-2 text-sm focus:border-[var(--color-wp-accent)] focus:outline-none focus:ring-2 focus:ring-[var(--color-wp-accent)]/25 @error('required_languages') border-red-400 @enderror">
            <p id="required_languages_note" class="mt-1.5 text-sm text-[var(--color-wp-muted)]">{{ __('The app’s translations are made with AI, so some wording may need adjusting. Tell us what reads wrong and we will fix it.') }}</p>
            @error('required_languages') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="special_requests" class="block text-sm font-medium text-[var(--color-wp-text)]">{{ __('Special requests') }}</label>
            <textarea id="special_requests" name="special_requests" rows="4" required maxlength="2000" aria-describedby="special_requests_note"
                class="mt-1.5 w-full rounded-[10px] border border-[var(--color-wp-border)] bg-white px-3 py-2 text-sm focus:border-[var(--color-wp-accent)] focus:outline-none focus:ring-2 focus:ring-[var(--color-wp-accent)]/25 @error('special_requests') border-red-400 @enderror"
                placeholder="{{ __('Anything we should know — denominations, languages, timeline…') }}">{{ old('special_requests') }}</textarea>
            <p id="special_requests_note" class="mt-1.5 text-sm text-[var(--color-wp-muted)]">{{ __('Enter “None” if you have no special requests.') }}</p>
            @error('special_requests') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        @if ($turnstileSiteKey)
            <div>
                <div class="cf-turnstile" data-sitekey="{{ $turnstileSiteKey }}"></div>
                @error('turnstile') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
        @endif

        <div class="flex flex-col-reverse gap-3 pt-2 sm:flex-row sm:items-center sm:justify-between">
            <a href="{{ url('/admin/login') }}" class="justify-center text-center text-sm font-medium text-[var(--color-wp-muted)] hover:text-[var(--color-wp-accent)] sm:justify-start sm:text-start">
                {{ __('Back to sign in') }}
            </a>
            <button type="submit"
                class="wp-public-cta inline-flex cursor-pointer items-center justify-center rounded-[10px] bg-[var(--color-wp-accent)] px-5 py-2.5 text-sm font-semibold text-white hover:bg-[var(--color-wp-accent-deep)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-wp-accent)]">
                {{ __('Submit request') }}
            </button>
        </div>
    </form>
@endsection
