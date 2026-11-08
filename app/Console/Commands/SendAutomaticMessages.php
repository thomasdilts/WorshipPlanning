<?php

namespace App\Console\Commands;

use App\Services\AutomaticMessageDispatcher;
use Illuminate\Console\Command;

class SendAutomaticMessages extends Command
{
    protected $signature = 'worship:send-automatic-messages';

    protected $description = 'Send the automatic messages that are due for upcoming services.';

    public function handle(AutomaticMessageDispatcher $dispatcher): int
    {
        $sentFor = $dispatcher->sendDue();

        $this->components->info("Automatic messages sent for {$sentFor} service(s).");

        return self::SUCCESS;
    }
}
