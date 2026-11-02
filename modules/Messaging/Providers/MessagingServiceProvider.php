<?php

namespace Modules\Messaging\Providers;

use App\Core\Modules\ChurchModuleManager;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleRegistry;
use App\Core\Permissions\PermissionRegistry;
use Illuminate\Support\ServiceProvider;
use Modules\Messaging\Channels\EmailChannel;
use Modules\Messaging\Channels\NtfyChannel;
use Modules\Messaging\Channels\SmsChannel;
use Modules\Messaging\Channels\TelegramChannel;
use Modules\Messaging\Channels\WhatsAppChannel;
use Modules\Messaging\Contracts\MessageChannel;
use Modules\Messaging\Services\MessageSender;
use Modules\Messaging\Support\MessagingAvailability;

class MessagingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MessagingAvailability::class, function ($app) {
            return new MessagingAvailability($app->make(ChurchModuleManager::class));
        });

        $this->app->tag([
            EmailChannel::class,
            SmsChannel::class,
            WhatsAppChannel::class,
            NtfyChannel::class,
            TelegramChannel::class,
        ], 'messaging.channels');

        $this->app->singleton(MessageSender::class, function ($app) {
            /** @var iterable<MessageChannel> $channels */
            $channels = $app->tagged('messaging.channels');

            return new MessageSender($channels, $app->make(ChurchModuleManager::class));
        });
    }

    public function boot(ModuleRegistry $modules, PermissionRegistry $permissions): void
    {
        $modules->register(new ModuleDefinition(
            key: MessagingAvailability::MODULE_EMAIL,
            name: 'Messaging (email)',
            description: 'Send invitations and messages by email from this church’s own mailbox.',
            settings: [
                'email.host' => 'string',
                'email.port' => 'int',
                'email.from_address' => 'string',
            ],
            required: false,
        ));

        $modules->register(new ModuleDefinition(
            key: MessagingAvailability::MODULE_SMS,
            name: 'Messaging SMS via iP.1',
            description: 'Send SMS invitations (iP.1). Mainly used in Sweden; check iP.1 for pricing.',
            settings: [
                'api_token' => 'string',
                'sender' => 'string',
            ],
            required: false,
        ));

        $modules->register(new ModuleDefinition(
            key: MessagingAvailability::MODULE_WHATSAPP,
            name: 'Messaging via WhatsApp',
            description: 'Send WhatsApp invitations via Meta. Good for international use; Meta charges for messages.',
            settings: [
                'access_token' => 'string',
                'phone_number_id' => 'string',
            ],
            required: false,
        ));

        $modules->register(new ModuleDefinition(
            key: MessagingAvailability::MODULE_NTFY,
            name: 'Messaging via ntfy',
            description: 'Send invitations as free push notifications through the ntfy app. Each person subscribes to their own topic.',
            settings: [
                'server_url' => 'string',
            ],
            required: false,
        ));

        $modules->register(new ModuleDefinition(
            key: MessagingAvailability::MODULE_TELEGRAM,
            name: 'Messaging via Telegram',
            description: 'Send invitations as free Telegram messages from the church’s own bot. Each person connects once from their profile.',
            settings: [
                'bot_token' => 'string',
            ],
            required: false,
        ));

        $permissions->register('messaging', [
            'messaging.view',
            'messaging.templates.manage',
            'messaging.settings.manage',
            'messaging.send',
        ]);
    }
}
