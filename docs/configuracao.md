# Configuração

A configuração vem do `.env` na raiz do projeto, que não é versionado. O
`.env.example` traz todas as chaves; copie-o para `.env` e ajuste.

O formato é o do phpdotenv: uma variável por linha, `CHAVE=valor`; linha com
`#` é comentário; aspas duplas em volta do valor são opcionais.

`src/Config/Config.php` é o único ponto da aplicação que lê o ambiente. Um teste
(`tests/Unit/EnvExampleTest.php`) confere que toda chave lida está no
`.env.example` e que toda chave do exemplo é lida por alguém.

O processo web nunca escreve no `.env`. A única exceção é o `php token.php`,
rodado à mão na linha de comando.

## Acesso

### `PHPORTO_AUTH_TOKEN`

O token de acesso, enviado no campo de senha do HTTP Basic pelo navegador e pela
API.

- **Formato:** texto. O `php token.php` gera 32 bytes aleatórios em hexadecimal
  (64 caracteres), grava aqui e imprime. Se já houver um valor, pede
  confirmação antes de trocar.
- **Se faltar:** vazio ou ausente, a aplicação responde **503** a tudo — telas e
  API, com credencial ou sem —, dizendo para rodar `php token.php`. A falha cai
  para o lado fechado: um `.env` incompleto não vira uma ferramenta de shell
  aberta.

Trocar o token derruba quem usava o anterior e desfaz um bloqueio por
tentativas erradas. Ver [seguranca.md](seguranca.md).

## WSL

### `PHPORTO_WSL_ROOT`

O diretório onde toda execução do WSL começa, **visto de dentro do WSL** (por
exemplo `/home/usuario/projetos`).

- **Formato:** caminho absoluto do Linux.
- **Se faltar:** nada é executado. A tela `/wsl` e a API recusam dizendo que a
  chave está vazia.

Não tem padrão de propósito: um padrão faria o comando cair no home do usuário
do WSL sem ninguém notar, e um comando relativo agiria na pasta errada.

### `PHPORTO_DISTRO`

A distro passada ao `wsl.exe -d`.

- **Formato:** o nome exato, como aparece em `wsl -l -q`.
- **Se faltar:** `Debian`.

Se a distro não aparecer em `wsl -l -q`, o botão do WSL na home desabilita e diz
o motivo.

### `PHPORTO_TIMEOUT`

Segundos até um comando do WSL ser morto.

- **Formato:** inteiro.
- **Se faltar:** `120`. Valor abaixo de **30** é elevado a 30.

O piso existe porque acordar a VM do WSL custa cerca de 4,5 s — medido: 4543 ms
com a VM fria, 88 ms depois —, e ela dorme sozinha. Com 3 ou 5 segundos, o
primeiro comando de cada dia falharia sempre, e o erro pareceria do comando, não
da configuração.

### `PHPORTO_TZ`

O fuso em que as datas aparecem na tela.

- **Formato:** identificador IANA, como `America/Sao_Paulo`.
- **Se faltar ou for inválido:** `America/Sao_Paulo`.

O banco grava sempre em UTC; o fuso vale só para exibir. O JSON que o botão
"Copiar como JSON" entrega, e o que a API devolve, ficam em UTC.

## Windows

### `PHPORTO_WINUTIL_TIMEOUT`

Segundos até uma ação do Windows ser cancelada.

- **Formato:** inteiro.
- **Se faltar:** `600`. Valor abaixo de **60** é elevado a 60.

O piso é mais alto que o do WSL porque ações longas são a regra: a auditoria
gera oito blocos de log, a captura de rede dura o tempo pedido e ainda monta o
relatório, a instalação chama o winget, que baixa, e a remoção de apps percorre
os pacotes um a um. Uma instalação interrompida deixa o sistema num estado que a
ferramenta não sabe descrever.

A espera é síncrona: a aba fica parada até a ação terminar, e é isso que garante
que a execução chega ao banco. Como o `php -S` atende em série, uma ação longa
congela as outras telas enquanto roda.

A chave mantém `WINUTIL` no nome por herança do projeto de onde as ações
vieram. Renomear obrigaria quem já tem um `.env` a editá-lo para não perder o
valor.

Não há outra chave do Windows: as ações moram em `src/Win/` e não há caminho
externo a apontar.

## Superfícies

As duas chaves booleanas aceitam `true`/`false`, `1`/`0`, `yes`/`no` e
`on`/`off`, sem diferença de caixa. Qualquer outro valor mantém o padrão, para
que um erro de digitação não ligue uma superfície que nasce desligada.

