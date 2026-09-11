# PHPorto

A doca entre o Windows e o WSL. Recebe um comando pelo navegador, executa dentro
da distro, devolve a saída e guarda o registro no banco.

---

## ⚠️ Leia antes de subir

**Esta ferramenta executa comando de shell arbitrário na máquina de quem a sobe,
com os privilégios do usuário do WSL. Não há autenticação, e isso é de propósito:
é ferramenta local, de uma pessoa só.**

Por isso ela **deve** escutar apenas em `127.0.0.1`. Em `0.0.0.0`, no IP da rede,
ou atrás de um proxy que a exponha, qualquer um que alcance a porta ganha execução
remota de comando na sua máquina — sem senha, sem saber quem foi, sem limite do que
pode rodar. Não é "menos segura": é acesso remoto irrestrito.

**Nada de segredo por aqui.** Toda saída vira registro no banco, em texto puro,
sem cifra e sem prazo. Um `cat` num `.env` de outro projeto grava a credencial no
log. Se um segredo passar por engano, apague os registros e considere-o vazado.

## Requisitos

- **PHP 8.5** na linha de comando, com `json`, `pdo`, `pdo_sqlite` e `pdo_mysql`
- **WSL2** com uma distro instalada — precisa aparecer em `wsl -l -q`
- **Composer**

Desenvolvido e testado em **Windows 11**. O WSL2 também roda em Windows 10, mas
não foi testado lá.

## Instalação

```bash
composer install
```

Copie `.env.example` para `.env` e preencha `PHPORTO_WSL_ROOT` com a pasta onde
suas execuções devem começar, **vista de dentro do WSL**. É a única variável
obrigatória: sem ela nada roda.

## Subir

```bash
php -S 127.0.0.1:4001 -t public public/router.php
```

E abra <http://127.0.0.1:4001>.

### O `-t public` é a trava principal

`public/` é o **único** diretório servido pela web. `src/`, `storage/`, `files/` e
o `.env` ficam fora dele — não existe URL que os alcance. Medido: com a raiz do
projeto como document root, `GET /.env` devolvia o arquivo e
`GET /storage/database.sqlite` devolvia os 16 KB do banco com tudo que havia sido
executado; com `-t public`, os dois são 404 por **não existirem** ali.

Defende-se com geografia o que não se deve defender com expressão regular:
`/src/Config/Config.php` não é dotfile, e passaria por qualquer regra escrita
para dotfiles.

### O `router.php` é a segunda camada

Ele continua obrigatório, por dois motivos menores mas reais: barra dotfiles que
apareçam dentro de `public/` (decodificando o caminho **antes** de comparar, senão
`/%2Eenv` passa), e despacha as rotas explicitamente em vez de depender do
fallback do servidor embutido. Como a ferramenta não tem nenhum arquivo estático
— CSS e JS são embutidos na página —, ele é **lista de permissão**: tudo que não é
rota da aplicação é 404.

## Estrutura

```
public/          único diretório servido pela web
  index.php      front controller
  router.php     porteiro do php -S
src/             todo o código (PSR-4, App\)
  Config/        único leitor de env
  Domain/        Execution, ExecutionKind
  Exceptions/    StorageException
  Http/          rotas, views-model, CSRF, API
  Providers/     SQLite (padrão), MySQL, Mongo
  Services/      ExecutionLogService
  Views/         templates
  Win/           o motor do Windows: PHP + PowerShell, lado a lado
    worker.ps1   o processo elevado, com a allowlist que tranca
    bootstrap.ps1  monta o ambiente das ações e despacha por nome
    actions/     as treze ações, um Invoke-*.ps1 cada
    lib/         primitivas do WinUtil (MIT — ver THIRD-PARTY.md)
    config/      dns.json, preset.json, tweaks.json
    audit/       audit.ps1
  Wsl/           Runner, ScriptBuilder, Distro
storage/         o banco — FORA do public
files/           cmd.sh descartável — FORA do public
tests/
  Pester/        testes PowerShell das ações
```

`src/Win/` é o único lugar do projeto onde PHP e PowerShell convivem, e é de
propósito: o que sobe o processo elevado e o que ele executa mudam juntos.
**Não há nada a configurar ali** — as ações vêm no repositório. Até a migração
existia um `PHPORTO_WINUTIL_PATH` obrigatório apontando para um projeto
separado; ele não existe mais.

## As quatro telas

| rota | o que faz |
| --- | --- |
| `/` | Home. Três botões: configuração, WSL e WIN. O do WSL desabilita quando o WSL não está instalado ou a distro do `.env` não aparece em `wsl -l -q`; o do WIN, enquanto o PowerShell elevado estiver desligado. Cada um diz qual é o motivo. |
| `/config` | Mostra o banco ativo, alterna a API, liga o PowerShell elevado e restaura de fábrica. O `.env` **nunca é reescrito** pela web: o que a tela alterna vai para `storage/flags.json`. |
| `/wsl` | O executor do WSL: entrada, saída, card de anexos e a tabela de registros. |
| `/win` | As treze ações do Windows, uma seção cada, executadas por um PowerShell elevado. Nada roda com o interruptor da `/config` desligado. |

