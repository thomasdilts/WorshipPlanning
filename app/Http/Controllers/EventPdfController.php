<?php

namespace App\Http\Controllers;

use App\Core\Tenancy\CurrentChurch;
use App\Models\Event;
use App\Services\EventPdfExporter;
use Illuminate\Http\Response;

class EventPdfController extends Controller
{
    public function __invoke(Event $event, EventPdfExporter $exporter): Response
    {
        $user = auth()->user();
        abort_unless($user !== null, 403);

        if (! $user->isPlatformAdmin()) {
            abort_unless(
                $user->church_id !== null && (int) $user->church_id === (int) $event->church_id,
                404
            );
        }

        app(CurrentChurch::class)->set($event->church);

        abort_unless(
            $user->can('events.view') || $user->isPlatformAdmin(),
            403
        );

        return $exporter->stream($event);
    }
}
