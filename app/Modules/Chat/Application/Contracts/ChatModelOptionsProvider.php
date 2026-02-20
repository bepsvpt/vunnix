<?php

namespace App\Modules\Chat\Application\Contracts;

interface ChatModelOptionsProvider
{
    public function provider(): string;

    public function model(): string;
}
