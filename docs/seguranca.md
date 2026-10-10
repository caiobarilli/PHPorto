# Segurança

O PHPorto executa comando de shell arbitrário na máquina onde roda: no WSL, com
os privilégios do usuário da distro; na tela `/win`, como Administrador do
Windows. É uma ferramenta local, de uma pessoa só. Este documento reúne as
travas que existem e o que cada uma cobre.

## Bind no loopback

O servidor deve escutar **só em `127.0.0.1`**:

```bash
php -S 127.0.0.1:4001 -t public public/router.php
```

Nunca `0.0.0.0`, o IP da rede ou um proxy que o exponha. O token de acesso não
muda isso: ele viaja em HTTP Basic, que é base64 — codificação, não cifra.
Quem enxergar o tráfego lê o token e, com ele, ganha execução de comando na
máquina.

### O `-t public` e o `router.php`

`public/` é o único diretório servido. `src/`, `storage/`, `files/`, `runtime/`
e o `.env` ficam fora dele, e não existe URL que os alcance. Medido: com a raiz
do projeto como document root, `GET /.env` devolvia o arquivo e
`GET /storage/database.sqlite` devolvia o banco; com `-t public`, os dois são
404 por não existirem ali.

O `router.php` é a segunda camada. Ele funciona como lista de permissão — tudo
que não é rota da aplicação é 404 —, barra dotfiles que apareçam em `public/`
decodificando o caminho antes de comparar (senão `/%2Eenv` passaria) e recusa
byte nulo.

## Acesso remoto: túnel SSH

Para usar de outra máquina, abra um túnel SSH até o `127.0.0.1` da máquina que
roda o PHPorto, em vez de abrir a porta:

```bash
ssh -N -L 4001:127.0.0.1:4001 usuario@maquina-windows
```

E abra <http://127.0.0.1:4001> na máquina de onde você está. O tráfego, token
incluído, vai cifrado pelo SSH. A máquina Windows precisa de um servidor SSH
(como o OpenSSH Server do Windows), que o PHPorto não instala.

Pelo túnel, janelas que uma ação abre — como o **Explorar** da auditoria —
aparecem na máquina Windows, não na sua.

## Token de acesso

Toda requisição, telas e API, passa por um portão único antes do roteamento.

- **Geração:** `php token.php` cria 32 bytes aleatórios em hexadecimal, imprime
  e grava em `PHPORTO_AUTH_TOKEN` no `.env`. Trocar um token existente pede
  confirmação. O script roda só na linha de comando e mora fora de `public/`.
- **Entrada:** HTTP Basic. No navegador, o próprio diálogo de login; o token vai
  no campo de senha e o usuário é ignorado. Pela API, `curl -u :TOKEN`.
- **Comparação:** `hash_equals`, em tempo constante.
- **Sem token configurado:** a aplicação responde 503 a tudo, com credencial ou
  sem. A falha cai para o lado fechado.
- **Sem logout:** o HTTP Basic não tem. Para sair, feche o navegador.

## Limite de tentativas

Cinco tokens errados seguidos bloqueiam o acesso por quinze minutos. O bloqueio
responde 429, com `Retry-After` e o tempo que falta.

- Só erro conta: requisição sem credencial não conta, porque a primeira de todo
  navegador vem sem ela. Um acerto zera o contador.
- Durante o bloqueio, nem o token certo passa.
- O contador é um só, não por IP: tudo chega do loopback.
- O contador fica em `storage/auth-falhas.json` e guarda a impressão SHA-256 do
  token, nunca o token. Trocar o token desfaz o bloqueio do anterior.
- Contador ilegível conta como zero; falha ao gravá-lo não impede a resposta.

## CSRF de uso único

Todo formulário das telas leva um token de formulário que vale por **uma**
execução. O servidor valida e queima o token no envio, e a página seguinte nasce
com outro. Toda rota com efeito responde com POST-redirect-GET (303).

Isso cobre duas coisas:

- **Outro site disparando um POST.** Um formulário em qualquer página pode fazer
  POST para `http://127.0.0.1:4001/wsl`, e o CORS não impede a requisição de
  sair — impede só a resposta de ser lida. O site de terceiro não consegue ler o
  token de formulário, e sem ele o envio é recusado.
