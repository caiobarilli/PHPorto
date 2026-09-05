<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Falha ao gravar ou ler o registro de execuções.
 *
 * É a fronteira entre 400 e 500. Cada provider traduz o erro nativo do seu
 * driver (PDOException, exceção do driver do Mongo) para esta classe, então
 * as camadas acima distinguem "a entrada estava errada"
 * (InvalidArgumentException) de "a persistência quebrou" sem conhecer nenhum
 * detalhe do banco.
 *
 * Substituiu a DuplicateEntryException, que veio do domínio anterior e saiu
 * junto com a unicidade: log de execução não tem registro duplicado.
 */
final class StorageException extends RuntimeException
{
}
