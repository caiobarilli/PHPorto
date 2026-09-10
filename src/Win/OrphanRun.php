<?php

declare(strict_types=1);

namespace App\Win;

/**
 * Uma execução que terminou no PowerShell elevado e nunca virou linha no banco.
 *
 * POR QUE ISSO EXISTE: a espera da tela é síncrona, e a linha é gravada pela
 * requisição que espera. Se essa requisição morrer — servidor parado, aba
 * fechada, processo do `php -S` encerrado no meio —, o trabalho terminou do
 * outro lado e o registro não aconteceu. Medido, e não hipótese: o
 * `files/win-worker.log` tem duas execuções de `tweaks` que mexeram no
 * registro e nos serviços do Windows e não existem no histórico.
 *
 * NÃO É UM PsResult, e a diferença é o que justifica a classe. O PsResult é o
 * que a requisição que ESPEROU recebe: ela já sabe qual ação pediu, então o
 * resultado não precisa dizer. Quem recolhe depois não sabe de nada, e precisa
 * de três coisas a mais para conseguir gravar uma linha honesta:
 *
 *   $acao e $params  — para montar o rótulo. Sem eles, a linha diria que algo
 *                      aconteceu sem dizer o quê.
 *   $finishedAt      — a hora de FIM, não a do recolhimento. A listagem ordena
 *                      por created_at, então a hora errada não seria um detalhe
 *                      de texto: poria a execução de ontem no topo do histórico
 *                      de hoje, e a mentira cresceria quanto mais tarde alguém
 *                      abrisse a tela.
 *
 * Os três vêm do `win-done-<id>.json`, que o worker passou a carregar
 * justamente por isso.
 */
final readonly class OrphanRun
{
    /**
     * @param string                         $id         id do trabalho, para apagar o par depois de gravar
     * @param string                         $acao       nome da ação, ou '' quando o worker recusou o job antes de validá-lo
     * @param array<string, string|int|bool> $params     parâmetros já validados pela allowlist do worker
     * @param string|null                    $finishedAt instante de fim em UTC, 'Y-m-d H:i:s', ou null se o arquivo não trouxe
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
