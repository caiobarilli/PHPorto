<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Lançada quando o valor do registro já existe na base.
 *
 * É uma exceção própria da aplicação: cada provider traduz o erro nativo
 * do seu driver (ex.: duplicate key 11000 do Mongo, violação de unique do
 * MySQL) para esta classe. Assim, as camadas superiores (EntryService,
 * index.php) tratam duplicidade sem conhecer nenhum detalhe do driver.
 */
final class DuplicateEntryException extends RuntimeException
{
}
