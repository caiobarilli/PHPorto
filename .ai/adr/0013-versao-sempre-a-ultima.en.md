# Versions are never pinned: always the latest patched release

[Português](0013-versao-sempre-a-ultima.md) | English

## Context

Versions pinned in code and docs go stale with every release and hide security flaws.

- `src/Win/Ollama.php` pins `VERSION` (`v0.40.13`), `URL`, `SHA256` and `INSTALLER_BYTES` (`1676266440`). A truncated 1.6 GB download shows up as a hash mismatch, not as an incomplete download. A new release leaves the code behind. Dependabot does not update a hash that lives in code. ADR 0008 asked for a fixed tag and "no `latest`".
- `src/Php/C/Tcc.php` pins Tiny C Compiler `0.9.27` with `URL` and `SHA256`, repeated in `docs/seguranca.md`.
- The PHP updater (`php-update`, `docs/windows.md`, "Atualizar o PHP") only offers a patch on the same line (`8.5.11` to `8.5.12`, never `8.5` to `8.6`).
- PHP `8.5.12` on the Maintainer's two PCs was vulnerable to CVE-2026-126714 (phpAfterShell, GHSA-fm2m-fjf5-v3cp). Per https://www.php.net/ChangeLog-8.php, the fix ships in `8.6.0` and `8.5.13`.
- The README (PR `#84`) pins `8.6.0` in the install commands.

## Decision

The target is always the latest stable release with security patches applied.

- Docs point to the official latest-release source (e.g. https://windows.php.net/download, https://github.com/ollama/ollama/releases/latest). A number in the docs is only an example, marked as such, with the way to find the latest.
- Code resolves version, URL and checksum at runtime from the official release (checksum published by the project: `downloads.php.net` `releases.json`, GitHub release assets). No version, hash or size in a constant.
- Size comes from the real `Content-Length`. A download shorter than expected becomes "incomplete download" (or resumes with `Range`), before the hash check.
- Dependency constraints use an open floor (`"php": "^8.5"`), never an exact version.
- The updater moves up a minor within the same major (`8.5` to `8.6`). A new major still needs the Maintainer's decision.
- Replaces, in ADR 0008, the fixed tag, the "no `latest`" and the update-by-commit. The rest of ADR 0008 still holds.

## Consequence

Integrity now depends on the checksum published by the official source, not on a hash reviewed in a commit. `https` only, official host only, no redirect to another host. A project that publishes no checksum keeps a pinned version until a trusted source exists, recorded in `.ai/pitfalls.md`.

Until this is implemented, an agent checks the latest version at the official source before following any doc or command, and swaps the number.

Follow-ups (outside this PR, no code change here):

- Ollama: resolve release and checksum at runtime and detect truncated downloads.
- Tiny C Compiler: review `Tcc.php` and `docs/seguranca.md`.
- PHP updater: allow `8.5` to `8.6`.
- README: replace the pinned `8.6.0` with the official source or mark it as an example.

Not verified: whether the Ollama release publishes its own checksum for `OllamaSetup.exe`.

## Commit

On branch `docs/adr-versoes-e-idiomas`.

## PR

`#85`
