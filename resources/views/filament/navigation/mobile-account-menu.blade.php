{{-- The trigger shows "My profile" instead of the user's name, via the --wp-account-label custom property. --}}
<div class="wp-mobile-only wp-mobile-account-menu" style="--wp-account-label: @js(__('My profile'))">
    <x-filament-panels::user-menu :position="\Filament\Enums\UserMenuPosition::Sidebar" />
</div>
