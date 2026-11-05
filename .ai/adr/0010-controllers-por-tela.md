# Um controller por tela

## Contexto

`src/Http/Pages.php` tinha 1.904 linhas e atendia todas as telas do painel: `/`, `/config`, `/wsl`, `/win`, `/hyperv`, `/php` e `/ollama`. Mexer numa tela obrigava a ler o arquivo inteiro.

## Decisão

- As telas ficam em `src/Http/Pages/`: `HomePage`, `ConfigPage`, `WslPage`, `WinPage`, `HypervPage`, `PhpPage` e `OllamaPage`. Cada uma expõe `handle()`.
- A base abstrata `Page` guarda o construtor com as dependências e o que mais de uma tela usa: `flash`, `takeFlash`, `describe`, `winBlockingReason` e `ROWS`.
- `public/index.php` monta as dependências uma vez e o `match` cria só a tela da rota pedida.
- O código dos métodos foi movido sem edição. URL, texto, CSRF, filtro de Host e auth não mudam, e a allowlist de `public/router.php` também não.

## Consequência

Cada tela fica num arquivo próprio. Todas recebem as mesmas dependências, mesmo as que não usam algumas delas; separar isso fica para depois.
