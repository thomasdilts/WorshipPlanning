<?php

namespace App\Filament\Resources\Events\Concerns;

use Livewire\Attributes\Url;

/**
 * Opens the task a link points at (?task=ID, e.g. from a decline message) when the service page loads:
 * its edit form for people who can edit the service, else its read-only view.
 */
trait OpensLinkedTask
{
    #[Url(as: 'task')]
    public ?string $linkedTaskId = null;

    /**
     * Runs after the table is built; the id is cleared, so later requests and reloads skip this.
     */
    public function bootedOpensLinkedTask(): void
    {
        $taskId = $this->linkedTaskId;

        if (blank($taskId)) {
            return;
        }

        $this->linkedTaskId = null;

        if (! ctype_digit($taskId) || ! $this->getTable()->getQuery()->whereKey((int) $taskId)->exists()) {
            return;
        }

        $canEdit = ! $this->isReadOnly() && (auth()->user()?->can('update', $this->getOwnerRecord()) ?? false);

        $this->mountAction($canEdit ? 'edit' : 'view', context: ['table' => true, 'recordKey' => $taskId]);

        // Render the whole table with its open modal, not only the modal as a partial update.
        $this->forceRender();
    }
}
