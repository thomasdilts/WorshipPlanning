<?php

namespace App\Services;

use App\Models\Event;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class EventPdfExporter
{
    public function __construct(
        protected EventTaskRowSummary $summary,
        protected AssignmentStateResolver $states,
    ) {}

    public function stream(Event $event): Response
    {
        $event->load([
            'church',
            'tasks.template',
            'tasks.assignedUser',
            'tasks.assignedTeam',
            'tasks.sharedFiles',
            'tasks.pictures',
            'tasks.catalogSong',
            'tasks.responses',
        ]);

        $filename = Str::slug($event->name).'-'.$event->starts_at->format('Y-m-d').'.pdf';

        return Pdf::loadView('pdf.event', [
            'event' => $event,
            'summary' => $this->summary,
            'states' => $this->states->forMany($event->tasks),
            'logoPath' => $event->church?->logoAbsolutePath(),
        ])
            ->setPaper('a4', 'landscape')
            ->stream($filename);
    }
}
