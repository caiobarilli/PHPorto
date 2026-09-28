# Desenvolvimento

## Árvore

```
public/              único diretório servido pela web
  index.php          front controller, com o portão de token
  router.php         porteiro do php -S: lista de permissão de rotas
src/                 todo o código PHP (PSR-4, namespace App\)
  bootstrap.php      carrega o .env e monta a aplicação
  Config/            Config (único leitor do ambiente), Flags, EnvFile
  Domain/            Execution, ExecutionKind, OutputCap, WinState
  Exceptions/        StorageException
  Http/              rotas, autenticação, CSRF, API e os view-models
  Providers/         SQLite (padrão), MySQL e MongoDB
  Services/          ExecutionLogService
  Views/             templates das telas
  Win/               o motor do Windows: PHP e PowerShell lado a lado
    worker.ps1       o processo elevado, com a allowlist que tranca
    bootstrap.ps1    monta o ambiente das ações e despacha por nome
    actions/         as treze ações, um Invoke-*.ps1 cada
    lib/             primitivas do WinUtil (upstream)
    config/          dns, preset e tweaks (upstream); debloat e tweaks.pt-BR (do projeto)
    audit/           audit.ps1
    tools/           binários baixados na primeira execução (não versionados)
  Wsl/               Runner, ScriptBuilder, Distro, InputLimit
storage/             banco SQLite, flags.json, marcador da elevação, contador de tokens
files/               canal de trabalho: cmd.sh e os arquivos do worker
runtime/             o que as ações do Windows criam enquanto rodam
tests/
  Unit/              Pest, sem processo externo
  Integration/       Pest, com php -S, SQLite em arquivo e MySQL opcional
  Pester/            testes PowerShell do bootstrap, do worker e das ações
  Fakes/, Support/   provider falso e o auxiliar que sobe o php -S
tools/
  cs-check-lf.php    o PHP-CS-Fixer sobre uma cópia em LF
token.php            gera o token de acesso
docs/                esta documentação
```

`storage/`, `files/` e `runtime/` existem no clone por um `.gitkeep`; o
conteúdo não é versionado.

## O portão

```bash
composer gate
```

Roda, em ordem, e para no primeiro que falhar:

| comando | o quê |
| --- | --- |
| `composer test` | suíte Pest |
| `composer stan` | PHPStan em level max, com `phpVersion` 8.5 |
| `composer cs-check-lf` | PHP-CS-Fixer em modo de conferência, sobre uma cópia em LF |
| `composer pester` | testes PowerShell (`Invoke-Pester ./tests/Pester -CI`) |

`composer cs-fix` aplica o PHP-CS-Fixer na árvore real.

O projeto não tem CI. A maior parte das ações do Windows exige Administrador e
mexe na máquina de verdade; num runner hospedado elas seriam puladas, e o verde
seria sobre o que menos importa.

### Por que `cs-check-lf` e não `cs-check`

A árvore de trabalho tem `.php` em CRLF (`core.autocrlf=true`), e o PSR-12 exige
LF. O `cs-check` direto acusa a maior parte dos arquivos, cada um com o arquivo
inteiro no diff. Desligar a regra `line_ending` não resolve: as regras que mexem
no bloco de abertura reescrevem aquele trecho em LF e deixam o arquivo misto. O
`cs-check-lf` converte uma cópia para LF, roda o fixer nela e descarta a cópia.
Detalhes em `tools/cs-check-lf.php`.

## Testes

- **Pest** (`tests/Unit`, `tests/Integration`): `composer test`, ou
  `vendor/bin/pest caminho/do/Teste.php` para um arquivo.
- **Pester** (`tests/Pester`): `composer pester`. Exige Windows PowerShell 5.1 e
  o módulo Pester 5. Os testes rodam sem elevação; o que exige Administrador é
  simulado com `Mock`.
- **MySQL**: os testes de integração do MySQL são pulados sem as variáveis
  `MYSQL_TEST_*` no ambiente. Ver [configuracao.md](configuracao.md).
- **MongoDB**: não há teste. O driver só foi verificado com `php -l`.

**Variável de ambiente vazia não chega ao processo filho no Windows.** Passada
ao `proc_open` como `CHAVE=''`, ela some: o filho vê `getenv()` como `false`,
e "vazia" fica indistinguível de "ausente". Um teste que tente zerar uma chave
assim acaba medindo o `.env` de quem roda. Para subir o servidor com uma
configuração conhecida, use `Tests\Support\PhpServer`, que monta uma raiz
temporária com `.env` próprio e tira do ambiente herdado as chaves que esse
`.env` define.

Nenhum teste eleva nada nem abre prompt de UAC. O caminho de ligar o PowerShell
elevado de verdade é verificado à mão.

## Encoding dos arquivos

Duas regras opostas convivem, para arquivos diferentes:

| arquivo | fim de linha | BOM |
| --- | --- | --- |
| `.ps1` escrito pelo projeto (`worker.ps1`, `bootstrap.ps1`, testes Pester, scripts gerados) | CRLF | com |
| `files/cmd.sh`, gerado a cada execução do WSL | LF | sem |
| `src/Win/lib/*.ps1` e os JSON do upstream | CRLF | sem, byte a byte da origem |

**Por que CRLF com BOM no `.ps1`:** o Windows PowerShell 5.1 lê script sem BOM
como ANSI. E o dano não para na acentuação: medido, num arquivo UTF-8 sem BOM o
travessão (U+2014, bytes `E2 80 94`) é lido como três caracteres ANSI, e o
último, `0x94`, é a aspa curva de fechamento, que o PowerShell trata como
delimitador de string. Um travessão dentro de string dupla **quebra o parser**,
com erro apontando para longe da causa. Com BOM, isso não acontece.

**Por que LF sem BOM no `cmd.sh`:** o bash engasga com `\r` e com BOM.

Os arquivos de `src/Win/lib/` e três dos JSON de `src/Win/config/` são do
upstream e ASCII puro; ficam sem BOM para continuarem iguais à origem (ver
[terceiros.md](terceiros.md)). `Invoke-Gdid.ps1` e `Invoke-Optimize.ps1` também
estão sem BOM, com bytes acima de `0x7F` só em comentário; um teste que parseia
todo `Invoke-*.ps1` falha se alguém puser um travessão dentro de string neles.

O `.gitattributes` tem regras estreitas: `*.ps1 -text` e os três JSON do
upstream com `-text`, para o git gravar e devolver os bytes como estão em
qualquer clone. Uma regra ampla (`text=auto`) renormalizaria a árvore inteira.

**Cuidado com `sed -i`:** o do Git Bash devolve o arquivo em LF. Num `.ps1`,
com `-text`, o LF entraria no repositório com o arquivo inteiro no diff. Edite
`.ps1` com uma ferramenta que preserve o fim de linha, e confira com
`git diff --stat` antes de commitar.

## Mensagens de commit

O `git log` é o registro de decisão do projeto: cada mensagem guarda por que a
coisa é como é, com a medição que sustentou a escolha e os custos aceitos. Vale
ler antes de desfazer algo que pareça estranho.

A mensagem vai por arquivo, nunca colada no terminal:

```bash
git commit -F .git/COMMIT_MSG.txt
git log -1 --format=%B    # confere o que entrou
```

Quatro mensagens antigas perderam texto quando eram coladas, e uma foi enviada
sem corpo. O histórico publicado não é reescrito; a [errata](ERRATA.md) nomeia
cada commit e restaura o sentido do que se perdeu.

Comentário de código diz o que a função faz, o que recebe e o que retorna.
Decisão, medição e custo aceito ficam na mensagem de commit.
