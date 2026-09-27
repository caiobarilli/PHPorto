<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Execution;
use App\Domain\ExecutionKind;
use App\Domain\WinState;
use App\Domain\WinStateScope;
use App\Exceptions\StorageException;

/**
 * Contrato agnóstico de persistência do registro de execuções.
 *
 * Propositalmente NÃO expõe nada específico de Mongo (sem BSON, sem
 * Collection, sem ObjectId) nem de PDO. As três implementações trocam sem que
 * a camada de serviço mude uma linha — é essa a herança que justifica manter
 * três providers.
 *
 * NÃO HÁ UNICIDADE, e isso é decisão registrada, não esquecimento. A versão
 * anterior deste contrato carregava entryExists() e uma exceção de duplicata,
 * herdadas de um domínio em que o valor precisava ser único. Log de execução
 * não tem isso: duas execuções idênticas são dois registros. Quem reintroduzir
 * um índice UNIQUE aqui vai derrubar o teste que grava o mesmo comando duas
 * vezes e exige dois registros de volta.
 */
interface DatabaseProviderInterface
{
    /**
     * Sufixo do nome da tabela (ou coleção) de estado da /win.
     *
     * NA INTERFACE porque os três drivers derivam o nome da mesma forma, e o
     * sufixo em três arquivos é o começo de três nomes diferentes. Derivar em
     * vez de configurar é decisão: quem renomeou a tabela de execuções ganha
     * esta renomeada junto, sem uma segunda variável de ambiente para manter
     * em dia.
     */
    public const STATE_SUFFIX = '_win_state';

    /**
     * Grava uma execução e devolve o registro COMO FICOU no banco.
     *
     * O retorno não é cortesia: o created_at é atribuído pelo banco, não por
     * quem chama, e sem devolvê-lo quem consome a API precisaria de uma
     * segunda consulta só para saber quando aquilo aconteceu — e essa consulta
     * não teria como distinguir o próprio registro de outro gravado no mesmo
     * instante.
     *
     * @throws StorageException em qualquer falha de persistência.
     */
    public function insert(Execution $execution): Execution;

    /**
     * Execuções mais recentes primeiro.
     *
     * O desempate por identidade decrescente não é detalhe: duas execuções no
     * mesmo segundo têm o mesmo created_at, e sem desempate a ordem entre elas
     * fica a critério do banco.
     *
     * $kinds NULO SIGNIFICA TODOS, e é o padrão de propósito: quem já chamava
     * recent($limit) não muda de comportamento. Lista vazia significa nenhum.
     *
     * O FILTRO É AQUI, e não na tela, porque filtrar depois de ler mente em
     * silêncio: pedir as 100 mais recentes e descartar as de outro tipo pode
     * devolver lista vazia existindo registro no banco, e nada na tela
     * explicaria por quê. Cada tela mostra a última execução DELA, e é o
     * banco que sabe qual é.
     *
     * @param list<ExecutionKind>|null $kinds
     *
     * @return list<Execution>
     *
     * @throws StorageException em falha de consulta.
     */
    public function recent(int $limit = 100, ?array $kinds = null): array;

    /**
     * Apaga registros e devolve quantos foram apagados.
     *
     * $kinds nulo apaga TUDO — o comportamento anterior, preservado como
     * padrão. Com lista, apaga só aqueles tipos, e lista vazia não apaga nada:
     * o "limpar" de uma tela não pode levar embora o histórico da outra, que
     * quem clicou não estava olhando.
     *
     * @param list<ExecutionKind>|null $kinds
     *
     * NÃO TOCA NO ESTADO DA /win, e há teste exigindo isso. Apagar registros e
     * mudar o que a tela afirma sobre a máquina são coisas diferentes: um
     * botão rotulado "limpar histórico" que fizesse a segunda passaria a
     * oferecer "Aplicar" no que continua aplicado.
     *
     * @throws StorageException em falha de escrita.
     */
    public function clear(?array $kinds = null): int;

    /**
     * Grava (ou sobrescreve) uma linha de estado da /win e devolve como ficou.
     *
     * UPSERT, e não insert: é uma linha por (escopo, ação), que se substitui.
     * Histórico de estado não interessa — quem quer saber o que aconteceu tem
     * a tabela de execuções, que guarda exatamente isso e com data.
     *
     * Devolve o registro com o $updatedAt atribuído pelo banco, pelo mesmo
     * motivo do insert(): quem chama não tem outra forma de sabê-lo sem uma
     * segunda consulta.
     *
     * @throws StorageException em qualquer falha de persistência.
     */
    public function putWinState(WinState $state): WinState;

    /**
     * As linhas de estado da /win de UM escopo, indexadas pelo nome da ação.
     *
     * DEVOLVE O ESCOPO INTEIRO DE UMA VEZ, e não existe getter de uma linha:
     * a tela precisa do estado das treze seções para se desenhar, e um getter
     * por ação viraria treze consultas por carregamento de página. O filtro
     * está na consulta pelo mesmo motivo que o do recent(): filtrar depois de
     * ler mente em silêncio.
     *
     * O ESCOPO É OBRIGATÓRIO, ao contrário do $kinds do recent(), e isso saiu
     * de um teste que falhou. A chave do array é a AÇÃO — é assim que quem lê
     * quer perguntar, "o que há para 'tweaks'?" —, e isso só é inequívoco
     * DENTRO de um escopo: o tweaks aplicado e o tweaks selecionado têm a
     * mesma ação, então uma leitura sem filtro fazia um sobrescrever o outro e
     * devolvia uma linha onde havia duas. O recent() tolera o nulo porque
     * devolve lista; aqui devolver "todos" exigiria outra forma de chave, e
     * duas formas de chave no mesmo método é onde o chamador erra.
     *
     * Linha ilegível é DESCARTADA em vez de derrubar a leitura — escopo
     * desconhecido, payload que não é JSON de objeto. É o mesmo critério do
     * flags.json: sem informação confiável, a tela se comporta como se não
     * houvesse estado, que é o lado que não afirma nada.
     *
     * @return array<string, WinState> indexado pelo nome da ação
     *
     * @throws StorageException em falha de consulta.
     */
    public function winStates(WinStateScope $scope): array;

    /**
     * Esquece uma linha de estado e diz se havia algo para esquecer.
     *
     * É o que uma reversão bem-sucedida faz: não grava "não aplicado", APAGA.
     * Ausência e "não aplicado" têm de significar a mesma coisa, senão passam
     * a existir dois jeitos de dizer o mesmo e a tela precisa tratar os dois.
     *
     * @throws StorageException em falha de escrita.
     */
    public function forgetWinState(WinStateScope $scope, string $action): int;
}
