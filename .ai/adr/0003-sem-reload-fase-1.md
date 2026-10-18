# Sem-reload fase 1

## Contexto

Cada POST recarregava a página inteira. Token, aviso e histórico já vinham no HTML do 303.

## Decisão

Formulário com `data-sem-reload` manda o mesmo POST e recebe o mesmo 303. O script do layout segue o 303 e troca só o `.wrap` em `<main id="conteudo">`. Sem JavaScript, o fluxo antigo permanece. `Cache-Control: no-store`. CSRF confere `Sec-Fetch-Site` antes do token.

## Consequência

Scripts das telas ligam por delegação. Senha e `[data-segredo]` saem na troca. Erro de rede vira GET, nunca o POST de novo.

## Commit

`8d05931`, `67e7d92`

## PR

`#6` (`b7611e1`)
