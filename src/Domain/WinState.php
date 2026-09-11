<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Uma linha de estado da tela /win: o que foi aplicado, ou o que ficou marcado.
 *
 * POR QUE ISTO NÃO É UMA EXECUÇÃO. A tabela de execuções guarda FATOS
 * datados — "a ação tweaks rodou às 14h, saiu 0, demorou 8 s" — e cresce para
 * sempre. Esta guarda a SITUAÇÃO ATUAL, uma linha por (escopo, ação), que se
 * sobrescreve. São perguntas diferentes: "o que aconteceu aqui?" contra "como
 * está agora?".
 *
 * E NÃO SE DERIVA UMA DA OUTRA, que era o caminho tentador: bastaria varrer o
 * histórico procurando o último tweaks sem -Undo. Não serve, por três motivos
 * medidos:
 *
 *   O botão "limpar histórico do Windows" apaga os registros — clear(Windows),
 *   que a /win já oferece. Derivando, limpar o histórico passaria a MUDAR o
 *   que a tela afirma sobre a máquina, e um botão que diz "apagar registros"
 *   não deveria ter esse poder.
 *
 *   O rótulo gravado é texto para pessoa ler ("tweaks -Preset standard"),
 *   montado pelo describe(). Derivar estado exigiria voltar a parseá-lo, e um
 *   rótulo é justamente o que se pode reescrever sem aviso — d57d2a1 já
 *   reescreveu esse formato uma vez, e o histórico tem as duas formas.
 *
 *   Exit 0 não prova que aplicou. Medido no Invoke-Tweaks: ele captura o erro
 *   de CADA item, escreve ERROR e segue, e ainda termina com OK; preset
 *   inexistente também sai 0. Quem deriva do histórico lê os dois como
 *   sucesso.
 *
 * TAMBÉM NÃO É O PAR win-out/win-done do recolhimento de órfãos, e a
 * verificação estava pedida: aquele par é FILA, não estado. Ele existe entre o
 * fim do trabalho e a gravação da linha, e gravar é o mesmo ato que apagá-lo —
 * viveu segundos, ou até o próximo carregamento da tela. Esta linha nasce
 * depois de a execução estar registrada e sobrevive a reinício, que é o
 * contrário. As duas se encontram num ponto só: o recolhimento também aplica a
 * transição, porque a execução que ele recolhe aconteceu de verdade.
 */
final readonly class WinState
{
    /**
     * @param string                          $action  nome da ação (o valor do WinAction), guardado como
     *                                                 STRING e não como enum: o Domain não conhece o motor
     *                                                 do Windows, e linha de uma ação que deixou de existir
     *                                                 tem de ler como "sem informação", não estourar
     * @param array<string, string|int|bool>  $payload o que a ação precisa de volta — para o tweaks, o
     *                                                 preset que o -Undo exige; para a seleção, os itens
     * @param string|null                     $updatedAt quando foi gravada, em ISO 8601, ou nulo antes de gravar
     */
    public function __construct(
        public WinStateScope $scope,
        public string $action,
        public array $payload = [],
        public ?string $updatedAt = null,
    ) {
    }

    /**
     * A chave desta linha, no formato que o Mongo usa como _id.
     *
     * Escopo e ação são os dois campos da chave primária nos providers SQL. No
     * Mongo viram este _id composto em vez de um índice único: o _id já é
     * único e indexado por construção, então a garantia sai de graça, sem
     * depender de um createIndex que pode falhar silenciosamente num banco
     * onde a coleção já existia.
     *
     * O separador é ':' porque nenhum dos dois lados o aceita — escopo é enum
     * fechado, e ação é chave de allowlist, restrita a letras minúsculas.
     */
    public function key(): string
    {
        return $this->scope->value . ':' . $this->action;
    }

    /**
     * O payload como texto, para gravar.
     *
     * O FORMATO MORA AQUI E NÃO EM CADA DRIVER, e é para haver um lugar só
     * quando ele mudar. Os três gravam a MESMA string JSON — inclusive o
     * Mongo, que poderia guardar subdocumento nativo: guardaria, e aí uma
     * chave com ponto ou com '$' seria recusada pelo BSON, fazendo o mesmo
     * payload passar no SQLite e falhar no Mongo. Texto atravessa os três do
     * mesmo jeito.
     *
     * Objeto vazio, não array vazio: `[]` e `{}` voltam diferentes do
     * json_decode, e a ausência de parâmetros tem de voltar como mapa. É o
     * mesmo cuidado que o JobChannel já toma com "params":{} — lá o array
     * vazio expunha Count e Length ao PowerShell e fazia a allowlist recusar
     * ação legítima.
     */
    public function encodedPayload(): string
    {
        if ($this->payload === []) {
            return '{}';
        }

        $json = json_encode($this->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false ? '{}' : $json;
    }

    /**
     * Decodifica um payload lido do banco, ou devolve nulo se não der.
     *
     * NULO É "DESCARTE ESTA LINHA", e quem chama descarta. Payload ilegível
     * significa que não se sabe o que aquela linha afirma, e o lado seguro de
     * não saber é não afirmar nada — a mesma regra do flags.json, onde JSON
     * quebrado vira "pergunte ao .env" em vez de ligar algo.
     *
     * Só objeto: uma lista ou um escalar no lugar do mapa é linha estragada,
     * não payload vazio.
     *
     * @return array<string, string|int|bool>|null
     */
    public static function decodePayload(mixed $raw): ?array
    {
        if (is_array($raw)) {
            // O Mongo devolve documento já decodificado quando a linha foi
            // gravada por uma versão que guardava subdocumento. Tolerar isso
            // custa duas linhas e evita que um banco antigo leia como vazio.
            $raw = json_encode($raw);
        }

        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return null;
        }

        // O array VAZIO escapa do teste de lista, e a ordem aqui foi encontrada
        // por teste que falhou: array_is_list([]) é TRUE, então um payload
        // legítimo de ação sem parâmetro — o '{}' que encodedPayload() grava —
        // era descartado como se fosse lista, e o gdid aplicado sumia da
        // leitura. Vazio é mapa vazio.
        if ($decoded !== [] && array_is_list($decoded)) {
            return null;
        }

        $limpo = [];

        foreach ($decoded as $chave => $valor) {
            // Só os três tipos que a allowlist produz. Qualquer outra coisa —
            // nulo, objeto aninhado, float — não veio desta ferramenta, e
            // repassá-la faria o chamador tratar um tipo que ele não espera.
            if (is_string($chave) && (is_string($valor) || is_int($valor) || is_bool($valor))) {
                $limpo[$chave] = $valor;
            }
        }

        return $limpo;
    }
}