- **Execução repetida.** Recarregar, voltar no navegador ou reenviar o mesmo POST
  não executa de novo. Com duas abas abertas, a mais antiga tem o token vencido.

Na API, a trava equivalente é o `Content-Type: application/json` obrigatório:
um formulário HTML não consegue enviá-lo, e ele obriga o navegador a um
preflight que o CORS barra antes de qualquer efeito. A checagem do cabeçalho
`Origin` contra `CORS_ORIGIN` é a segunda camada. Ver [api.md](api.md).

### O token de acesso não substitui o CSRF

Depois que o token de acesso é colado no diálogo, **o navegador o reenvia
sozinho** em toda requisição para `127.0.0.1:4001` — inclusive no POST que o
formulário de outro site dispara. O token de acesso não distingue a requisição
legítima da forjada. Contra isso, quem trava continua sendo o token de
formulário nas telas e o `Content-Type` obrigatório na API.

## Allowlist dupla nas ações do Windows

O processo elevado recebe trabalho por arquivo, e o arquivo carrega
`{acao, params}`, nunca PowerShell. Duas listas decidem o que é aceito:

| lista | onde | papel |
| --- | --- | --- |
| `WinAction` | PHP, `src/Win/WinAction.php` | primeira barreira: recusa no servidor o que o formulário mandou errado, com mensagem legível |
| `$ALLOWLIST` | `src/Win/worker.ps1`, em integridade Alta | a tranca: última validação antes de executar, e cobre quem escrever no arquivo de trabalho sem passar pela tela |

Cada ação declara os parâmetros que aceita e os valores permitidos; o que não
estiver declarado é recusado antes de executar. Campo de texto livre tem teto
de 4096 bytes nas duas listas. Um teste de paridade exige que as duas conheçam
as mesmas treze ações.

Os valores validados entram no script gerado como dado, num JSON em base64, e
chegam na ação como parâmetro; nada do que a tela manda vira código.

O que a allowlist **não** faz: limitar a consequência de uma ação legítima. A
instalação continua instalando qualquer coisa que o winget ofereça. E quem puder
reescrever `src/Win/worker.ps1` é dono da próxima elevação, do mesmo jeito que
quem puder reescrever `src/` é dono da aplicação.

O PHP roda em integridade Média e não consegue encerrar o processo elevado.
Desligar e cancelar chegam como ordem, num arquivo que o próprio processo
elevado lê e obedece.

### A janela do processo elevado

O nonce que o processo elevado exige em cada trabalho fica em
`storage/win-elevation.json`, que qualquer processo do mesmo usuário lê —
inclusive o WSL. Enquanto o processo elevado estiver de pé, quem ler o nonce
consegue mandar trabalho para ele, dentro da allowlist. Para encurtar essa
janela, o processo sai sozinho depois de 600 s sem ação em andamento
(`$IDLE_TIMEOUT_S` no `worker.ps1`, repetido em `Elevation::IDLE_TIMEOUT_S`; um
teste confere os dois).

