# A tela do Windows

`/win` executa as ações de manutenção do Windows. Elas exigem Administrador,
e por isso rodam num PowerShell elevado, aberto pela tela `/config`.

## Ligar o PowerShell elevado

Em `/config`, seção Windows, o interruptor **PowerShell elevado** abre um
processo `powershell.exe` com privilégio de Administrador e espera a prova de
que ele funciona. O Windows pode pedir confirmação (UAC). Se ninguém confirmar
em 30 s, a tela avisa e oferece **tentar novamente**; cada POST faz uma
tentativa só.

Enquanto está ligado, esse processo aceita as ações vindas da `/win`. Ele
obedece a uma lista fechada de ações e parâmetros — o que a tela manda nunca é
código. Ver [seguranca.md](seguranca.md).

Ao ligar, o servidor anota o SHA-256 de cada arquivo que o processo elevado
carrega (`bootstrap.ps1`, `actions/`, `lib/`, `config/` e `audit/audit.ps1`).
Cada ação confere esses arquivos antes de carregá-los. Se algum mudou, sumiu ou
apareceu depois de ligar, a ação é recusada com exit 1 e a saída diz qual
arquivo foi. Depois de editar algo em `src/Win`, ou de um `git pull`,
**desligue e ligue de novo** para a mudança valer.

O processo elevado grava os scripts que gera e os resultados em
`files/win-protected/`, uma pasta que só Administradores e SYSTEM alteram. Se
essa pasta for um link, ou tiver outro dono, o processo elevado recusa subir e
a tela mostra o motivo; apague a pasta e ligue de novo. Enquanto ele estiver
ligado, a pasta do projeto não pode ser renomeada nem movida.

O estado é de vida curta:

- **recarregar a página mantém ligado**;
- **reiniciar o servidor desliga**: o processo elevado percebe que o `php -S`
  sumiu e sai sozinho;
- **desligar na `/config`** manda uma ordem que o processo obedece e sai. Não
  pede confirmação;
- **dez minutos sem uso desligam**: sem ação em andamento por 600 s, o processo
  elevado sai sozinho. Uma ação longa não conta como ociosidade. Para voltar a
  usar a `/win`, ligue de novo na `/config`.

Enquanto o interruptor estiver desligado, o botão do Windows na home fica
desabilitado e nenhuma ação comum roda. As sensíveis, abaixo, não dependem dele.

## Ações que abrem o UAC

Algumas ações pedem **um prompt de UAC a cada execução**, mesmo com o
PowerShell elevado ligado: instalar apps (`install`), ligar o acesso remoto
(`rdp on`), instalar o Sunshine, abrir a porta dele no firewall, gravar a senha
da Web UI dele e parear um Moonlight, instalar o
windows_exporter e liberá-lo no firewall, instalar o exportador da GPU, e
instalar e ligar o OpenSSH Server (`openssh install` e `openssh enable`). Na
tela, o botão ou a opção delas diz **abre o UAC**.

- O prompt mostra "Windows PowerShell". Ele é a confirmação daquele clique;
  um prompt que apareça sem você ter clicado não é do PHPorto.
- Recusar, ou não responder em 60 s, não executa nada, e a tela diz isso.
- Elas rodam com o interruptor do PowerShell elevado **desligado**: cada uma
  abre e fecha o próprio processo elevado.
