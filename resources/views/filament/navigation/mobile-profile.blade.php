<ul class="wp-mobile-only fi-sidebar-nav-groups">
    <x-filament-panels::sidebar.item
        :active="request()->routeIs(\App\Filament\Pages\EditProfile::getRouteName())"
        icon="heroicon-o-user-circle"
        :label="__('My profile')"
        :url="filament()->getProfileUrl()"
    />
</ul>
