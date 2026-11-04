<?php

namespace App\Http\Controllers;

use App\Filament\Pages\ExportChurchData;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves a finished church export once, as a normal file download, then deletes it. The link is
 * signed and short-lived, and only works for someone who may export the church the file belongs to.
 */
class ChurchExportDownloadController extends Controller
{
    public function __invoke(string $export): BinaryFileResponse
    {
        abort_unless(ExportChurchData::canAccess(), 403);

        $churchId = (int) auth()->user()->church_id;
        abort_unless(Str::startsWith($export, "{$churchId}-") && Str::isUuid(Str::after($export, "{$churchId}-")), 404);

        $path = ExportChurchData::exportDirectory()."/{$export}.zip";
        abort_unless(is_file($path), 404);

        $filename = Str::slug((string) auth()->user()->church->name).'-'.now()->format('Y-m-d').'.zip';

        return response()->download($path, $filename)->deleteFileAfterSend();
    }
}
