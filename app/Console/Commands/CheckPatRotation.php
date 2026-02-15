<?php

namespace App\Console\Commands;

use App\Services\BotPatRotationService;
use Illuminate\Console\Command;

class CheckPatRotation extends Command
{
    protected $signature = 'security:check-pat-rotation';

    protected $description = 'Check bot PAT age and send rotation reminder if approaching expiry (D144)';

    public function handle(BotPatRotationService $service): int
    {
        $alert = $service->evaluate();

        if ($alert) {
            $this->info("PAT rotation alert created: {$alert->message}");
        } else {
            $this->info('No PAT rotation alert needed.');
        }

        return self::SUCCESS;
    }
}
