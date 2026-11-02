<?php

namespace App\Http\Controllers;

use App\Core\Modules\ChurchModuleManager;
use App\Models\Church;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Messaging\Services\TelegramUpdates;
use Modules\Messaging\Support\MessagingAvailability;

/**
 * Receives updates for a church's Telegram bot. Telegram sends back the secret given to setWebhook
 * in the X-Telegram-Bot-Api-Secret-Token header, so other callers are refused.
 */
class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, Church $church, ChurchModuleManager $modules, TelegramUpdates $updates): Response
    {
        $secret = (string) ($modules->settings($church, MessagingAvailability::MODULE_TELEGRAM)['webhook_secret'] ?? '');
        $given = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');

        abort_unless(
            $secret !== '' && hash_equals($secret, $given) && $modules->isEnabled($church, MessagingAvailability::MODULE_TELEGRAM),
            403,
        );

        $updates->handle($church, $request->json()->all());

        return response()->noContent(200);
    }
}