### `PHPORTO_DASHBOARD_ENABLED`

As telas `/`, `/config` e `/wsl`.

- **Se faltar:** `true`.
- **Desligada:** as três respondem 404.

### `PHPORTO_API_ENABLED`

A API JSON em `/api/executions`. Ver [api.md](api.md).

- **Se faltar:** `false`.
- **Desligada:** `/api/*` responde 404.

Nasce desligada: quem clona não ganha uma superfície de execução por HTTP sem
ter pedido. Responde 404, e não 403, porque 403 confirmaria que existe algo
desligado ali.

A tela `/config` também alterna esta chave, gravando em `storage/flags.json`. A
precedência é:

```
storage/flags.json  vence  .env  vence  o padrão do código
```

Se o `flags.json` sumir ou estiver ilegível, vale o `.env`. "Restaurar
configurações de fábrica", na `/config`, apaga o `flags.json`.

### `CORS_ORIGIN`

A origem aceita pela API. Só vale para `/api/*`; as telas são mesma origem.

- **Formato:** esquema, host e porta, sem barra no fim.
- **Se faltar:** `http://127.0.0.1:4001`.

O padrão é a origem do servidor documentado, e não `*`: um padrão permissivo
seria a política publicada para quem clonasse sem criar o `.env`.

## Banco

Cada execução vira uma linha: comando, saída, código de saída, duração, tipo
(comando, anexo ou windows), se estourou o tempo, e quando. Não há unicidade:
duas execuções iguais são dois fatos.

Além da tabela de execuções há uma de estado da `/win`, com o mesmo nome mais o
sufixo `_win_state`. O nome é derivado, não configurável. As duas nascem sozinhas
no primeiro acesso, sem migration.

"Restaurar configurações de fábrica", na `/config`, oferece também apagar o
arquivo do banco — o SQLite inteiro, com todo o histórico, sem desfazer; o
banco vazio é recriado no acesso seguinte. A opção só aparece com
`DB_PROVIDER=sqlite`: nos outros bancos, apagar seria derrubar um schema que a
ferramenta não criou.

### `DB_PROVIDER`

- **Formato:** `sqlite`, `mysql` ou `mongo`.
- **Se faltar:** `sqlite`.
- **Outro valor:** erro "DB_PROVIDER desconhecido" ao abrir o banco.

### `SQLITE_PATH`

O arquivo do SQLite.

- **Formato:** caminho relativo à raiz do projeto, ou absoluto. Absoluto vale com
  as duas barras (`C:\dados\x.sqlite` ou `C:/dados/x.sqlite`), e caminho que
  começa com `\` também (UNC, como `\\servidor\pasta\x.sqlite`).
- **Se faltar:** `storage/database.sqlite`.

O arquivo nasce sozinho; a **pasta tem de existir**. Pasta ausente é recusada
com a mensagem "A pasta do banco não existe", em vez de criar um banco vazio
numa árvore que ninguém pediu — um banco vazio diria, em silêncio, que nada
executou.

### `SQLITE_TABLE`

- **Se faltar:** `executions`.

### `MONGODB_URI`, `MONGO_DB_NAME`, `MONGO_COLLECTION`

Só com `DB_PROVIDER=mongo`. Exigem o pacote `mongodb/mongodb` e a extensão
`ext-mongodb`, que não vêm instalados.

- **Se faltar:** URI e banco vazios; coleção `executions`.

### `MYSQL_HOST`, `MYSQL_PORT`, `MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD`, `MYSQL_TABLE`

Só com `DB_PROVIDER=mysql`.

- **Se faltar:** `localhost`, `3306`, banco, usuário e senha vazios, tabela
  `executions`.

## MySQL de teste

### `MYSQL_TEST_HOST`, `MYSQL_TEST_PORT`, `MYSQL_TEST_DATABASE`, `MYSQL_TEST_USER`, `MYSQL_TEST_PASSWORD`

Lidas **do ambiente** pelos testes de integração do MySQL
(`tests/Integration/MySQLProviderTest.php`). A suíte não lê o `.env`: exporte as
variáveis antes de rodar, por exemplo no PowerShell:

```powershell
$env:MYSQL_TEST_DATABASE="phporto_teste"; $env:MYSQL_TEST_USER="usuario"
```

- **Se faltar:** sem `MYSQL_TEST_DATABASE` e `MYSQL_TEST_USER`, esses testes são
  pulados e a suíte segue verde. Host e porta caem em `127.0.0.1` e `3306`.

Os testes usam uma tabela própria, criada e apagada por eles, nunca a
`MYSQL_TABLE`.
