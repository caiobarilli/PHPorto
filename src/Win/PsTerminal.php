<?php

declare(strict_types=1);

namespace App\Win;

interface PsTerminal
{
    public function run(string $command): PsResult;
}
