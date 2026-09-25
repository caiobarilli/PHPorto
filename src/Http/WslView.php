<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Execution;

/**
 * A tela /wsl: entrada, saída, anexos e a tabela de registros.
 *
 * $coldStartSeconds não é enfeite. Acordar a VM do WSL custa cerca de 4,5 s
 * (medido: 4543 ms com a VM fria, 88 ms depois), e isso acontece toda manhã.
 * Quatro segundos e meio de tela parada fazem a pessoa clicar de novo — então
 * o número aparece no estado "executando", para ela saber que a espera é
 * esperada em vez de achar que o clique não pegou.
 */
final readonly class WslView
{
    /**
     * @param list<Execution> $rows        registros, mais recentes primeiro
     * @param ?Execution      $result      execução desta requisição, se houve
     * @param ?string         $blocked     motivo que impede executar, se houver
     * @param ?string         $notice      recado de ação que não executou nada
     * @param int             $maxOutputBytes  teto de saída de uma execução
     * @param int             $maxCommandBytes teto do texto de um comando
     * @param int             $maxPathBytes    teto de cada caminho de anexo
     */
    public function __construct(
        public array $rows,
        public ?Execution $result,
        public ?string $blocked,
        public ?string $notice,
        public string $csrfToken,
        public string $csrfField,
        public string $distro,
        public string $root,
        public int $timeout,
        public string $tz,
        public int $coldStartSeconds,
        public int $maxOutputBytes,
        public int $maxCommandBytes,
        public int $maxPathBytes,
    ) {
    }
}
