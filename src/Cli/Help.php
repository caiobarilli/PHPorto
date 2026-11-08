<?php

declare(strict_types=1);

namespace App\Cli;

final class Help
{
    public const COMMANDS = ['serve', 'token', 'help'];

    public static function general(): string
    {
        return <<<'TXT'
PHPorto

Uso:
  php phporto <comando> [opções]

Comandos:
  serve    Sobe o painel em 127.0.0.1, na porta do .env.
  token    Gera o token de acesso e grava no .env.
  help     Mostra esta ajuda.

Use "php phporto <comando> --help" para os detalhes de um comando.

Códigos de saída: 0 ok, 1 falha, 64 uso inválido, 78 configuração inválida.

TXT;
    }

    public static function for(string $command): string
    {
        return match ($command) {
            'serve' => <<<'TXT'
Uso:
  php phporto serve

Sobe o painel em primeiro plano, só em 127.0.0.1, na porta de PHPORTO_PORT
(padrão 4001). Pode ser chamado de qualquer pasta. Ctrl+C encerra o servidor.

Substitui: php -S 127.0.0.1:4001 -t public public/router.php

Exige um .env válido na raiz do projeto (saída 78 se faltar ou estiver inválido).

TXT,
            'token' => <<<'TXT'
Uso:
  php phporto token

Gera 32 bytes aleatórios em hexadecimal, grava em PHPORTO_AUTH_TOKEN no .env e
imprime o token. Se já existir um, pergunta [s/N] antes de trocar: trocar
derruba o acesso de quem usa o token atual.

TXT,
            'help' => <<<'TXT'
Uso:
  php phporto help [comando]

TXT,
            default => self::general(),
        };
    }
}
