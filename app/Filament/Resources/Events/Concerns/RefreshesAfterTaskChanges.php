<?php

namespace App\Filament\Resources\Events\Concerns;

use App\Filament\Resources\Events\RelationManagers\TasksRelationManager;
use Livewire\Attributes\On;

/**
 * Service pages: the task table is its own Livewire component, so it announces saves and the page
 * reloads the service to redraw the staffing summary, plan state, end time and heading around it.
 */
trait RefreshesAfterTaskChanges
{
    #[On(TasksRelationManager::TASKS_CHANGED_EVENT)]
    public function refreshAfterTaskChanges(): void
    {
        $this->getRecord()->refresh();
    }
}
