@if (count($tabs) > 0)
    <nav class="wp-tabbar" aria-label="{{ __('Main navigation') }}">
        @foreach ($tabs as $tab)
            <a
                href="{{ $tab->getUrl() }}"
                class="wp-tabbar-item"
                @if ($tab->isActive()) aria-current="page" @endif
            >
                @if ($icon = $tab->getIcon())
                    <x-filament::icon :icon="$icon" aria-hidden="true" />
                @endif
                <span>{{ $tab->getLabel() }}</span>
            </a>
        @endforeach

        <button
            type="button"
            class="wp-tabbar-item"
            aria-controls="fi-main-sidebar"
            x-data
            x-bind:aria-expanded="$store.sidebar.isOpen"
            x-on:click="
                document.querySelectorAll('#fi-main-sidebar .fi-sidebar-group.fi-collapsible[data-group-label]').forEach(group => {
                    $store.sidebar.collapseGroup(group.dataset.groupLabel);
                });
                $store.sidebar.open();
            "
        >
            <x-filament::icon icon="heroicon-o-bars-3" aria-hidden="true" />
            <span>{{ __('More') }}</span>
        </button>
    </nav>
@endif
