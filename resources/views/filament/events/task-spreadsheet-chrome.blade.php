@php
    use App\Enums\SpreadsheetDensity;
    use App\Enums\SpreadsheetLayout;
    use App\Enums\SpreadsheetRangeKey;
    use App\Enums\SpreadsheetViewKey;

    $view = SpreadsheetViewKey::tryFrom($this->staffingView) ?? SpreadsheetViewKey::AllStaffing;
    $range = SpreadsheetRangeKey::tryFrom($this->range) ?? SpreadsheetRangeKey::NextSixWeeks;
    $density = SpreadsheetDensity::Comfortable;
    $layout = SpreadsheetLayout::Auto;
    $listRows = $this->listRows();
    $eventNames = $this->eventNameOptions();
    $selectedNames = $this->tableFilters['events']['names'] ?? null;
@endphp

<div @class(['wp-ss-workspace', 'wp-ss-density-'.$density->value, 'wp-ss-layout-'.$layout->value])>
    <x-mobile-disclosure class="wp-planner-filters" wire:key="staffing-filters">
        <x-slot name="summary">
            <x-filament::icon icon="heroicon-m-adjustments-horizontal" aria-hidden="true" />
            {{ __('filament-tables::table.filters.heading') }}
        </x-slot>
        <div class="wp-ss-toolbar" aria-label="{{ __('Tasks by service tools') }}">
            <label class="wp-ss-search">
                <x-filament::icon icon="heroicon-m-magnifying-glass" aria-hidden="true" />
                <span class="sr-only">{{ __('Search') }}</span>
                <input type="search" wire:model.live.debounce.300ms="tableSearch" placeholder="{{ __('Search services or people') }}" />
            </label>

            <x-event-type-filter :event-names="$eventNames" :selected-names="$selectedNames" />

            <label class="wp-ss-select-field">
                <span>{{ __('Date range') }}</span>
                <select aria-label="{{ __('Date range') }}" wire:change="setRange($event.target.value)">
                    @foreach (SpreadsheetRangeKey::cases() as $key)
                        <option value="{{ $key->value }}" @selected($range === $key)>{{ $key->label() }}</option>
                    @endforeach
                </select>
            </label>
            <label class="wp-ss-select-field">
                <span>{{ __('Saved views') }}</span>
                <select aria-label="{{ __('Saved views') }}" wire:change="setStaffingView($event.target.value)">
                    @foreach (SpreadsheetViewKey::cases() as $key)
                        <option value="{{ $key->value }}" @selected($view === $key)>{{ $key->label() }}</option>
                    @endforeach
                </select>
            </label>

            <x-planner-columns />

            <div class="wp-ss-actions">
                @if (\App\Filament\Resources\Events\EventResource::canCreate())
                    {{ $this->createEventAction }}
                @endif
                {{ $this->exportSpreadsheetAction }}
            </div>

            @if ($range === SpreadsheetRangeKey::Custom)
                <div class="wp-ss-custom-dates">
                    <label class="wp-ss-select-field"><span>{{ __('From') }}</span><input type="date" wire:model.live="tableFilters.custom_dates.from" /></label>
                    <label class="wp-ss-select-field"><span>{{ __('Until') }}</span><input type="date" wire:model.live="tableFilters.custom_dates.until" min="{{ $this->customFrom }}" /></label>
                </div>
            @endif
        </div>
    </x-mobile-disclosure>
    <div class="wp-ss-scroll-hint" aria-hidden="true">
        {{ __('Scroll sideways to see more columns') }}
    </div>

    <div class="wp-ss-list-panel">
        @if ($listRows === [])
            <p class="wp-empty-hint">{{ __('Nothing needs attention in this view.') }}</p>
        @else
            <ul class="wp-ss-event-list">
                @foreach (collect($listRows)->groupBy('event_id') as $eventId => $eventItems)
                    @php($event = $eventItems->first())
                    <li class="wp-ss-tone-{{ $event['event_tone'] }}">
                        <x-mobile-disclosure class="wp-ss-event-group" wire:key="staffing-event-{{ $eventId }}">
                            <x-slot name="summary">
                                <span class="wp-ss-event-heading">
                                    <span class="wp-ss-event-name">{{ $event['event_name'] }}</span>
                                    <span class="wp-ss-event-when">
                                        <time datetime="{{ $event['event_date'] }}">{{ $event['event_calendar_label'] }}</time>
                                        · {{ $event['event_day_label'] }}@if (filled($event['event_time_label'] ?? null)) · {{ $event['event_time_label'] }}@endif
                                    </span>
                                </span>
                            </x-slot>

                            <div class="wp-ss-event-actions">
                                <a href="{{ $event['event_url'] }}" class="wp-ss-list-event">{{ __('Service order') }}</a>
                                <span class="wp-ss-date-meta">{{ __('Week :number', ['number' => $event['event_week']]) }}</span>
                            </div>
                            <ul class="wp-ss-list">
                                @foreach ($eventItems as $item)
                                    <li class="wp-ss-list-item" wire:key="staffing-activity-{{ $item['focus'] }}">
                                        <div class="wp-ss-list-body">
                                            <div class="wp-ss-list-role">{{ $item['column'] }}</div>
                                            @include('filament.tables.spreadsheet-cell', [
                                                'taskId' => $item['task_id'],
                                                'taskIds' => $item['task_ids'],
                                                'html' => $item['html'],
                                                'vacant' => $item['vacant'],
                                                'conflict' => $item['conflict'],
                                                'state' => $item['state'],
                                                'focusCell' => $item['focus'],
                                            ])
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        </x-mobile-disclosure>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
    <div class="wp-ss-grid-panel" x-on:resize.window="updatePins()" x-on:planner-layout-changed.window="updatePins()" x-data="{
        resizeObserver: null,
        mutationObserver: null,
        frame: null,
        updatePins() {
            cancelAnimationFrame(this.frame);
            this.frame = requestAnimationFrame(() => {
                const content = this.$el.querySelector('.fi-ta-content-ctn');
                const footer = this.$el.querySelector('.fi-pagination');
                if (content && this.$el.getBoundingClientRect().width > 0) {
                    const bottomSpace = (document.querySelector('.wp-tabbar')?.getBoundingClientRect().height ?? 0) + 16;
                    const available = Math.max(160, window.innerHeight - content.getBoundingClientRect().top - (footer?.getBoundingClientRect().height ?? 0) - bottomSpace);
                    const height = Math.floor(available) + 'px';
                    if (content.style.getPropertyValue('--wp-ss-available-height') !== height) {
                        content.style.setProperty('--wp-ss-available-height', height);
                    }
                }
                let offset = 0;
                this.$el.querySelectorAll('thead tr:last-child th.wp-ss-pin').forEach(header => {
                    const pinClass = [...header.classList].find(name => name.startsWith('wp-ss-pin-'));
                    if (!pinClass) return;
                    this.$el.querySelectorAll('.' + pinClass).forEach(cell => cell.style.setProperty('--wp-ss-pin-offset', offset + 'px'));
                    offset += header.getBoundingClientRect().width;
                });
            });
        },
        init() {
            this.resizeObserver = new ResizeObserver(() => this.updatePins());
            this.resizeObserver.observe(this.$el);
            this.mutationObserver = new MutationObserver(() => this.updatePins());
            this.mutationObserver.observe(this.$el, { childList: true, subtree: true });
            this.$nextTick(() => this.updatePins());
        },
        destroy() {
            this.resizeObserver?.disconnect();
            this.mutationObserver?.disconnect();
            cancelAnimationFrame(this.frame);
        }
    }">
        {{ $this->table }}
    </div>
</div>
