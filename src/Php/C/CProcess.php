<?php

declare(strict_types=1);

namespace App\Php\C;

use App\Win\PsResult;
use App\Win\PsTerminalProcess;

final class CProcess implements CCompiler
{
    public function __construct(
        private readonly string $filesDir,
        private readonly string $php = PHP_BINARY,
        private readonly int $timeoutSeconds = CCode::TIMEOUT_SECONDS,
    ) {
    }

    public function run(string $code, TccStatus $tcc): PsResult
    {
        $arquivo = $this->filesDir . DIRECTORY_SEPARATOR . 'c-' . bin2hex(random_bytes(6)) . '.c';

        if (@file_put_contents($arquivo, $code) !== strlen($code)) {
            @unlink($arquivo);

            return new PsResult("[phporto] sem como gravar o código em {$this->filesDir}\n", null, false, 0);
        }

        try {
            return (new PsTerminalProcess($this->filesDir, $this->timeoutSeconds, CCode::MAX_OUTPUT_BYTES, workDir: $this->filesDir))
                ->runArgv(CCode::arguments($this->php, $tcc, $arquivo));
        } finally {
            @unlink($arquivo);
        }
    }
}
