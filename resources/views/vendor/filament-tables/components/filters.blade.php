@props([
    'applyAction',
    'form',
    'headingTag' => 'h3',
    'resetAction' => null,
    'resetActionPosition' => null,
])

@if ($this instanceof \App\Filament\Pages\MyTasks)
    <x-mobile-disclosure class="wp-planner-filters wp-schedule-filters" wire:key="schedule-filters">
        <x-slot name="summary">
            <x-filament::icon icon="heroicon-m-adjustments-horizontal" aria-hidden="true" />
            {{ __('filament-tables::table.filters.heading') }}
        </x-slot>

        <div class="wp-mobile-only wp-schedule-search">
            <label class="wp-ss-search">
                <x-filament::icon icon="heroicon-m-magnifying-glass" aria-hidden="true" />
                <span class="fi-sr-only">{{ __('filament-tables::table.fields.search.label') }}</span>
                <input type="search" wire:model.live.debounce.500ms="tableSearch" maxlength="1000" placeholder="{{ __('filament-tables::table.fields.search.placeholder') }}" />
            </label>
        </div>

        @include('filament.tables.filters-content')
    </x-mobile-disclosure>
@else
    @include('filament.tables.filters-content')
@endif
