<?php

namespace App\Modules\Chat\Application\Contracts;

use App\Agents\VunnixAgent;

interface ChatPromptProvider
{
    public function build(VunnixAgent $agent): string;
}
