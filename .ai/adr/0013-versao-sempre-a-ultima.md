# Versão nunca fixa: sempre a última com patch

Português | [English](0013-versao-sempre-a-ultima.en.md)

## Contexto

Versões cravadas no código e na doc envelhecem a cada release e escondem falha de segurança.

- `src/Win/Ollama.php` fixa `VERSION` (`v0.40.13`), `URL`, `SHA256` e `INSTALLER_BYTES` (`1676266440`). Um download truncado de 1,6 GB aparece como hash divergente, e não como download incompleto. Release nova deixa o código parado. O Dependabot não atualiza hash que mora no código. O ADR 0008 pedia tag fixa e "nada de `latest`".
- `src/Php/C/Tcc.php` fixa o Tiny C Compiler `0.9.27` com `URL` e `SHA256`, repetidos em `docs/seguranca.md`.
- O atualizador de PHP (`php-update`, `docs/windows.md`, "Atualizar o PHP") só oferece patch na mesma linha (`8.5.11` para `8.5.12`, nunca `8.5` para `8.6`).
- O PHP `8.5.12` dos dois PCs do Maintainer era vulnerável à CVE-2026-126714 (phpAfterShell, GHSA-fm2m-fjf5-v3cp). Segundo https://www.php.net/ChangeLog-8.php, a correção está no `8.6.0` e no `8.5.13`.
- O README (PR `#84`) crava `8.6.0` nos comandos de instalação.

## Decisão

O alvo é sempre a última versão estável com os patches de segurança aplicados.

- Doc aponta para a fonte oficial da última release (ex.: https://windows.php.net/download, https://github.com/ollama/ollama/releases/latest). Número na doc só como exemplo, marcado como exemplo e com o caminho para achar a última.
- Código resolve versão, URL e checksum em tempo de execução a partir da release oficial (checksum publicado pelo projeto: `releases.json` do `downloads.php.net`, assets da release no GitHub). Nada de versão, hash ou tamanho em constante.
- Tamanho vem do `Content-Length` real. Download menor que o esperado vira "download incompleto" (ou retoma com `Range`), antes de conferir o hash.
- Restrição de dependência usa piso aberto (`"php": "^8.5"`), nunca versão exata.
- Atualizador sobe de minor dentro do mesmo major (`8.5` para `8.6`). Major novo continua pedindo decisão do Maintainer.
- Substitui, no ADR 0008, a tag fixa, o "nada de `latest`" e a atualização por commit. O resto do ADR 0008 segue valendo.

## Consequência

A integridade passa a depender do checksum publicado pela fonte oficial, e não de um hash revisado em commit. Só `https`, só o host oficial, sem seguir redirecionamento para outro host. Projeto que não publica checksum fica com a versão fixa até haver fonte confiável, registrado em `.ai/pitfalls.md`.

Até a implementação, agente confere a última versão na fonte oficial antes de seguir doc ou comando, e troca o número.

Pendências (fora deste PR, sem mudança de código aqui):

- Ollama: resolver release e checksum em tempo de execução e detectar download truncado.
- Tiny C Compiler: rever `Tcc.php` e `docs/seguranca.md`.
- Atualizador de PHP: permitir `8.5` para `8.6`.
- README: trocar o `8.6.0` cravado pela fonte oficial ou marcar como exemplo.

Não verificado: se a release do Ollama publica checksum próprio do `OllamaSetup.exe`.

## Commit

No branch `docs/adr-versoes-e-idiomas`.

## PR

Este PR.
