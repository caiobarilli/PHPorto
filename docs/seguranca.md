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

Com `ConsentPromptBehaviorAdmin` em 0, a elevação não pede confirmação, e um
processo Médio consegue elevar sozinho de qualquer jeito. Nesse caso nenhuma
dessas barreiras protege contra quem já roda como o usuário.

### Conclusão só com id válido

O processo elevado devolve cada execução num `win-done-<id>.json`, e o id vira
parte do caminho dos arquivos de saída que o PHP lê e apaga. O PHP só aceita id
de 12 dígitos hexadecimais, o formato que o processo elevado gera, e confere
isso antes de montar qualquer caminho. Um arquivo de conclusão com id fora do
formato é ignorado e nunca apaga nem lê nada fora de `src/Win/files/`.

### O executável baixado

O WinMemoryCleaner é baixado na primeira execução da ação Memória e roda como
Administrador. O SHA-256 da versão fixada está no `Invoke-Memory.ps1`; um
arquivo que não bata é apagado antes de rodar, tanto logo depois do download
quanto quando ele já estava em `src/Win/tools/`.

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
