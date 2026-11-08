# Iniciar com o Windows pela tarefa do Agendador

## Contexto

O painel só existia enquanto alguém rodava `php phporto serve` num terminal. O Maintainer quer que ele suba com o Windows, mesmo sem sessão aberta, sem guardar senha e sem nível elevado. Isso é separado do acesso pela rede (ADR 0011): um não liga o outro.

## Decisão

- **Tarefa fixa:** `PHPorto`, disparo `At startup`, na conta do próprio usuário, "com ou sem usuário logado" pelo logon S4U, `RunLevel Limited`. Ação: `php.exe <raiz>\phporto serve`, pasta de trabalho na raiz. Reinício em falha (3 vezes, a cada minuto) e `MultipleInstances IgnoreNew`.
- **Senha:** o PHPorto nunca lê, recebe nem grava senha. Proibido formulário, API, STDIN e `Get-Credential` repassado. O S4U não guarda senha. Se o teste manual mostrar que o WSL ou o Ollama não funcionam na sessão S4U, o plano B é gerar o XML da tarefa para o usuário importar no `taskschd.msc`, que pede a senha no próprio diálogo. O plano B não foi implementado.
- **Ação elevada `autostart`** (`on`, `off`, `stop`), sensível, um UAC por ação (ADR 0001 e 0002). A conta é o SID do próprio processo PHP (`whoami /user`, classe `Identity`, a mesma no CLI e na `/config`), nunca `USERDOMAIN` e `USERNAME`, que numa máquina sem domínio podem dizer `WORKGROUP` enquanto o token diz `DESKTOP\usuario`. O `on` recebe o `Sid` (formato estrito `S-1-5-21-…`, validado no PHP e no worker) e só registra a tarefa se for igual ao `WindowsIdentity.GetCurrent().User.Value` do processo elevado, e só com `php.exe` e `phporto` existentes. A tarefa é registrada com o `GetCurrent().Name` (S4U, Limited). A leitura da tarefa (`status` e o `on` idempotente) compara pelo SID do principal, não pelo nome. Sem conseguir ler o SID, a ação falha com o motivo e nada é criado. Conta sem SID `S-1-5-21` (por exemplo, conta Entra ID) é recusada.
- **Estado próprio:** `files/autostart.json` (`enabled`, `updated_at`), fora do `network.json`. O `network off` e o Desligar já da rede não mexem nele. Um `autostart` antigo no `network.json` migra uma vez para o arquivo novo. Ligar o início não liga a rede.
- **Comandos:** `php phporto autostart on|off|status`. `on` e `off` são idempotentes: `on` não pede UAC se a tarefa já está igual ao esperado, e `off` não pede UAC se a tarefa não existe. `status` é só leitura.
- **Desligar:** `off` remove a tarefa e não derruba o `serve` em execução. Parar agora (`stop`) encerra a árvore de qualquer `php phporto serve` deste PHPorto, o da tarefa ou um aberto à mão no terminal, e a própria tarefa. Para cada `php.exe`, lê a linha de comando (com as regras de aspas do Windows), acha o caminho do script seguido de `serve`, resolve o caminho para absoluto (pelo diretório de trabalho do processo, se for relativo) e compara com o script do projeto sem diferenciar maiúsculas, sem curinga, então colchetes no caminho não atrapalham. O `php.exe` cuja linha de comando ou diretório não pôde ser lido não é morto, e a mensagem diz quantos ficaram sem confirmação.
- **Supervisão:** o `serve` reinicia o filho que cair, com espera de 1, 2, 4, 8 e 16 segundos, e as tentativas zeram depois de 60 segundos estável. Passado o teto, o listener de rede desiste sem derrubar o loopback, e o loopback desistindo faz o `serve` sair com falha, para o Agendador reiniciar. O principal que sai com 0 é parada pedida. A rede só volta se o IP, o perfil e a regra ainda conferirem.
- **Sem sessão interativa:** as ações que pedem UAC falham fechadas, com o motivo na tela, dentro do prazo do uso único. O firewall nunca é criado no boot: o `serve` só lê.
- **Tela:** seção Iniciar com o Windows na `/config`, separada da rede, com Ligar, Desligar e Parar agora.

## Consequência

A criação e a remoção da tarefa pedem UAC, mas a tarefa roda sem privilégio. O Ollama no boot e o chat ficam fora desta etapa. Se o IP da rede mudar, o `serve` do boot sobe só o loopback e o motivo vai para o histórico, até um novo `network on`.

Não testado no Windows real, só em testes automatizados com fakes:

- registrar a tarefa com S4U e disparo na inicialização pela ação elevada
- o WSL e o Ollama funcionando na sessão S4U
- o `serve` subindo no boot, sem ninguém logado
- ler a tarefa (`Get-ScheduledTask`) sem elevação e o formato de `LogonType`, `RunLevel` e do tipo do disparo
- `Stop-ScheduledTask` e o `taskkill` encerrando também os `php -S` filhos (a leitura do diretório de trabalho de outro processo funcionou no teste Pester, com um processo real do mesmo usuário; o resto não foi visto)
- o que acontece com o `serve` em execução quando a tarefa é removida
- o SID de `whoami /user` bater com o SID do processo elevado em todas as contas (conta local, Microsoft, domínio); numa máquina sem domínio isso falhava com o nome
- o reinício em falha do Agendador depois que o `serve` desiste

## Commit

`5f7333c`, `04858cd`, `b576db7`, `37450a6`

## PR

Sem PR: branch único `feat/cli-phporto`, com merge do Maintainer.
