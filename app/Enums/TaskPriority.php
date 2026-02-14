<?php

namespace App\Enums;

enum TaskPriority: string
{
    case High = 'high';
    case Normal = 'normal';
    case Low = 'low';
}
