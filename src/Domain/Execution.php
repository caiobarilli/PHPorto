<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Uma execução registrada: o que foi pedido, o que saiu, e como terminou.
 *
 * É o que se persiste. Não há unicidade: rodar o mesmo comando duas vezes são
 * dois fatos distintos, cada um com sua saída, sua duração e seu horário — e a
 * ferramenta existe justamente para registrar que algo foi feito duas vezes.
 *
 * Dois campos merecem explicação:
 *
 * $durationMs é inteiro, em milissegundos, e não float em segundos. Float
 * atravessa os três drivers de jeitos diferentes (REAL no SQLite, DOUBLE no
 * MySQL, double BSON no Mongo) e volta com ruído de arredondamento; inteiro é
 * o mesmo valor nos três. A tela divide por mil na hora de mostrar.
 *
 * $timedOut é campo próprio, e não dedução a partir de $exitCode. Timeout
 * deixa o código de saída nulo — mas comando que nem chegou a iniciar também
 * deixa. Sem o campo, os dois casos viram a mesma linha na tabela.
 */
final readonly class Execution
{
    public function __construct(
        public string $command,
        public string $output,
        public ?int $exitCode,
        public int $durationMs,
        public ExecutionKind $kind,
        public bool $timedOut,
        public ?string $createdAt = null,
    ) {
    }

    /** Duração em segundos, para exibição. */
    public function durationSeconds(): float
    {
        return $this->durationMs / 1000;
    }
}
