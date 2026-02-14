<?php

namespace App\Enums;

enum TaskOrigin: string
{
    case Webhook = 'webhook';
    case Conversation = 'conversation';
}
