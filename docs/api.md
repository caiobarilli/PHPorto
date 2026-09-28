# API

`/api/executions` lista as execuções gravadas e executa comandos no WSL por
HTTP, com o mesmo motor e os mesmos limites da tela `/wsl`. A API não executa
ações do Windows.

## Ligar

A API nasce desligada. Desligada, `/api/*` responde 404. Há dois jeitos de
ligar:

- `PHPORTO_API_ENABLED=true` no `.env`; ou
- o interruptor da API na tela `/config`, que grava em `storage/flags.json` e
  vence o `.env`.

Ver [configuracao.md](configuracao.md).

## Autenticação

Toda requisição passa pelo mesmo portão das telas: HTTP Basic, com o token no
campo de senha e o usuário vazio.

```bash
curl -u :SEU_TOKEN http://127.0.0.1:4001/api/executions
```

Sem credencial a resposta é 401, inclusive com a API desligada; o 404 dela só
aparece depois do token. Cinco tokens errados seguidos bloqueiam por quinze
minutos (429). Ver [seguranca.md](seguranca.md).

## `GET /api/executions`

Lista as execuções mais recentes, de todos os tipos (comando, anexo e windows).

| parâmetro | padrão | |
| --- | --- | --- |
| `limit` | `100` | quantas, das mais recentes; valor que não é inteiro vira o padrão |

```bash
curl -u :SEU_TOKEN "http://127.0.0.1:4001/api/executions?limit=10"
```

```json
{
  "ok": true,
  "count": 1,
  "executions": [
    {
      "command": "git status",
      "output": "On branch main\n...",
      "exit_code": 0,
      "duration_ms": 182,
      "kind": "comando",
      "timed_out": false,
      "created_at": "2026-09-28 13:05:12"
    }
  ]
}
```

`created_at` está em UTC. `exit_code` é `null` quando não houve código — por
exemplo, numa ação do Windows interrompida.

## `POST /api/executions`

Executa no WSL. O corpo é JSON, e o cabeçalho `Content-Type: application/json`
é **obrigatório**.

Comando:

```bash
curl -X POST -u :SEU_TOKEN http://127.0.0.1:4001/api/executions \
  -H "Content-Type: application/json" \
  -d '{"command":"git status"}'
```

Anexo, com os dois caminhos vistos de dentro do WSL (ver [wsl.md](wsl.md)):

```json
{"src": "/mnt/c/Users/voce/arquivo.csv", "dst": "~/arquivo.csv"}
```

Com `src` e `dst` preenchidos, a requisição é anexo e `command` é ignorado.

Resposta:

```json
{
  "ok": true,
  "recorded": true,
  "note": null,
  "execution": { "command": "git status", "output": "...", "exit_code": 0, "duration_ms": 182, "kind": "comando", "timed_out": false, "created_at": "2026-09-28 13:05:12" }
}
```

Quando a execução acontece mas a gravação no banco falha, a resposta é
`"ok": true` com `"recorded": false` e o motivo em `note`. Não é 500 de
propósito: um 500 faria o cliente concluir que nada rodou e tentar de novo,
executando duas vezes.

## Limites

Os mesmos da tela `/wsl`:

| o quê | teto |
| --- | --- |
| `command` | 64 KB |
| `src`, `dst` | 4 KB cada |
| saída | 1 MiB, cortada com aviso no fim da própria saída |
| tempo | `PHPORTO_TIMEOUT` |

## Erros

Todo erro volta como `{"ok": false, "error": "<motivo>"}`.

| status | quando |
| --- | --- |
| 400 | corpo que não é JSON; sem `command` nem `src`/`dst`; `limit` fora da faixa |
| 401 | sem credencial, ou token errado |
| 403 | cabeçalho `Origin` diferente de `CORS_ORIGIN` |
| 404 | API desligada |
| 405 | método que não é `GET`, `POST` nem `OPTIONS` |
| 409 | WSL indisponível: `PHPORTO_WSL_ROOT` vazio, distro ausente, ou falha ao executar |
| 413 | comando ou caminho acima do teto |
| 415 | sem `Content-Type: application/json` |
| 429 | bloqueado por tentativas erradas, com `Retry-After` |
| 500 | falha ao ler o banco na listagem |
| 503 | `PHPORTO_AUTH_TOKEN` não configurado |

## Checagem de origem

A API responde com `Access-Control-Allow-Origin` igual a `CORS_ORIGIN`
(padrão `http://127.0.0.1:4001`). Uma requisição com cabeçalho `Origin`
diferente é recusada com 403. Requisição sem `Origin`, como a do curl, passa
por esta checagem.

A trava principal contra outro site disparar um POST é o `Content-Type`
obrigatório: um formulário HTML não consegue enviá-lo, e com ele o navegador faz
um preflight que o CORS barra antes de qualquer efeito. A checagem de origem é
a segunda camada. O preflight `OPTIONS` também passa pelo portão de token e,
sem credencial, recebe 401: a API é para clientes como o curl, não para páginas
de outra origem. Ver [seguranca.md](seguranca.md).
