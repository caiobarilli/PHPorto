<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * O que originou uma execução.
 *
 * Existe como enum, e não como string solta, porque o valor atravessa três
 * drivers de banco e volta: com enum, um valor desconhecido vindo do banco
 * falha na leitura em vez de virar uma linha silenciosamente errada na tela.
 */
enum ExecutionKind: string
{
    case Comando = 'comando';
    case Anexo = 'anexo';

    /**
     * Execução do lado Windows: uma ação de src/Win/actions, num PowerShell elevado.
     *
     * Mora na MESMA tabela dos outros dois: um comando no WSL e uma ação no
     * Windows são o mesmo fato — algo foi executado nesta máquina, com saída,
     * código de saída e duração. Duas tabelas obrigariam a tela a decidir de
     * qual ler antes de saber o que aconteceu.
     *
     * O caso novo não invalida registro antigo: fromStorage() já tolerava
     * valor desconhecido, e nenhum registro gravado antes deste caso existir
     * carrega este valor.
     */
    case Windows = 'windows';

    /** Os tipos da tela /wsl: tudo que não é do Windows. */
    public const WSL = [self::Comando, self::Anexo];

    /**
     * Converte o que veio do banco, tolerando ausência.
     *
     * Registro gravado por versão anterior pode não ter o campo; nesse caso
     * é comando, que era o único tipo antes do card de anexos.
     */
    public static function fromStorage(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        return is_string($value) ? (self::tryFrom($value) ?? self::Comando) : self::Comando;
    }
}
