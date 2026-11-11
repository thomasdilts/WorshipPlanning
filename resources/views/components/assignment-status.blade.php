@props(['state'])

<span {{ $attributes->class(['wp-status', 'wp-status-'.$state->tone()]) }}>
    <x-filament::icon :icon="$state->icon()" aria-hidden="true" />
    <span class="wp-status-label">{{ $state->label() }}</span>
</span>
