<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Execution;
use App\Domain\ExecutionKind;
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
     * $kind NULO SIGNIFICA TODOS, e é o padrão de propósito: quem já chamava
     * recent($limit) não muda de comportamento ao ganhar o parâmetro.
     *
     * O FILTRO É AQUI, e não na tela, porque filtrar depois de ler mente em
     * silêncio: pedir as 100 mais recentes e descartar as de outro tipo pode
     * devolver lista vazia existindo registro no banco, e nada na tela
     * explicaria por quê. Cada tela mostra a última execução DELA, e é o
     * banco que sabe qual é.
     *
     * @return list<Execution>
     *
     * @throws StorageException em falha de consulta.
     */
    public function recent(int $limit = 100, ?ExecutionKind $kind = null): array;

    /**
     * Apaga registros e devolve quantos foram apagados.
     *
     * $kind nulo apaga TUDO — o comportamento anterior, preservado como
     * padrão. Com tipo, apaga só aquele tipo: o "limpar" de uma tela não pode
     * levar embora o histórico da outra, que quem clicou não estava olhando.
     *
     * @throws StorageException em falha de escrita.
     */
    public function clear(?ExecutionKind $kind = null): int;
}
