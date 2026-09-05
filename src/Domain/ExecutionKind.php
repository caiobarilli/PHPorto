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
