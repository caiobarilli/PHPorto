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
  Wsl/           Runner, ScriptBuilder, Distro
storage/         o banco — FORA do public
files/           cmd.sh descartável — FORA do public
tests/
```

## As três telas

| rota | o que faz |
| --- | --- |
| `/` | Home. Dois botões: configuração e WSL. O botão do WSL desabilita quando o WSL não está instalado ou a distro do `.env` não aparece em `wsl -l -q`, dizendo qual dos dois é. |
| `/config` | Mostra o banco ativo. **Só leitura** — editar significaria escrever no `.env` pela web, num projeto que já executa comando arbitrário, para ganhar pouco: quem sobe a ferramenta tem o arquivo aberto no editor. |
| `/wsl` | O executor: entrada, saída, card de anexos e a tabela de registros. |

O botão do WSL responde **"dá para usar"**, não "está rodando agora". A VM dormir
é normal e ela sobe sozinha no primeiro comando — desabilitar por isso mentiria.

## As duas flags

| variável | padrão | desligada |
| --- | --- | --- |
| `PHPORTO_DASHBOARD_ENABLED` | `true` | `/`, `/config` e `/wsl` respondem 404 |
| `PHPORTO_API_ENABLED` | `false` | `/api/*` responde 404 |

404 e não 403: 403 confirmaria que existe algo desligado ali.

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

## Uma pegadinha, para você não perder tempo

A primeira linha do `files/cmd.sh` é um cabeçalho da ferramenta: `exec 2>&1` mais
o `cd` guardado em `PHPORTO_WSL_ROOT`. O `exec 2>&1` é o que faz stdout e stderr
saírem juntos e na ordem real — dois pipes separados no Windows entregariam as
linhas embaralhadas pelo buffer. O efeito colateral é que as mensagens de erro do
bash citam a linha **+1** em relação ao que você digitou: um erro na sua primeira
linha aparece como `cmd.sh: line 2`.

## Comandos

```bash
composer test       # suíte Pest
composer stan       # PHPStan (level max, phpVersion 8.5)
composer cs-check   # PHP-CS-Fixer, sem escrever nada
```
