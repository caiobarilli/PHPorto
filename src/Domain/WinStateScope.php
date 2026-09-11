<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * O que uma linha de estado do Windows está guardando.
 *
 * DUAS COISAS NA MESMA TABELA, e não duas tabelas, porque são a mesma forma —
 * um par (ação, conteúdo) que se sobrescreve — e diferem só no que significam.
 * Duas tabelas duplicariam o upsert, o ensureTable e os testes nos três
 * drivers para ganhar uma coluna a menos.
 *
 * A distinção importa porque as consequências de perder cada uma são opostas:
 * perder a seleção faz a pessoa remarcar caixas; perder o aplicado faz a tela
 * oferecer "Aplicar" no que já está aplicado. Por isso a tela lê as duas em
 * separado, e por isso existe enum em vez de string solta.
 */
enum WinStateScope: string
{
    /**
     * O que esta ferramenta aplicou e ainda não reverteu.
     *
     * NÃO É LEITURA DA MÁQUINA, e essa é a fronteira mais importante desta
     * fatia. É memória do que a /win mandou fazer, e serve para dois fins:
     * escolher entre "Aplicar" e "Reverter", e saber COM QUE PARÂMETRO
     * reverter — o Invoke-Tweaks -Undo exige -Preset, e sem esta linha a tela
     * não tem como saber qual preset devolver.
     *
     * Quem mexer na máquina por fora (regedit, o WinUtil original, um
     * PowerShell elevado à mão) deixa esta linha desatualizada, e é aceito: o
     * alternativo é sondar a máquina a cada carregamento de página, que é
     * exatamente o custo que o heartbeat da elevação existe para não pagar.
     */
    case Applied = 'aplicado';

    /**
     * As caixas que a pessoa deixou marcadas na última visita.
     *
     * Conveniência pura: sem isto, a tela nasce desmarcada e quem usa um
     * conjunto próprio de tweaks o remonta a cada visita. Não afirma nada
     * sobre a máquina, então não tem lado perigoso.
     */
    case Selection = 'selecao';

    /**
     * Converte o que veio do banco, recusando o que não reconhece.
     *
     * Devolve NULO em vez de um caso padrão, ao contrário do
     * ExecutionKind::fromStorage() — e a diferença é deliberada. Lá, tipo
     * desconhecido virava 'comando' porque a linha existe e precisa aparecer
     * na tabela de algum jeito. Aqui, escopo desconhecido significa que não se
     * sabe o que aquela linha afirma, e o lado seguro é descartá-la: uma
     * linha de significado incerto tratada como 'aplicado' faria a tela dizer
     * que algo está aplicado sem base.
     */
    public static function fromStorage(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        return is_string($value) ? self::tryFrom($value) : null;
    }
}
