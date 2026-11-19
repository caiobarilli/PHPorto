# Documentation in English and Portuguese

[Português](0014-documentacao-bilingue.md) | English

## Context

`README.md`, `docs/` and `.ai/` exist only in Portuguese. Anyone who does not read Portuguese has no documentation, and agents working in another language read it worse.

## Decision

All documentation exists in English and Brazilian Portuguese, one file per language.

- `README.md` in English, because it is the GitHub landing page. `README.pt-BR.md` in Portuguese.
- `docs/` and `.ai/`: the current file stays where it is, with the same name, in Portuguese. English is the sibling with `.en.md` (`docs/windows.md` and `docs/windows.en.md`; `.ai/adr/0014-documentacao-bilingue.md` and `.ai/adr/0014-documentacao-bilingue.en.md`). This way nothing in `docs/` moves (a rule in `.ai/regras.md`) and links to `.ai/adr/...md` keep working.
- At the top of each file, right below the title, goes the link to the other language: `Português | [English](...)` or `[Português](...) | English`.
- A change in one language ships with the same change in the other, in the same PR.
- Out of scope: UI text (`src/Win/config/*.pt-BR.json`, views), commit messages and LICENSE/THIRD-PARTY.

## Consequence

Every doc change doubles. ADRs 0013 and 0014 are born in both languages.

Follow-ups (outside this PR):

- `README.md` moves to English and the current text goes to `README.pt-BR.md`.
- An `.en.md` version of each file in `docs/`.
- An `.en.md` version of `.ai/regras.md`, `.ai/pitfalls.md`, `.ai/teto.md` and ADRs 0001 to 0012.

## Commit

On branch `docs/adr-versoes-e-idiomas`.

## PR

`#85`
