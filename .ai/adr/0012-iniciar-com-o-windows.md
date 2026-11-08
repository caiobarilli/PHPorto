# Iniciar com o Windows pela tarefa do Agendador

## Contexto

O painel só existia enquanto alguém rodava `php phporto serve` num terminal. O Maintainer quer que ele suba com o Windows, mesmo sem sessão aberta e sem nível elevado, e que depois da instalação tudo se opere pelo painel. Isso é separado do acesso pela rede (ADR 0011): um não liga o outro.

A primeira versão usava o logon S4U (sem senha). O teste no Windows real derrubou a hipótese: numa conta administradora, a tarefa S4U sobe o `serve` com o `svchost.exe` como pai, em nível de integridade alto (`S-1-16-12288`). O Windows só divide o token do UAC em logon interativo. O canal do Ollama recusa isso (correto), e o Ollama fica inacessível no boot. Também medido: uma sessão SSH de conta administradora é elevada, o `serve` iniciado por ela sobe em nível alto e o WSL aparece como "não ativado".

## Decisão

- **Tarefa fixa (plano B):** `PHPorto`, disparo `At startup`, na conta do próprio usuário, logon por senha ("com ou sem usuário conectado", `LogonType Password`), `RunLevel LeastPrivilege` (Limited). Ação: `php.exe "<raiz>\phporto" serve`, pasta de trabalho na raiz. Reinício em falha (3 vezes, a cada minuto) e `MultipleInstancesPolicy IgnoreNew`. A tarefa S4U antiga é substituída. A hipótese de que logon com senha mais Limited dá nível **médio** numa conta administradora é **não testada**: o teste manual decide.
- **Senha, e o caminho que a pede:** o PHPorto nunca lê, recebe nem grava senha. Proibido formulário, API, STDIN, `Get-Credential` e `Register-ScheduledTask -Password`. O caminho escolhido: o PHPorto gera `files/PHPorto-tarefa.xml` (UTF-16, sem senha, só o SID, o logon `Password`, o nível limitado, o disparo no boot e a ação), e o usuário o importa no Agendador de Tarefas (`taskschd.msc`, como administrador, Ação, Importar Tarefa). O diálogo do próprio Windows pede a senha e a entrega ao serviço do Agendador. Por que não expõe a senha: o XML do Agendador não tem campo de senha (ela vai separada, no diálogo ou no `/RP` do `schtasks`), e nesse caminho ela vai do teclado do usuário para o processo do Windows, sem passar por nenhum processo que o PHPorto abra. Descartados: `schtasks /Create /RP *` e `Register-ScheduledTask -Password`, porque a senha entraria num processo aberto pelo PHPorto (a leitura do console ou o parâmetro ficam no alcance dele). Abrir a tarefa pré-preenchida não existe: o `taskschd.msc` não aceita um XML pela linha de comando. É "não testada" que o diálogo de importação peça a senha para esse XML e que a tarefa importada fique igual ao esperado.
- **Conta:** o SID do próprio processo PHP (`whoami /user`, por `System32\whoami.exe`, classe `Identity`, a mesma no CLI e na `/config`), nunca `USERDOMAIN` e `USERNAME`, que numa máquina sem domínio podem dizer `WORKGROUP` enquanto o token diz `DESKTOP\usuario`. Formato estrito `S-1-5-21-…`; sem conseguir ler o SID, nada é gerado. Conta sem SID `S-1-5-21` (por exemplo, Entra ID) é recusada. A leitura da tarefa (`status` e o `on` idempotente) compara pelo SID do principal, não pelo nome.
- **Ação elevada `autostart`** (`off`, `stop`, `restart`), sensível, um UAC por ação (ADR 0001 e 0002). Não há mais subação que crie tarefa: nenhum caminho cria uma tarefa S4U ou elevada. `off` remove a tarefa. `stop` encerra a árvore de qualquer `php phporto serve` deste PHPorto. `restart` faz o `stop` e roda a tarefa de novo (`Start-ScheduledTask`); sem a tarefa, falha antes de encerrar qualquer coisa.
- **Estado próprio:** `files/autostart.json` (`enabled`, `updated_at`), fora do `network.json`. O `network off` e o Desligar já da rede não mexem nele. Ligar o início não liga a rede.
- **Comandos:** `php phporto autostart on|off|status|restart`. `on`: se a tarefa já está igual ao esperado (SID, logon `Password`, Limited, php, script, boot), não faz nada; se há uma tarefa diferente (por exemplo S4U), a remove com UAC antes de gerar o XML; sem tarefa, só gera o XML (sem UAC) e diz que a tarefa ainda não está criada. O estado só vira ligado quando a tarefa é observada igual ao esperado. `off` não pede UAC se a tarefa não existe. `status` é só leitura e mostra o logon da tarefa e o nível deste processo.
- **O `serve` recusa nível elevado:** o nível de integridade vem do SID `S-1-16-…` do `whoami /groups` (`Level`). Em nível alto (`12288`) ou de sistema (`16384`), `php phporto serve` sai com falha, não sobe nenhum listener, escreve no STDERR o motivo e como iniciar (terminal comum ou `schtasks /Run /TN PHPorto`, e que o SSH de administrador é elevado) e registra "serve recusado" no histórico. Decisão: **não subir**, em vez de subir só para mostrar o aviso. Um painel elevado executa ações de administrador sem o UAC, e a promessa dos ADR 0001 e 0002 é que o PHPorto não tem privilégio sozinho; um servidor que acessa a rede, recebe um token e roda elevado só para exibir uma mensagem é exatamente o risco que a recusa evita. O motivo fica no STDERR, no histórico e no código de saída da tarefa. Se o nível não puder ser lido, o `serve` sobe (a recusa só vale para um nível lido como alto), e fora do Windows a checagem não existe. A checagem é do `serve`; um `php -S` à mão sobre `public/router.php` não passa por ela.
- **Desligar e parar:** `off` remove a tarefa e não derruba o `serve` em execução. Parar agora encerra a árvore de qualquer `php phporto serve` deste PHPorto, o da tarefa ou um aberto à mão. Para cada `php.exe`, lê a linha de comando (com as regras de aspas do Windows), acha o caminho do script seguido de `serve`, resolve o caminho para absoluto (pelo diretório de trabalho do processo, se for relativo) e compara com o script do projeto sem diferenciar maiúsculas, sem curinga. O `php.exe` cuja linha de comando ou diretório não pôde ser lido não é morto, e a mensagem diz quantos ficaram sem confirmação.
- **Supervisão:** o `serve` reinicia o filho que cair, com espera de 1, 2, 4, 8 e 16 segundos, e as tentativas zeram depois de 60 segundos estável. Passado o teto, o listener de rede desiste sem derrubar o loopback, e o loopback desistindo faz o `serve` sair com falha, para o Agendador reiniciar. O principal que sai com 0 é parada pedida. A rede só volta se o IP, o perfil e a regra ainda conferirem.
- **Sem sessão interativa:** as ações que pedem UAC falham fechadas, com o motivo na tela, dentro do prazo do uso único. O firewall nunca é criado no boot: o `serve` só lê.
- **Tela:** seção Iniciar com o Windows na `/config`, separada da rede: mostra a tarefa (presente ou ausente, igual ao esperado ou não), o logon dela (Password ou S4U, com aviso para S4U) e o nível do servidor (com aviso se for alto). Ligar fica desativado com a tarefa já igual ao esperado, e Desligar e Reiniciar sem tarefa; o servidor valida do mesmo jeito. Botões: Ligar (gera o XML e diz o que fazer), Desligar, Reiniciar o servidor pela tarefa e Parar agora.

