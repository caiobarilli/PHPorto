<?php

declare(strict_types=1);

namespace App\Php\C;

use App\Win\PsResult;

interface CCompiler
{
    public function run(string $code, TccStatus $tcc): PsResult;
}
