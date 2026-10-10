# A tela do Windows

`/win` executa treze ações de manutenção do Windows. Elas exigem Administrador,
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
(`rdp on`), instalar o Sunshine e abrir a porta dele no firewall, instalar o
windows_exporter e liberá-lo no firewall, e instalar o exportador da GPU. Na
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
3. as outras dez ações, em quatro abas;
4. o histórico do Windows.

A aba aberta fica na URL, em `/win?aba=sistema`, `rede`, `aplicativos` ou
`servicos`. Depois de executar, a tela volta para a mesma aba. Aba desconhecida
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

## A tela Hyper-V

`/hyperv` lista as máquinas virtuais deste computador: nome, situação, memória
em uso, há quanto tempo está ligada e o endereço IP. **Só lê**: não liga, não
desliga, não cria nem apaga VM, e abrir a tela não grava no histórico.

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

## Reversíveis e não reversíveis

| ação | como se desfaz |
| --- | --- |
| Ajustes | marcar os aplicados e **Reverter** (`-Undo`) |
| Desempenho | **Voltar ao Balanceado** |
| Otimizar | **Restaurar** (`-Undo`), com o estado em `runtime/` |
| GDID | **Religar** (`enable`) |
| DNS | escolher outro provedor, ou **DHCP** |
| Métricas da GPU | **desinstalar** |
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
no fuso de `PHPORTO_TZ`. **Limpar histórico do Windows** apaga só os registros
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