## Consequência

A remoção da tarefa e o reinício pedem UAC, e a criação é uma importação manual no `taskschd.msc` (com a senha no diálogo do Windows), mas a tarefa roda sem privilégio. Esse passo manual é o preço de o PHPorto nunca ver a senha. Se a hipótese do nível médio falhar, o `serve` recusa subir pela tarefa (nível alto) e o painel não volta no boot: o que se faz então é decisão do Maintainer (por exemplo, a tarefa com `RunLevel` Limited por uma conta padrão separada), não do código. O Ollama no boot e o chat ficam fora desta etapa. Se o IP da rede mudar, o `serve` do boot sobe só o loopback e o motivo vai para o histórico, até um novo `network on`.

Não testado no Windows real, só em testes automatizados com fakes:

- o logon por senha com `RunLevel` Limited dar nível **médio** numa conta administradora
- o diálogo de importação do `taskschd.msc` pedir a senha para o XML gerado
- o XML gerado ser aceito pelo Agendador (esquema 1.2, UTF-16) e a tarefa importada ficar igual ao esperado no `status` (formato de `LogonType`, `RunLevel`, do tipo do disparo e do SID lido pelo `NTAccount`)
- o WSL e o Ollama funcionando na sessão da tarefa com senha
- o `serve` subindo no boot, sem ninguém logado, com a tarefa com senha
- o `Start-ScheduledTask` iniciar o `serve` depois do `taskkill` do `restart`
- `Stop-ScheduledTask` e o `taskkill` encerrando também os `php -S` filhos (a leitura do diretório de trabalho de outro processo funcionou no teste Pester, com um processo real do mesmo usuário; o resto não foi visto)
- o que acontece com o `serve` em execução quando a tarefa é removida
- o reinício em falha do Agendador depois que o `serve` desiste, e depois da recusa por nível alto

Validado no Windows real: o SID de `whoami /user` identifica a conta onde o nome por `USERDOMAIN` falhava; a tarefa S4U sobe elevada numa conta administradora e uma sessão SSH de administrador é elevada (a hipótese original do S4U caiu).

## Commit

`5f7333c`, `04858cd`, `b576db7`, `37450a6`, `c97a0cb` (SID); plano B no branch `fix/autostart-plano-b`.

## PR

Sem PR para a primeira parte: branch único `feat/cli-phporto`, com merge do Maintainer. O plano B vai por PR do branch `fix/autostart-plano-b`.