O botão do WSL responde **"dá para usar"**, não "está rodando agora". A VM dormir
é normal e ela sobe sozinha no primeiro comando — desabilitar por isso mentiria.

## As duas flags

| variável | padrão | desligada |
| --- | --- | --- |
| `PHPORTO_DASHBOARD_ENABLED` | `true` | `/`, `/config` e `/wsl` respondem 404 |
| `PHPORTO_API_ENABLED` | `false` | `/api/*` responde 404 |

404 e não 403: 403 confirmaria que existe algo desligado ali.

### Ligar pela tela, sem editar arquivo

`PHPORTO_API_ENABLED` também se alterna em `/config`. O que a tela grava vai
para `storage/flags.json`, e a precedência é:

```
storage/flags.json  vence  .env  vence  o padrão do código
```

O `.env` continua somente-leitura para o processo web — ele guarda credencial de
banco, e uma escrita malsucedida ali custa caro demais para o que se ganha.
Ligar abre um aviso explicando que a rota executa comando e que a proteção dela
é só a checagem de origem; desligar não pergunta nada, porque desligar reduz
superfície.

Se o `flags.json` sumir ou corromper, tudo volta ao `.env` — e o `.env` do
projeto traz a API desligada. A falha cai para o lado seguro por construção.

### Restaurar configurações de fábrica

Ainda em `/config`. Apaga o `flags.json`, e a configuração volta a ser
exatamente o que o `.env` diz. No mesmo aviso há uma opção para **apagar também
o arquivo do banco** — o `storage/database.sqlite` inteiro, com todo o
histórico, sem desfazer. O app recria o banco vazio no acesso seguinte.

A opção só aparece no sqlite: nos outros bancos "apagar" seria dropar um schema
que a ferramenta não criou e que pode não ser só dela.

## A API

Só existe com `PHPORTO_API_ENABLED=true`, e ela **nasce desligada** — quem clona
não ganha uma superfície de execução por HTTP sem ter pedido.

```
GET  /api/executions?limit=100   lista os registros
POST /api/executions             executa
```

```bash
curl -X POST http://127.0.0.1:4001/api/executions \
  -H "Content-Type: application/json" \
  -d '{"command":"git status"}'
```

Para anexo, `{"src":"...","dst":"..."}`.

O `Content-Type: application/json` é **obrigatório**, e isso é trava, não
formalidade — veja a seção seguinte.

## Por que o CORS não bastaria

**O CORS não impede a requisição de sair. Ele impede a resposta de ser lida.**

Um formulário em qualquer site que você abrir pode fazer `POST` para
`http://127.0.0.1:4001/wsl`, e o comando **roda**. O atacante não vê a saída — e
não precisa ver: o efeito já aconteceu na sua máquina.

As travas de verdade:

- **Nas telas**, token por sessão no formulário. Um site de terceiro não tem como
  lê-lo — aí sim a política de mesma origem trabalha a nosso favor.
- **Na API**, exigir `Content-Type: application/json` torna a requisição "não
  simples" e obriga o navegador a fazer *preflight*, que o CORS barra antes de
  qualquer efeito. Um formulário HTML não consegue mandar esse `Content-Type`. A
  checagem de `Origin` é a segunda camada.

## O `.env`

Uma variável por linha, `CHAVE=valor`, sem aspas; linha com `#` é comentário. O
`.env` não vai para o repositório — quem documenta é o `.env.example`, que traz
todas as chaves comentadas.

Sobre `PHPORTO_TIMEOUT`: **não use valor baixo.** Acordar a VM do WSL custa cerca
de 4,5 s (medido: 4543 ms com a VM fria, 88 ms depois), e ela dorme sozinha. Com 3
ou 5 segundos, o primeiro comando de cada dia falha sempre — e o erro parece do
comando. O piso aplicado pelo código é 30 s; o padrão é 120 s.

## O banco

Cada execução vira uma linha: comando, saída, código de saída, duração, tipo
(comando ou anexo), se estourou o timeout, e quando. Três providers plugáveis via
`DB_PROVIDER` — **SQLite** (padrão, arquivo local criado on-demand), **MySQL** e
**MongoDB** — sem mudar nenhuma linha de código.

Não há unicidade, e é decisão registrada: duas execuções idênticas são dois
fatos distintos, e a ferramenta existe justamente para registrar que algo foi
feito duas vezes.

### A segunda tabela: o estado da `/win`

Além do histórico há uma tabela de **estado**, com o nome da primeira mais o
sufixo `_win_state` — derivada, não configurável, para não existir uma segunda
variável de ambiente a manter em dia. Ela nasce no mesmo acesso que a outra, sem
migration.

O que ela guarda é uma linha por par *(escopo, ação)*, que se **substitui**:

| escopo | o que é |
| --- | --- |
| `aplicado` | o que esta ferramenta aplicou e ainda não reverteu — é o que faz o botão dizer "reverter" em vez de "aplicar", e é onde fica o `-Preset` que o `-Undo` do `tweaks` exige de volta |
| `selecao` | as caixas que ficaram marcadas na última visita, para não remarcar tudo a cada vez |

**Não é leitura da máquina, e não substitui o histórico.** É memória do que a
tela mandou fazer. Mexer no sistema por fora — `regedit`, o WinUtil original, um
PowerShell elevado à mão — deixa a linha desatualizada, e isso é custo aceito: o
contrário seria sondar a máquina a cada carregamento de página, que é justamente
o que o heartbeat da elevação existe para não pagar.

Duas consequências que valem saber:

- **"Limpar histórico" não mexe nela.** Apagar registros e mudar o que a tela
  afirma sobre a máquina são coisas diferentes, e há teste travando isso.
- **Sem estado salvo, a tela se comporta como antes** e nunca afirma que algo
  está aplicado. Linha ilegível é descartada em vez de derrubar a página — mesma
  regra do `flags.json`.

O "restaurar de fábrica" apaga o arquivo do banco, então leva as duas tabelas.

## Uma pegadinha, para você não perder tempo

A primeira linha do `files/cmd.sh` é um cabeçalho da ferramenta: `exec 2>&1` mais
o `cd` guardado em `PHPORTO_WSL_ROOT`. O `exec 2>&1` é o que faz stdout e stderr
saírem juntos e na ordem real — dois pipes separados no Windows entregariam as
linhas embaralhadas pelo buffer. O efeito colateral é que as mensagens de erro do
bash citam a linha **+1** em relação ao que você digitou: um erro na sua primeira
linha aparece como `cmd.sh: line 2`.

## Comandos

```bash
composer gate         # o portão inteiro: os quatro abaixo, em ordem

composer test         # suíte Pest
composer stan         # PHPStan (level max, phpVersion 8.5)
composer cs-check-lf  # PHP-CS-Fixer sobre cópia em LF, sem escrever nada
composer pester       # testes PowerShell das ações do Windows
composer cs-fix       # PHP-CS-Fixer, escrevendo
```

**Não há CI.** O portão é o `composer gate` que você roda. A maior parte das
ações do Windows exige Administrador e mexe na máquina de verdade; num runner
hospedado elas seriam puladas, e o verde seria sobre o que menos importa.

Use `cs-check-lf`, não `cs-check`. A árvore de trabalho tem `.php` em CRLF
(`core.autocrlf=true`) e o PSR-12 quer LF, então o `cs-check` direto acusa 37
dos 53 arquivos, cada um com o arquivo inteiro no diff. Medido: desligar a
regra `line_ending` **não** resolve — as regras que mexem no bloco de abertura
reescrevem aquele trecho em LF e deixam o arquivo misto. O `cs-check-lf` roda o
fixer sobre uma cópia convertida, e o que sobra no relatório é estilo de
verdade. Detalhes em `tools/cs-check-lf.php`.

## O registro fica nas mensagens de commit

`git log` é a documentação de decisão deste projeto: cada mensagem registra por
que a coisa é como é, com a medição que sustentou a escolha. Vale ler antes de
desfazer qualquer coisa que pareça estranha — provavelmente já foi pesada.

Quatro mensagens perderam texto no caminho até o repositório, e uma foi empurrada
sem corpo nenhum. Como aqui não se faz `amend` nem `rebase` em histórico
publicado, o conserto é aditivo: [`ERRATA.md`](ERRATA.md) nomeia cada commit pelo
hash, mostra onde a costura cedeu e restaura o sentido do que se perdeu.

A causa era o transporte — copiar e colar a mensagem para dentro do terminal. Por
isso **a mensagem vai por arquivo**, e esta é a regra daqui em diante:

```bash
git commit -F .git/COMMIT_MSG.txt   # e confira com: git log -1 --format=%B
```

## Licença

MIT — texto em [`LICENSE`](LICENSE). A escolha é prática: `src/Win/lib/` e
`src/Win/config/` já são MIT do CT Tech Group, e a mesma licença elimina
conflito de cláusula na redistribuição. E a ausência de garantia, numa
ferramenta que executa shell arbitrário como Administrador, não é formalidade:
é o parágrafo que precisa estar escrito.

## Créditos

A tela `/win` executa ações que vieram do
[WinUtil](https://github.com/ChrisTitusTech/winutil), de Chris Titus Tech, pelo
fork sem interface gráfica `winutil-cli`. As partes copiadas estão em
`src/Win/lib/` e `src/Win/config/`, sob **MIT © 2022 CT Tech Group LLC** — texto
íntegro em [`src/Win/LICENSE.winutil`](src/Win/LICENSE.winutil), com o que veio
de onde em [`src/Win/THIRD-PARTY.md`](src/Win/THIRD-PARTY.md).

Binários usados pelas ações não são versionados: cada um é baixado na primeira
execução. Os créditos deles estão no mesmo arquivo.
