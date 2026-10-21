# Regras

## Papéis

- **Maintainer:** decide, valida na tela, mergeia. É o único que sobe `php -S` e o único que dá push neste fluxo.
- **Session Assistant:** pensa, fatia, escreve o prompt, revisa o que volta. Não executa o projeto, não commita, não sobe servidor.
- **CLI (executor):** mede, escreve, mostra o diff, porta verde, commita. Não decide sozinho, não estende escopo, não dá push.

## Este fluxo

- Não subir `php -S` (o Maintainer sobe; o `composer gate` sobe o de teste e derruba).
- Não dar push. Não mergear. Não mexer na `main`.
- Commit só com portão verde e `git commit -F .git/COMMIT_MSG.txt`.
- Sem rodapé de coautoria de IA. Conferir com `git log -1 --format=%b`.
- Sem nome real de pessoa em `.ai/`, `docs/`, mensagem de commit ou código, salvo citação de author de terceiro (LICENSE, THIRD-PARTY, pacote).
- Sem marca de ferramenta no código. Não criar `CLAUDE.md`, `AGENTS.md`, nem arquivo com nome de ferramenta ou de pessoa.
- `docs/` é documentação de uso: não mover, não apagar. `.claude/` não mexe.

## Código

- No código, só anotação de tipo/retorno do PHPStan (`@phpstan-type`, `@param`, `@return`, `@var`, sem prosa). Texto de o que faz ou por quê não mora no código.
- Uso em `docs/`. Memória de agente em `.ai/`.
- A trava é o `composer sem-comentario` (PHP) e o tokenizador nos `.ps1`.

## Proibições

- Comentário acima de função descrevendo o que faz, recebe ou retorna.
- Rodapé de coautoria de IA.
- Comentário `shortcut:` no código.
- `curl -s`.
- Aspas tipográficas e travessão em string de `.ps1`.
- `sed -i` em `.ps1`.
- Afrouxar ACL de `files/win-protected/`.