- Com o UAC silencioso (`ConsentPromptBehaviorAdmin` = 0) ou desligado, elas
  são recusadas, com a correção na mensagem. A `/config` mostra em que pé o UAC
  está. Ver [seguranca.md](seguranca.md#ações-sensíveis-uac-por-execução).

## Como as ações rodam

Cada ação é um formulário próprio. O envio é validado no servidor, gravado num
arquivo de trabalho e executado pelo processo elevado. A resposta espera a ação
terminar e grava a execução no histórico.

- **Tempo:** cada ação é cancelada depois de `PHPORTO_WINUTIL_TIMEOUT` (padrão
  600 s). Ver [configuracao.md](configuracao.md).
- **Espera síncrona:** o `php -S` atende em série, então uma ação longa congela
  as outras telas até terminar.
- **Saída:** cortada em 1 MiB por execução, com o aviso na própria saída.
- **Uma ação por vez.**
- **Servidor parado no meio:** o processo elevado encerra a ação e deixa um
  sinal de fim. No próximo carregamento da `/win`, a execução entra no histórico
  com a hora real, código de saída vazio e a nota de que foi interrompida.

## A organização da tela

A tela tem quatro partes, nesta ordem:

1. a última execução do Windows;
2. as três ações de um clique, sem parâmetro e sem estado: **Auditoria**,
   **Memória** e **Processos**;
3. as outras ações, em cinco abas;
4. o histórico do Windows.

A aba aberta fica na URL, em `/win?aba=sistema`, `rede`, `aplicativos`,
`servicos` ou `acesso-remoto`. Depois de executar, a tela volta para a mesma aba. Aba desconhecida
abre Sistema.

Trocar de aba não volta ao topo da página. Com JavaScript, o painel troca sem
recarregar e a URL acompanha a aba. Sem JavaScript, o link recarrega a página
já posicionada no menu de abas.

| aba | ações |
| --- | --- |
| Sistema | Ajustes, Remover apps |
| Rede | DNS, Captura de rede |
| Aplicativos | Instalar apps |
| Serviços | Métricas do Windows, Métricas da GPU, Otimizar, Dispositivos conectados (GDID), Desempenho |
| Acesso Remoto | Área de Trabalho Remota (RDP), Sunshine, Acesso SSH |
| Chaves SSH | Gerar chave, Arquivo .ppk, Autorizar no Windows, Usuário e chave no WSL |

## As ações

### Auditoria (`audit`)

Gera o log do sistema em oito arquivos, em `C:\log\DD.MM.AAAA`: sistema,
hardware, os 30 processos que mais usam RAM, serviços em execução, programas de
inicialização, rede, tarefas agendadas ativas e VMs do Hyper-V.

**Explorar** abre a pasta do log de hoje no Explorer, ou `C:\log` sem auditoria
de hoje. A janela abre na máquina onde o servidor roda. O registro dessa
execução diz exit 0 mesmo que nenhuma janela tenha aparecido: o Explorer
retorna antes de abri-la.

### Memória (`memory`)

Limpa a RAM com o WinMemoryCleaner, baixado para `src/Win/tools/` na primeira
execução. Antes de rodar, o SHA-256 do executável é conferido contra o da versão
fixada; se não bater, o arquivo é apagado e a ação para com erro.

### Processos (`processes`)

Lista os 30 processos que mais consomem RAM. Só lê.

### Ajustes (`tweaks`)

Os 62 ajustes do WinUtil, lidos de `src/Win/config/tweaks.json`, com rótulo e
descrição em português. Mexem no registro e nos serviços do Windows. Nenhum vem
marcado; os botões de preset (padrão, mínimo, avançado) marcam as caixas de um
conjunto pronto. O grupo "Ajustes avançados — CUIDADO" vem destacado.

Antes de aplicar cada ajuste, a ação confere se os comandos que ele usa existem;
se falta algum, o ajuste sai como `ERROR` e não é aplicado.

Seis ajustes (widgets, modo escuro, extensões, arquivos ocultos, recomendações
do Iniciar e alinhamento da barra de tarefas) podem só valer no próximo login ou
depois de reiniciar o Explorer. A tela marca cada um.

### Remover apps (`debloat`)

Remove os pacotes APPX marcados, entre os 22 de `src/Win/config/debloat.json`.
Sem seleção guardada, os 22 vêm marcados. Enviar com nenhuma caixa marcada é
recusado, em vez de remover todos.

### DNS (`dns`)

Troca o DNS dos adaptadores de rede ativos. As opções são os provedores de
`src/Win/config/dns.json` (Google, Cloudflare e variações com filtro, OpenDNS,
Quad9, AdGuard), o **DNS próprio**, com primário obrigatório e secundário
opcional, e o **DHCP**, que devolve o DNS ao automático do roteador.

Cada adaptador que falha aparece como `ERROR`, e o resumo não diz "applied"
quando houve falha. Sem adaptador ativo, nada é alterado.

### Captura de rede (`network`)

Captura pacotes com o TShark numa interface, pelo tempo pedido (1 a 3600 s), e
gera um relatório. A captura vai para `runtime/Captures` e o relatório para
`runtime/Reports`, na pasta do projeto. Sem o TShark, a ação instala o Wireshark
pelo winget. A interface é obrigatória.

### Instalar apps (`install`)

Instala apps pelo winget, por ID exato, separados por vírgula. O campo aceita
qualquer ID; as caixas somam sete principais (Git, Visual Studio Code, Docker
Desktop, WSL, Debian, 7-Zip e VoiceMeeter Potato). Cada pacote é instalado
sozinho, sem perguntas, e o resultado de cada um é lido:

| código do winget | resultado |
| --- | --- |
| 0 | `OK` |
| já instalado | `OK` |
| reinício necessário para terminar | `WARNING`, com o nome do pacote |
| outro | `ERROR`, com o código |

### Métricas do Windows (`exporter`)

Instala e controla o `windows_exporter`, para o Prometheus, na porta 9182.
Subações: instalar, ver o estado, iniciar, parar, ver as métricas e liberar no
firewall. O instalador MSI é baixado para `runtime/`; o programa se instala em
`C:\Program Files\windows_exporter`. A instalação registra uma tarefa agendada
que o inicia com o Windows.

### Métricas da GPU (`gpu`)

Instala e controla o `nvidia_gpu_exporter`, para o Prometheus, na porta 9835.
Instala em `runtime/nvidia_gpu_exporter`, com tarefa agendada e regra de
firewall. Subações: instalar, ver o estado, iniciar, parar, ver as métricas e
desinstalar, que remove a tarefa, a regra e a pasta.

### Otimizar (`optimize`)

Para processos de interface e desabilita os serviços por trás deles, guardando o
estado anterior em `runtime/optimize-state.json`. Aceita um preset, uma lista de
processos a matar, ou restaurar:

- **`ssh`** — modo servidor. Desabilita, entre outros, o `WslService`: o botão
  do WSL desta ferramenta apaga até restaurar.
- **`kill-rdp`** — faz logoff das sessões RDP desconectadas e limpa o que elas
  deixam, incluindo `explorer`, `dwm` e `sihost`. Chamado pelo navegador, é a
  área de trabalho onde o próprio navegador está aberto. **Preservar** protege
  um usuário do logoff.
- **Restaurar (`-Undo`)** — devolve os serviços ao estado guardado. Sem o
  arquivo de estado, recusa.

A tela avisa antes dos dois presets, dizendo o que cada um derruba.

### Dispositivos conectados (`gdid`)

Liga e desliga o pipeline de Connected Devices: serviços, histórico de
atividades, domínios no arquivo `hosts` e o cache. **Ver o estado** (`status`)
só lê. **Desligar** (`disable`) guarda o tipo de início original dos serviços
em `runtime/gdid-state.json` e **corta as notificações do Windows** — os
domínios do WNS entram no bloqueio, e apps da Store param de receber aviso.
**Religar** (`enable`) devolve tudo.

### Desempenho (`performance`)

**Ativar desempenho máximo** troca o plano de energia para o Desempenho Máximo,
ou para o Alto Desempenho quando o Windows não tem o primeiro. **Voltar ao
Balanceado** devolve o plano padrão do Windows.

### Sunshine (`sunshine`)

Na aba **Acesso Remoto**. Além de ver o estado, instalar, iniciar/parar o
serviço e abrir/fechar a porta 47990, a seção tem um formulário com usuário,
senha, PIN e nome do dispositivo:

- **Parear com o PIN** (`pair`) — no Moonlight, adicione este PC; ele mostra um
  PIN de 4 dígitos. Digite o PIN, o usuário e a senha da Web UI, clique e
  aceite o UAC. Com **Gravar estas credenciais antes de parear** marcado, o
  mesmo UAC primeiro troca o usuário e a senha (necessário na primeira vez,
  quando o Sunshine ainda não tem credenciais). Se mais de um Moonlight estiver
  esperando PIN, a ação recusa e lista nome e endereço de cada um.
- **Só gravar credenciais** (`set-creds`) — para o serviço, grava usuário e
  senha com o próprio `sunshine.exe --creds` e sobe o serviço de volta (mesmo se
  a gravação falhar). Não usa PIN nem nome.

A senha tem pelo menos 8 bytes; o usuário não pode ter `:`. O histórico mostra
o usuário, mas `***` no lugar da senha e do PIN. O PIN expira em pouco tempo no
Moonlight: se o UAC demorar, gere outro. Detalhes em
[seguranca.md](seguranca.md#sunshine-senha-da-web-ui-e-pin-do-moonlight).

### Acesso SSH (`openssh`)

Na aba **Acesso Remoto**. Prepara esta máquina para o túnel SSH que leva ao
PHPorto de outro computador da rede privada:

- **Ver o estado** (`status`) — se o OpenSSH Server está instalado, se o `sshd`
  está rodando e se a regra do firewall existe. Só lê.
- **Instalar o OpenSSH Server** (`install`) — recurso opcional do próprio
  Windows, sem winget. Abre o UAC.
- **Ligar o SSH** (`enable`) — `sshd` em Automático e no ar, e a porta 22
  aberta só no perfil Privado do firewall. Abre o UAC.
- **Desligar o SSH** (`disable`) — para o `sshd`, volta para Manual e fecha a
  porta.

A seção traz o comando do túnel pronto para copiar,
`ssh -N -L 4001:127.0.0.1:4001 <usuario>@<maquina>`, e avisa que ligar abre a
porta 22 na rede privada. Não há porta configurável, e nada mexe no
`sshd_config` nem em chaves. Detalhes em
[seguranca.md](seguranca.md#acesso-remoto-túnel-ssh).

### Chaves SSH (`ssh-keys`)

Na aba **Chaves SSH**, depois de Acesso Remoto. Com o OpenSSH desligado, a aba
avisa, e autorizar e criar usuário no WSL ficam desabilitados.

- **Gerar chave ed25519** (`generate`) — `ssh-keygen -t ed25519` do Windows,
  sem senha, em `%USERPROFILE%\.ssh\id_ed25519`. Se já existe, não sobrescreve
  e avisa. A tela e o histórico mostram só a pública e a impressão SHA256.
- **Ver o estado** (`status`) — `sshd`, a pública que está no disco, o
  PuTTYgen e quantas chaves estão autorizadas. Só lê.
- **Instalar PuTTYgen** (`puttygen`) — winget com o ID fixo `PuTTY.PuTTY`,
  pela mesma validação do `install`. Abre o UAC.
- **Autorizar** (`authorize`) — acrescenta a pública colada, ou a gerada, ao
  `C:\ProgramData\ssh\administrators_authorized_keys`, com ACL só de SYSTEM e
  Administradores. Abre o UAC.
- **Ver as autorizadas** (`list`) e **Remover…** (`remove`, por impressão,
  com confirmação). Remover abre o UAC.
- **Criar usuário** (`wsl-user`) — usuário novo na distro escolhida, sem sudo,
  com `~/.ssh` em 700 e `authorized_keys` em 600. Abre o UAC.

Só entra linha pública `ssh-ed25519`, `ssh-rsa` ou `ecdsa-sha2-nistp*`, sem
opções na frente, conferida no PHP e no worker. Login por senha e
`sshd_config` ficam de fora.

## A tela Hyper-V

`/hyperv` lista as máquinas virtuais deste computador: nome, situação, memória
em uso, há quanto tempo está ligada e o endereço IP. Abrir a tela não grava no
histórico. Ela não cria nem apaga VM.

Ela só existe com o **Painel do Hyper-V** ligado em `/config`. Ligar confere,
sem elevação, se o recurso Hyper-V está ligado no Windows; se não estiver, o
painel não liga e a frase diz o que falta (no Windows Home o Hyper-V não
existe, e a frase diz isso). O estado fica em `storage/hyperv.json`, fora do
Git, e sobrevive a reiniciar.

A leitura pede o **PowerShell elevado** ligado, porque o `Get-VM` exige
Administrador. Ela roda ao abrir a tela e a cada **Atualizar**, com teto de
60 s; passou disso, é cancelada.

O que cada faixa quer dizer:

| faixa | o que fazer |
|---|---|
| precisa do PowerShell elevado ligado | **Abrir configuração** e ligar o PowerShell elevado |
| a leitura demorou demais | **Atualizar** em instantes |
| o Hyper-V não está ligado no Windows | ligar em "Ativar ou desativar recursos do Windows" e reiniciar |
| o serviço de máquinas virtuais está parado | reiniciar o computador, ou iniciar "Gerenciamento de Máquina Virtual do Hyper-V" em *Serviços* |
| não deu para ler as máquinas virtuais | abrir **detalhes técnicos** para ver a mensagem do Windows |

O IP mostra o IPv4 primeiro, com o botão **copiar**. Endereços `fe80::` ficam
de fora; outros IPv6 aparecem em **mais endereços**.

A coluna **Ações** liga (verde), desliga (vermelho) e reinicia (âmbar) uma VM,
achada pelo id e nunca pelo nome. Cada clique abre o próprio UAC e entra no
histórico da `/win`. Só fica habilitado o que cabe no estado lido:

| situação | o que dá para fazer |
|---|---|
| desligada, salva ou hibernada | ligar |
| pausada | retomar (o botão de ligar) |
| rodando | desligar e reiniciar |
| mudando de estado | nada, até atualizar |

Desligar e reiniciar pedem confirmação. Desligar pede ao sistema da VM, como o
menu Iniciar dela, e não corta a energia. Reiniciar também, quando o Hyper-V
sabe; quando não sabe, faz um reset. O estado é conferido de novo na hora, e o
que não cabe mais é recusado com o motivo.

## A tela do PHP

`/php` mostra o PHP que serve o PHPorto: versão, TS ou NTS, binário,
`php.ini` carregado, compilador e arquitetura, SAPI e o valor de
`session.upload_progress.enabled` (com a etiqueta `default` quando nenhum ini
declara a chave). A entrada fica em `/config`, na seção **PHP**.

A medição é feita no próprio processo do servidor, sem PowerShell e sem UAC.
Se o primeiro `php` do PATH for outro binário, a tela avisa e mostra os dois;
esse outro é medido com `php -r`, com teto de 5 s. A medida não grava no
histórico.

### Atualizar o PHP

Abaixo da medida, a tela compara a versão com a lista oficial de builds do PHP
para Windows (`https://downloads.php.net/~windows/releases/releases.json`). A
lista fica em `storage/php-releases.json` por 1 h (5 min quando a consulta
falha); o pedido de atualização sempre consulta de novo. O botão
**Atualizar PHP…** só aparece quando há um patch mais novo na **mesma linha**
(`8.5.11` para `8.5.12`, nunca `8.5` para `8.6`) e o PHP é NTS x64 numa pasta
local. Com TS, x86, versão de teste ou outra linha, a tela explica e não oferece.

A atualização é ação sensível e abre o UAC. A versão e o SHA256 vêm da lista
oficial, conferida de novo pelo próprio worker, só por `https` no
`downloads.php.net` e sem seguir redirecionamento. O zip é o NTS x64 oficial; se
o SHA256 não bater, nada é trocado. Antes de trocar, a pasta inteira é copiada
para uma irmã com a versão antiga no nome (`C:\php-8.5.11`); se já existir, vai
para `C:\php-8.5.11-2`, `-3` e assim até `-9`, sem sobrescrever backup. O zip
é extraído na própria pasta, o `php.ini` anterior volta para o lugar e o
`php.exe -v` precisa responder a versão nova. Se qualquer passo falhar, a pasta
volta como estava. PATH e permissões não mudam.

O PHP que está rodando continua o antigo: feche o `php -S` e suba de novo.

### Gravar o php.ini

Abaixo da medida, a tela lista uma lista fechada de chaves do `php.ini`
carregado, com o valor do arquivo, a etiqueta de quem difere do padrão e o valor
em uso quando ele ainda é o antigo:

| Chave | Aceita |
|---|---|
| `session.upload_progress.enabled` | `On` ou `Off` |
| `upload_max_filesize` | `1M` a `16G`, com `K`, `M` ou `G` |
| `post_max_size` | `1M` a `16G`, nunca menor que `upload_max_filesize` |
| `memory_limit` | `32M` a `16G`, ou `-1` |
| `max_execution_time` | `0` a `3600` segundos |
| `date.timezone` | um fuso da lista do PHP |
| `display_errors` | `On` ou `Off` |

Nenhuma outra chave e nenhum texto livre de ini: o POST com chave fora da lista
ou valor inválido é recusado inteiro. Só as chaves que mudaram são escritas.
Antes de gravar, o arquivo atual é copiado para `php.ini.bak` na mesma pasta; a
troca é atômica (temporário na mesma pasta e rename) e o resto do arquivo fica
igual, byte a byte, inclusive CRLF e BOM. Se o `php.ini` mudou no disco depois
que a tela abriu, nada é gravado.

Quem grava é o próprio `php -S`, com o usuário comum. Por isso o `php.ini`
precisa estar numa pasta que esse usuário pode gravar, como `C:\php`. Se não
puder (por exemplo, dentro de `C:\Program Files`), a tela explica e não oferece
salvar: não há caminho com UAC para o `php.ini`. Sem `php.ini` carregado, a tela
também não grava.

O valor novo só vale depois de fechar o `php -S` e subir de novo.

## Reversíveis e não reversíveis

| ação | como se desfaz |
| --- | --- |
| Ajustes | marcar os aplicados e **Reverter** (`-Undo`) |
| Desempenho | **Voltar ao Balanceado** |
| Otimizar | **Restaurar** (`-Undo`), com o estado em `runtime/` |
| GDID | **Religar** (`enable`) |
| DNS | escolher outro provedor, ou **DHCP** |
| Métricas da GPU | **desinstalar** |
| Acesso SSH | **Desligar o SSH** (`disable`); o OpenSSH Server instalado fica |
| Chaves SSH | **Remover…** tira a autorizada; a chave gerada e o usuário do WSL ficam |
| Remover apps | não se desfaz pela tela |
| Instalar apps | não se desfaz pela tela |
| Métricas do Windows | não há subação de desinstalar |
| Auditoria, Memória, Processos, Captura de rede | não alteram configuração |

Nos quatro primeiros, a tela lembra o que ela mesma aplicou: o botão principal
vira **Reverter** e cada ajuste aplicado ganha a marca "aplicado". Essa memória
é do que a tela mandou fazer, não uma leitura da máquina. Mudança feita por fora
— pelo `regedit`, por outro programa, por um PowerShell à mão — não aparece, e
por isso os interruptores manuais de `-Undo` continuam na tela.

Apagar o histórico do Windows não apaga essa memória. Restaurar de fábrica, na
`/config`, apaga o arquivo do banco e leva as duas.

## O que exige reiniciar

- **VoiceMeeter Potato**, na instalação: só funciona depois de reiniciar o
  Windows. A tela avisa na caixa dele.
- **Instalação com código de reinício pendente**: sai como `WARNING`, dizendo
  quais pacotes.
- **Os seis ajustes do Explorer**: podem pedir novo login ou reiniciar o
  Explorer, não o Windows.

## Histórico

O histórico do Windows fica no fim da tela, fora das abas. Cada linha guarda a
linha de comando equivalente, a saída, o código de saída e a duração, com a data
no fuso de `PHPORTO_TZ`. Senha e PIN do Sunshine aparecem como `***`. **Limpar histórico do Windows** apaga só os registros
de tipo `windows`; o do WSL fica.

## Onde as ações gravam

| pasta | o quê |
| --- | --- |
| `runtime/` | estado do optimize e do gdid, capturas e relatórios de rede, MSI do windows_exporter, exportador da GPU |
| `C:\log\` | os arquivos da auditoria |
| `src/Win/tools/` | o WinMemoryCleaner |
| `files/` | o canal com o processo elevado: trabalho, ordens, heartbeat, manifesto e log do worker |
| `files/win-protected/` | os scripts gerados e os resultados do processo elevado: saída, código e conclusão |

`runtime/` não é versionada. Apagá-la com o optimize ou o gdid aplicados leva
junto o que o `-Undo` e o `enable` leem para desfazer, e com o exportador da GPU
instalado quebra a tarefa agendada que o inicia.