E o que se consegue por essa janela ficou menor: o processo elevado longo
**recusa as ações sensíveis** (instalar, ligar o RDP, abrir porta no firewall),
com código 126 e o motivo "acao sensivel". Cada uma delas pede um prompt de UAC
próprio — ver [Ações sensíveis: UAC por execução](#ações-sensíveis-uac-por-execução).

Com `ConsentPromptBehaviorAdmin` em 0, a elevação não pede confirmação, e um
processo Médio consegue elevar sozinho de qualquer jeito. Nesse caso nenhuma
dessas barreiras protege contra quem já roda como o usuário, e a tela recusa as
ações sensíveis em vez de fingir que pediu confirmação.

### Conclusão só com id válido

O processo elevado devolve cada execução num `win-done-<id>.json`, e o id vira
parte do caminho dos arquivos de saída que o PHP lê e apaga. O PHP só aceita id
de 12 dígitos hexadecimais, o formato que o processo elevado gera, e confere
isso antes de montar qualquer caminho. Um arquivo de conclusão com id fora do
formato é ignorado e nunca apaga nem lê nada fora de `files/win-protected/`.

### O que roda elevado é o que estava lá ao ligar

`src/Win` é gravável por qualquer processo do usuário, e o processo elevado
carrega dali o `bootstrap.ps1`, as ações, o `lib/`, os JSON de `config/` (o
`tweaks.json` carrega PowerShell) e o `audit/audit.ps1`. Ao ligar, o PHP grava
o SHA-256 de cada um em `files/win-manifesto.json` (`WinManifest`) e passa o
SHA-256 desse arquivo na linha de comando do worker. O worker só aceita o
manifesto cujo hash for esse e daí guarda o mapa na memória: o que muda em
disco depois disso não muda o mapa. Vai o hash, e não o mapa inteiro, porque o
`-Verb RunAs` passa pelo `ShellExecuteEx`, que pode cortar a linha em ~2048
caracteres sem avisar, e o mapa em base64 passa de 4 KB.

Cada script gerado leva o mapa e uma cópia de `Read-PhportoConferido`. Essa
função lê os bytes do arquivo uma vez, confere o SHA-256 desses bytes e devolve
o texto deles, que é o que entra; nenhum arquivo é carregado pelo caminho
depois de conferido. Ela confere o bootstrap, o bootstrap confere `lib/`,
`actions/` e `config/`, e o `Invoke-Audit` confere o `audit.ps1`. Arquivo que
mudou, sumiu ou não estava no mapa é recusado, e a ação não roda.

### A pasta protegida

O script gerado roda como Administrador. Em `files/`, qualquer processo do
usuário podia trocá-lo entre o worker escrever e o filho ler. Agora o worker
grava os scripts e os resultados em `files/win-protected/`, com ACL própria,
sem herança de `files/`:

| quem | pode |
| --- | --- |
| Administradores, SYSTEM | tudo |
| o usuário do `php -S` | ler, e apagar arquivo — é o que o PHP faz com a conclusão depois de gravar no banco |
| OWNER RIGHTS | ler: o dono de um arquivo não ganha `WRITE_DAC` implícito |

A pasta nasce com Administradores de dono e com a ACL já aplicada, e a ACL é
refeita a cada subida. Pasta que já exista e seja link ou junção, ou tenha
outro dono, é recusada, e o worker não sobe. Enquanto vive, o worker mantém
aberto o arquivo `win-trava` lá dentro: o Windows não renomeia nem move pasta
com arquivo aberto dentro, e sem isso quem escreve em `files/` poderia trocar a
pasta inteira por outra. A conferência que vale é a feita com a trava já aberta.

A trava abre com acesso de **leitura** e compartilha leitura e escrita, mas não
exclusão. Assim o worker longo e um worker de uso único seguram a mesma pasta
ao mesmo tempo (antes, a trava abria sem compartilhar nada, e o segundo
falhava), e o que impede a troca da pasta continua de pé: nenhum dos dois
compartilha exclusão.

O usuário que recebe leitura é o dono do processo do `php -S`, e não o do
worker: com elevação "por cima do ombro" (usuário padrão digitando a senha de
um admin), o worker roda como o admin.

### O que a conferência não cobre

- **Arquivo trocado antes de ligar.** O manifesto confia no que está em
  `src/Win` na hora de ligar. Quem trocou um arquivo antes disso entra no mapa
  como se fosse o certo, do mesmo jeito que um `worker.ps1` reescrito antes de
  ligar é dono da elevação.
- **O `worker.ps1` e o lançador.** O worker longo não confere a si mesmo, e o
  `files/win-launcher.ps1` roda em integridade Média. Trocar qualquer um dos dois
  antes de ligar dá o mesmo que pedir uma elevação por conta própria. Na ação
  sensível é diferente: o stub do `-EncodedCommand` confere o SHA-256 do
  `worker.ps1` tirado no clique, e um `worker.ps1` trocado entre o clique e o
  prompt sai com 97 sem rodar nada. Trocado **antes** do clique, entra como
  certo.
- **UAC desligado.** A ACL só separa o processo Médio do elevado porque, com
  UAC, Administradores entra só para negar no token Médio. Com `EnableLUA` em 0
  o usuário já é Administrador pleno, e a pasta não o barra.
- **Pasta criada antes do worker.** O PHP não confere o dono da pasta
  protegida. Se ela ainda não existir, um processo do usuário pode criá-la com
  conclusões forjadas, que o PHP lê; o worker recusa subir sobre ela, mas o
  recolhimento de órfãs da `/win` já as terá visto. Fecha com o registro de ids
  pendentes, que é outra etapa.
- **Os executáveis baixados.** O WinMemoryCleaner é conferido logo antes de
  rodar, mas fica em `src/Win/tools/`, gravável, entre a conferência e a
  execução. O MSI do windows_exporter e o exportador da GPU, em `runtime/`, não
  têm hash fixado.

### O executável baixado

O WinMemoryCleaner é baixado na primeira execução da ação Memória e roda como
Administrador. O SHA-256 da versão fixada está no `Invoke-Memory.ps1`; um
arquivo que não bata é apagado antes de rodar, tanto logo depois do download
quanto quando ele já estava em `src/Win/tools/`.

## Ações sensíveis: UAC por execução

Com o processo elevado longo de pé, quem lê o nonce manda trabalho para ele sem
prompt nenhum. Para a maioria das ações isso é aceitável — elas leem estado, ou
desfazem. Para algumas, não. Essas são as **sensíveis**, e cada execução delas
pede um prompt de UAC próprio:

| ação | subações |
| --- | --- |
| `install` | todas (o winget instala qualquer coisa do catálogo) |
| `rdp` | `on` (abre o acesso remoto e a porta no firewall) |
| `sunshine` | `install`, `firewall-open`, `set-creds` (troca a senha da Web UI), `pair` (autoriza um dispositivo a ver e controlar a tela) |
| `exporter` | `install`, `firewall` |
| `gpu` | `install` (cria uma tarefa agendada como SYSTEM) |

A lista mora em dois lugares, `WinAction::SENSITIVE` (PHP, escolhe o caminho) e
`$SENSIVEIS` (`worker.ps1`, a tranca), e um teste confere que são a mesma.
`network`, `optimize -Undo`, `dns` e os ajustes de Defender/BitLocker ficam de
fora: reduzem a exposição, só leem, ou ficam para outra etapa.

### O fluxo

1. O PHP grava o **pedido** em `files/win-oneshot-<id>.json`: a ação, os
   parâmetros já validados, o PID do `php -S`, a pasta de trabalho, o prazo e o
   manifesto de `src/Win` tirado **no clique**. O id é do PHP, e é por ele que
   o resultado volta.
2. Um lançador em integridade Média chama `Start-Process powershell.exe -Verb
   RunAs` com um stub em `-EncodedCommand`. O stub leva só dois caminhos que o
   PHP escreveu e dois SHA-256 — o do `worker.ps1` e o dos bytes do pedido. O
   texto que a pessoa digitou nunca vai na linha de comando.
3. O prompt aparece. Recusado, sem resposta em 60 s ou com erro do Windows, o
   PHP apaga o pedido e diz que nada foi executado. Aceitar o prompt depois não
   roda nada: o pedido sumiu, e ele expira em 75 s de qualquer jeito.
4. O stub lê o `worker.ps1` uma vez, confere o hash desses bytes e roda o texto
   deles. O worker, no modo de uso único, confere o hash do pedido, **apaga o
   pedido** (um pedido serve uma vez), confere o resto (id, prazo, se o
   `php -S` ainda existe), prepara a pasta protegida e avisa o PHP com
   `ACEITO` em `files/win-oneshot-<id>.estado`.
5. A ação roda pela mesma allowlist e pelo mesmo script gerado do worker longo.
   O resultado chega pela pasta protegida, pelo id; o cancelamento por tempo vai
   numa ordem só daquele id (`win-ordem-cancelar-<id>`), que não afeta o worker
   longo. Feita a ação, o processo elevado sai.

A ação sensível **não depende do processo elevado longo**: roda com o
interruptor desligado. Só depende do checkout de `src/Win` e de a política do
UAC garantir um prompt.

### A política do UAC

O PHP lê `HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Policies\System`
com `reg.exe` na `/config` e a cada ação sensível (`UacPolicy`), e recusa a ação
quando não haveria prompt:

| política | ação sensível |
| --- | --- |
| `EnableLUA` = 1 e `ConsentPromptBehaviorAdmin` entre 1 e 5 | roda, com prompt |
| `ConsentPromptBehaviorAdmin` = 0 (eleva sem perguntar) | recusada |
| `EnableLUA` = 0 (UAC desligado) | recusada |
| política ilegível | recusada |

Não há escape pelo `.env`. O alvo é o **padrão do Windows**, `CPBA = 5`
("Solicitar consentimento para binários não Windows"), e quem muda é a pessoa,
à mão — uma ação do PHPorto que escrevesse política de UAC seria ruim de raiz.
Em `secpol.msc` → Políticas Locais → Opções de Segurança, "Controle de Conta de
Usuário: Comportamento do prompt de elevação para administradores no Modo de
Aprovação de Administrador", ou num prompt elevado:

```
reg add "HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Policies\System" /v ConsentPromptBehaviorAdmin /t REG_DWORD /d 5 /f
```

Vale na hora, sem reiniciar. Ligar o UAC (`EnableLUA` = 1) exige reiniciar.

**Endurecimento opcional: `CPBA = 2`** ("Sempre notificar", na área de trabalho
segura). No CPBA 5, binários do Windows com auto-elevação deixam um processo
Médio de um administrador elevar sem prompt; é a família de bypass conhecida, e
a própria Microsoft não trata o UAC como fronteira nesse nível. O CPBA 2 fecha
essa família, ao custo de um prompt também para as configurações do Windows. Não
é exigido.

A leitura da política roda em integridade Média e pode ser forjada por um
processo Médio. Não importa: com CPBA 0 ou UAC desligado esse processo já eleva
sozinho. A checagem existe para a tela não mentir; a tranca é o próprio UAC,
mais o worker longo recusando as sensíveis.

### O que o uso único não cobre

- **No CPBA 5, o UAC não é fronteira.** O uso único fecha o canal barato (ler o
  nonce e mandar trabalho para o worker longo) e garante um prompt visível por
  ação; não fecha um atacante Médio determinado. Só o CPBA 2 fecha a
  auto-elevação.
- **O prompt não diz qual ação está sendo aprovada.** Ele mostra "Windows
  PowerShell" e, nos detalhes, um base64. A pessoa correlaciona com o próprio
  clique. O hash amarra o que ela aprovou ao que roda; um prompt forjado por
  terceiro é outro prompt, fora de hora.
- **Arquivo trocado antes do clique** entra como certo (`worker.ps1`,
  `src/Win`). A janela agora começa no clique, e não na hora de ligar.
- **Aceite tardio.** Na corrida estreita em que o uso único lê o pedido um
  instante antes de o PHP desistir e apagá-lo, a ação roda e vira órfã,
  recolhida na próxima `/win` com nota.
- **Pasta protegida pré-criada**, como no worker longo: uma conclusão forjada
  com o id (visível no nome do pedido) seria lida como sucesso.
- **Negação de serviço do lado Médio** é inevitável: apagar o pedido, segurar a
  `win-trava` sem compartilhar, forjar `ERRO=` no `.estado`. Só nega execução.
- **As ações em si não ficam mais seguras.** A tarefa SYSTEM do `gpu install`
  aponta para um exe em `runtime/`, gravável pelo usuário; o MSI do exporter não
  tem hash; o winget é aberto; o WinMemoryCleaner tem janela entre conferir e
  rodar; o `tshark` vem do PATH; o `optimize -Undo` lê arquivo gravável. O uso
  único só garante que cada execução sensível teve prompt próprio.
- **Caminho longo.** O `-Verb RunAs` corta a linha em ~2048 caracteres sem
  avisar. Acima de 1900, o PHP recusa antes de abrir o prompt e pede para mover
  o projeto para um caminho mais curto.

### Sunshine: senha da Web UI e PIN do Moonlight

`sunshine set-creds` grava o usuário e a senha da Web UI do Sunshine, e
`sunshine pair` pareia um Moonlight pelo PIN (opcionalmente gravando as
credenciais antes, no mesmo UAC). As duas são sensíveis: a primeira troca a
credencial de administração do Sunshine, a segunda autoriza um cliente novo a
ver e controlar o desktop — a mesma classe do `rdp on`. O prompt é a confirmação
humana; sem ele, uma sessão do PHPorto sequestrada pareava um dispositivo
estranho em silêncio.

O PHPorto **pede** o PIN, mas **não guarda** senha nem PIN em repouso:

| onde | o que fica |
| --- | --- |
| formulário | `POST` no corpo, nunca na URL; senha em `type=password`; os campos voltam vazios (o script do sem-reload limpa senha e PIN) |
| mensagens de validação | citam o nome do campo, nunca o valor |
| histórico (`Pages::describe`) | `-Password *** -Pin ***` (`WinAction::SECRET_PARAMS`), também no recolhimento de órfãs |
| pedido `files/win-oneshot-<id>.json` | **em claro** enquanto o UAC está na tela (até ~75 s); apagado pelo worker ao consumir e pelo `finally` do PHP. Com o UAC fraco o PHP recusa **antes** de gravar o pedido |
| linha de comando do processo elevado | só caminhos e hashes, como em toda ação sensível |
| `win-exec-<id>.ps1` (pasta protegida) | os parâmetros em base64 enquanto o filho roda; sai no fim |
| `win-done-<id>.json` (pasta protegida) | `***` no lugar de `Password` e `Pin` (`$SEGREDOS` do worker) |
| `win-worker.log` | só ação e id, como sempre |
| saída da ação | nunca a senha, o PIN ou o cabeçalho `Authorization`; a saída do `sunshine.exe --creds` é descartada |

**A conexão com o Sunshine** vai só para `https://127.0.0.1:47990`, sem
parâmetro de host, e o certificado do outro lado tem de ter o mesmo SHA-256 do
`config\credentials\cacert.pem` da instalação. O callback de certificado é da
requisição, não do processo; nada de "aceitar qualquer certificado". Sem proxy,
sem redirect automático. A instalação tem de estar sob `Program Files` (a pasta
sai do caminho do serviço `SunshineService`), senão a ação recusa.

**Riscos aceitos:**

- **A senha na linha de comando do `sunshine.exe --creds`.** É a única interface
  do Sunshine para trocar a senha sem saber a atual. O processo é de
  integridade Alta e vive menos de um segundo, mas a auditoria de criação de
  processo (evento 4688 com linha de comando), o Sysmon e antivírus/EDR podem
  registrá-la.
- **Segredo em arquivo legível pelo próprio usuário por pouco tempo**: o pedido
  durante o prompt e o `win-exec-<id>.ps1` durante a execução. Contra processo
  do mesmo usuário isso não é fronteira (ele também lê a memória do `php -S`).
  O que se garante é nada em repouso: banco, log, `win-done` e pedido recusado
  ficam sem segredo.
- **A API do Sunshine muda.** Desde a v2026.906 o pareamento exige o
  `pairing_id` do pedido pendente; a ação detecta a versão pelo `GET /api/pin`
  (200 com a lista = nova, 404 = antiga). Uma mudança futura quebra com
  mensagem, não em silêncio.

## O `.env` não é escrito pela web

O processo web nunca escreve no `.env`, que guarda credencial de banco. O que a
tela `/config` alterna vai para `storage/flags.json`, que só contém booleanos e
tem allowlist de chaves. A única escrita no `.env` é a do `php token.php`,
rodado à mão.

## O que fica gravado

Toda saída vira registro no banco, em texto puro, sem cifra e sem prazo. Um
`cat` num `.env` de outro projeto grava a credencial no histórico. Se um segredo
passar por engano, apague os registros e considere-o vazado.

Os arquivos de estado local — `storage/flags.json`, `storage/win-elevation.json`,
`storage/auth-falhas.json`, o banco SQLite, `files/` e `runtime/` — ficam fora
do repositório pelo `.gitignore`.
