<?php

declare(strict_types=1);

namespace App\Cli;

final class Status
{
    public const OK = 0;

    public const FAILURE = 1;

    public const USAGE = 64;

    public const CONFIG = 78;
}
