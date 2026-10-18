# Allowlist, manifesto e pasta protegida

## Contexto

`src/Win` e `files/` são graváveis por qualquer processo do usuário. O worker roda como Administrador: um `.ps1` ou JSON trocado com ele de pé virava a próxima ação elevada.

## Decisão

Allowlist no worker e no PHP. Ao ligar, o PHP grava SHA-256 de `bootstrap.ps1`, `actions/`, `lib/`, `config/` e `audit/audit.ps1` em `files/win-manifesto.json` e passa o hash desse arquivo na linha de comando. O worker só aceita esse manifesto e carrega pelo mapa. Scripts e resultados vão para `files/win-protected/` com ACL própria, sem herança.

## Consequência

Mudança em `src/Win` só vale depois de desligar e ligar o elevado. Pasta que é link ou tem outro dono recusa a subida. ACL não se afrouxa.

## Commit

`8954661`

## PR

Não há merge `#3` no log. O commit entrou na main pelo merge `#4` (`ea0dd72`).
