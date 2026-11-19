# Documentação em inglês e português

Português | [English](0014-documentacao-bilingue.en.md)

## Contexto

`README.md`, `docs/` e `.ai/` existem só em português. Quem não lê português fica sem a documentação, e agente de outro idioma lê pior.

## Decisão

Toda documentação existe em inglês e em português do Brasil, um arquivo por idioma.

- `README.md` em inglês, porque é a página do GitHub. `README.pt-BR.md` em português.
- `docs/` e `.ai/`: o arquivo atual fica onde está, com o mesmo nome, em português. O inglês é o irmão com `.en.md` (`docs/windows.md` e `docs/windows.en.md`; `.ai/adr/0014-documentacao-bilingue.md` e `.ai/adr/0014-documentacao-bilingue.en.md`). Assim nada em `docs/` muda de lugar (regra de `.ai/regras.md`) e os links para `.ai/adr/...md` continuam valendo.
- No topo de cada arquivo, logo abaixo do título, vai o link para o outro idioma: `Português | [English](...)` ou `[Português](...) | English`.
- Mudança num idioma vai com a mesma mudança no outro, no mesmo PR.
- Fora da regra: texto de interface (`src/Win/config/*.pt-BR.json`, views), mensagem de commit e LICENSE/THIRD-PARTY.

## Consequência

Toda mudança de doc dobra. Os ADRs 0013 e 0014 já nascem nos dois idiomas.

Pendências (fora deste PR):

- `README.md` passa para inglês e o texto atual vai para `README.pt-BR.md`.
- Versão `.en.md` de cada arquivo de `docs/`.
- Versão `.en.md` de `.ai/regras.md`, `.ai/pitfalls.md`, `.ai/teto.md` e dos ADRs 0001 a 0012.

## Commit

No branch `docs/adr-versoes-e-idiomas`.

## PR

Este PR.
