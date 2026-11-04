<?php

use App\Http\Controllers\AssignmentResponseController;
use App\Http\Controllers\ChurchAccessRequestController;
use App\Http\Controllers\ChurchExportDownloadController;
use App\Http\Controllers\EventPdfController;
use App\Http\Controllers\PersonalIcsController;
use App\Http\Controllers\StaffingSpreadsheetController;
use App\Http\Controllers\TelegramWebhookController;
use App\Http\Middleware\SetCurrentChurch;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::get('/partner', [ChurchAccessRequestController::class, 'create'])
    ->name('partner.create');

Route::post('/partner', [ChurchAccessRequestController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('partner.store');

Route::middleware(['auth', SetCurrentChurch::class])
    ->get('/events/{event}/pdf', EventPdfController::class)
    ->name('events.pdf');

Route::middleware(['auth', SetCurrentChurch::class])
    ->get('/my-schedule.ics', PersonalIcsController::class)
    ->name('schedule.ics');

Route::middleware(['auth', SetCurrentChurch::class])
    ->get('/staffing.csv', StaffingSpreadsheetController::class)
    ->name('schedule.staffing-csv');

Route::middleware(['auth', 'signed', SetCurrentChurch::class])
    ->get('/church-export/{export}', ChurchExportDownloadController::class)
    ->name('church-export.download');

Route::middleware(['signed', 'throttle:30,1'])
    ->get('/assignments/{task}/respond/{user}', [AssignmentResponseController::class, 'show'])
    ->name('assignments.respond.show');

Route::middleware(['signed', 'throttle:10,1'])
    ->post('/assignments/{task}/respond/{user}', [AssignmentResponseController::class, 'store'])
    ->name('assignments.respond.store');

Route::middleware('throttle:30,1')
    ->get('/r/{token}', [AssignmentResponseController::class, 'showByToken'])
    ->name('assignments.quick.show');

Route::middleware('throttle:30,1')
    ->get('/r/{token}/{answer}', [AssignmentResponseController::class, 'answerByToken'])
    ->whereIn('answer', ['yes', 'no'])
    ->name('assignments.quick.answer');

Route::middleware('throttle:10,1')
    ->post('/r/{token}', [AssignmentResponseController::class, 'storeByToken'])
    ->name('assignments.quick.store');

Route::middleware('throttle:600,1')
    ->post('/telegram/webhook/{church}', TelegramWebhookController::class)
    ->whereNumber('church')
    ->name('telegram.webhook');
