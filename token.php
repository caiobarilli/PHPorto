<?php

declare(strict_types=1);

use App\Cli\Console;
use App\Cli\Main;

require __DIR__ . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

exit((new Main(__DIR__, Console::standard()))->run(['token']));
