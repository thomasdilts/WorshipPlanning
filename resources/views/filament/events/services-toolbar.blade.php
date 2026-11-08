@php
    use App\Filament\Resources\Events\EventResource;
@endphp

<x-mobile-disclosure class="wp-planner-filters" wire:key="services-filters">
    <x-slot name="summary">
        <x-filament::icon icon="heroicon-m-adjustments-horizontal" aria-hidden="true" />
        {{ __('filament-tables::table.filters.heading') }}
    </x-slot>

    <div class="wp-ss-toolbar wp-services-toolbar" aria-label="{{ __('Service tools') }}"
        x-on:resize.window="updateHeight()"
        x-on:planner-layout-changed.window="updateHeight()"
        x-data="{
            resizeObserver: null,
            mutationObserver: null,
            frame: null,
            updateHeight() {
                cancelAnimationFrame(this.frame);
                this.frame = requestAnimationFrame(() => {
                    const table = this.$el.closest('.fi-ta');
                    const content = table?.querySelector('.fi-ta-content-ctn');
                    if (!content) return;
                    const footer = table.querySelector('.fi-pagination');
                    const bottomSpace = (document.querySelector('.wp-tabbar')?.getBoundingClientRect().height ?? 0) + 16;
                    const available = Math.max(160, window.innerHeight - content.getBoundingClientRect().top - (footer?.getBoundingClientRect().height ?? 0) - bottomSpace);
                    const height = Math.floor(available) + 'px';
                    if (content.style.getPropertyValue('--wp-services-available-height') !== height) {
                        content.style.setProperty('--wp-services-available-height', height);
                    }
                });
            },
            init() {
                const table = this.$el.closest('.fi-ta');
                this.resizeObserver = new ResizeObserver(() => this.updateHeight());
                this.resizeObserver.observe(this.$el);
                this.mutationObserver = new MutationObserver(() => this.updateHeight());
                this.mutationObserver.observe(table, { childList: true, subtree: true });
                this.$nextTick(() => this.updateHeight());
            },
            destroy() {
                this.resizeObserver?.disconnect();
                this.mutationObserver?.disconnect();
                cancelAnimationFrame(this.frame);
            }
        }">
        <label class="wp-ss-search">
            <x-filament::icon icon="heroicon-m-magnifying-glass" aria-hidden="true" />
            <span class="sr-only">{{ __('Search') }}</span>
            <input type="search" wire:model.live.debounce.300ms="tableSearch" placeholder="{{ __('Search services') }}" />
        </label>

        <x-event-type-filter :event-names="$this->eventNameOptions()" :selected-names="$this->tableFilters['events']['names'] ?? null" />

        <div class="wp-services-dates">
            <label class="wp-ss-select-field">
                <span>{{ __('From') }}</span>
                <input type="date" aria-label="{{ __('From') }}" wire:model.live="tableFilters.from_date.date" />
            </label>
            <label class="wp-ss-select-field">
                <span>{{ __('Until') }}</span>
                <input type="date" aria-label="{{ __('Until') }}" wire:model.live="tableFilters.from_date.until" min="{{ $this->tableFilters['from_date']['date'] ?? '' }}" />
            </label>
            <button type="button" class="wp-ss-control" wire:click="showEventsFromToday">{{ __('Today') }}</button>
        </div>

        <x-planner-columns :reorderable="false" />

        @can('useTemplates', \App\Models\Event::class)
            <x-filament::button tag="a" :href="EventResource::getUrl('templates')" icon="heroicon-m-bookmark" color="gray" class="wp-services-templates">
                {{ __('Service templates') }}
            </x-filament::button>
        @endcan

        @if (EventResource::canCreate())
            <x-filament::button tag="a" :href="EventResource::getUrl('create')" icon="heroicon-m-plus" class="wp-services-create">
                {{ __('Create event') }}
            </x-filament::button>
        @endif
    </div>
</x-mobile-disclosure>
