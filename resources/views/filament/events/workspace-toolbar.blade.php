<div class="wp-workspace-toolbar" @if ($isOrder) x-data="serviceOrderDrag(@js([
    'draggable' => $this->getTable()->isReorderable(),
    'markerKey' => \App\Filament\Resources\Events\RelationManagers\TasksRelationManager::START_MARKER_KEY,
    'startLabel' => __('Start :time', ['time' => $startTime ?? '']),
    'beforeLabel' => __('Before the start · timed backwards'),
    'afterLabel' => __('After the start'),
    'handleLabel' => __('Drag the start time'),
]))" @endif>
    <div class="wp-workspace-tabs" role="group" aria-label="{{ __('Service workspace') }}">
        @foreach (['0' => __('Order'), '1' => __('People')] as $key => $label)
            <button type="button"
                wire:click="$dispatch('switch-service-tab', { tab: '{{ $key }}' })"
                aria-pressed="{{ ($isOrder ? '0' : '1') === (string) $key ? 'true' : 'false' }}"
                @class(['wp-ss-control', 'wp-workspace-tab-active' => ($isOrder ? '0' : '1') === (string) $key])>
                {{ $label }}
            </button>
        @endforeach
    </div>
    <div class="wp-workspace-actions">
        @foreach ($this->getTable()->getHeaderActions() as $action)
            @if ($action->isVisible() && ($action instanceof \Filament\Actions\ActionGroup || $action->isAuthorized()))
                {{ $action }}
            @endif
        @endforeach
    </div>
</div>
