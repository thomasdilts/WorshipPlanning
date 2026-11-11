@props(['eventNames', 'selectedNames' => null])

@php
    $allTypesSelected = $selectedNames === null || (count($selectedNames) === count($eventNames) && array_diff(array_keys($eventNames), $selectedNames) === []);
@endphp

<x-filament::dropdown :menu="false" placement="bottom-start" width="sm" :max-height="'24rem'" :teleport="true">
    <x-slot name="trigger">
        <button type="button" class="wp-ss-control wp-ss-event-trigger">
            <span class="wp-ss-control-label">{{ __('Service types') }}</span>
            <span class="wp-ss-control-value">
                @if ($allTypesSelected)
                    {{ __('All service types') }}
                @elseif (count($selectedNames) === 1)
                    {{ $selectedNames[0] }}
                @else
                    {{ __(':count selected', ['count' => count($selectedNames)]) }}
                @endif
            </span>
            <x-filament::icon icon="heroicon-m-chevron-down" aria-hidden="true" />
        </button>
    </x-slot>
    <div class="wp-ss-event-menu" x-data="{ multiple: @js(is_array($selectedNames) && count($selectedNames) !== 1 && count($selectedNames) !== count($eventNames)) }">
        <div x-show="!multiple">
            <button type="button" class="wp-ss-event-option" wire:click="selectEventType" x-on:click="close()">
                {{ __('All service types') }}
            </button>
            @foreach ($eventNames as $name)
                <button type="button" class="wp-ss-event-option" wire:click="selectEventType(@js($name))" x-on:click="close()">
                    <span>{{ $name }}</span>
                    @if ($selectedNames === [$name])
                        <x-filament::icon icon="heroicon-m-check" aria-hidden="true" />
                    @endif
                </button>
            @endforeach
            <button type="button" class="wp-ss-event-option wp-ss-event-multiple" x-on:click="multiple = true" @if ($selectedNames === null) wire:click="selectEventTypes(true)" @endif>
                <x-filament::icon icon="heroicon-m-squares-plus" aria-hidden="true" />
                {{ __('Choose multiple types') }}
            </button>
        </div>
        <div x-show="multiple" x-cloak>
            <p class="wp-ss-menu-help">{{ __('Select any combination. Changes apply immediately.') }}</p>
            <div class="wp-ss-menu-actions">
                <button type="button" wire:click="selectEventTypes(true)">{{ __('Select all') }}</button>
                <button type="button" wire:click="selectEventTypes(false)">{{ __('Select none') }}</button>
            </div>
            @foreach ($eventNames as $name)
                <label class="wp-ss-event-option">
                    <input type="checkbox" class="fi-checkbox-input" wire:model.live="tableFilters.events.names" value="{{ $name }}" />
                    <span>{{ $name }}</span>
                </label>
            @endforeach
            <button type="button" class="wp-ss-event-option wp-ss-event-multiple" x-on:click="multiple = false">{{ __('Choose one type') }}</button>
        </div>
    </div>
</x-filament::dropdown>
