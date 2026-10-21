# Teto

Ponte Windows↔WSL numa máquina local.

- `php -S` só em `127.0.0.1`.
- Token: sem `PHPORTO_AUTH_TOKEN` no `.env`, nada é servido.
- `/win`: lista fechada de ações; o que a tela manda nunca é código. Ações comuns no PowerShell elevado; sensíveis com UAC por clique.
- `/hyperv`: lista VMs. Na main medida (`ca29ec3`), liga, desliga e reinicia por GUID, não por nome (PR #16, `7023ef2`, `792fd95`).
- Outra máquina: túnel SSH até o loopback. Sem abrir a porta do PHPorto na LAN. Sem portproxy.

Fora do teto até o Maintainer mandar:

- criar VM, ISO, template
- chave SSH na VM
- campo livre de PowerShell no browser
- ConPTY
- comentário `shortcut:` no código
- afrouxar ACL de `files/win-protected/`
