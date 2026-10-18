<?php

declare(strict_types=1);

namespace App\Win;

final readonly class OrphanRun
{
    /**
     * @param string $id
     * @param string $acao
     * @param array<string, string|int|bool> $params
     * @param string|null $finishedAt
     */
    public function __construct(
        public string $id,
        public string $acao,
        public array $params,
        public PsResult $result,
        public ?string $finishedAt,
    ) {
    }
}
