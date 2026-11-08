<?php

declare(strict_types=1);

namespace App\Cli;

final class Help
{
    public const COMMANDS = ['serve', 'token', 'network', 'autostart', 'help'];

    public static function general(): string
    {
        return <<<'TXT'
PHPorto

Uso:
  php phporto <comando> [opções]

Comandos:
  serve    Sobe o painel em 127.0.0.1, na porta do .env.
  token    Gera o token de acesso e grava no .env.
  network  Liga, desliga e mostra o acesso pela rede privada (on, off, status).
  autostart Liga, desliga e mostra o início com o Windows (on, off, status).
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

Sobe o painel em primeiro plano, em 127.0.0.1, na porta de PHPORTO_PORT
(padrão 4001). Pode ser chamado de qualquer pasta. Ctrl+C encerra tudo.

Se a rede estiver ligada (php phporto network on) e tudo conferir, sobe um
segundo listener no IP privado escolhido, na mesma porta. São dois processos.
Se a rede estiver ligada mas algo não conferir (IP que sumiu, perfil diferente
de Private, regra de firewall ausente), nenhum listener de rede sobe, o motivo
aparece aqui e vai para o histórico, e o loopback continua. Nunca escuta em 0.0.0.0.

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
            'network' => <<<'TXT'
Uso:
  php phporto network on
  php phporto network off
  php phporto network status

on      Pergunta o IP de escuta (IPv4 privado de uma interface desta máquina),
        a porta e os IPs ou faixas liberados. Mostra o risco do HTTP sem TLS e
        exige digitar "sim". Grava o estado e cria a regra de firewall (UAC):
        entrada TCP só no perfil Private, no IP e na porta escolhidos, só para
        os IPs liberados. Se o serve já estiver rodando, reinicie-o.
off     Desliga, remove a regra de firewall (UAC) e derruba o listener de rede
        do serve. Pode ser repetido sem erro. Não mexe no início com o Windows.
status  Só leitura: estado, se o IP ainda é desta máquina, perfil da rede,
        regra de firewall e se o listener responde.

Não há flag para pular a confirmação do on.

TXT,
            'autostart' => <<<'TXT'
Uso:
  php phporto autostart on
  php phporto autostart off
  php phporto autostart status

on      Cria a tarefa PHPorto no Agendador de Tarefas (UAC): dispara na inicialização do
        Windows, na conta do próprio usuário, sem precisar de sessão aberta (logon S4U),
        sem nível elevado, e roda php.exe <raiz>\phporto serve. Reinicia em falha e não
        deixa uma segunda instância. Nenhuma senha é lida, recebida ou guardada.
        Não liga a rede privada. Repetir não muda nada.
off     Remove a tarefa (UAC). Pode ser repetido sem erro. Não derruba o servidor que
        estiver rodando e não mexe na rede privada.
status  Só leitura: o estado guardado, a tarefa do Agendador e se o servidor responde.

TXT,
            'help' => <<<'TXT'
Uso:
  php phporto help [comando]

TXT,
            default => self::general(),
        };
    }
}
