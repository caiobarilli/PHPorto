# Teto

Ponte Windows↔WSL numa máquina local.

- `php -S` em `127.0.0.1`. Única exceção (ADR 0011): a rede privada, opt-in, fail-closed, um segundo `php -S` no IPv4 privado escolhido, nunca `0.0.0.0`.
- Token: sem `PHPORTO_AUTH_TOKEN` no `.env`, nada é servido.
- `/win`: lista fechada de ações; o que a tela manda nunca é código. Ações comuns no PowerShell elevado; sensíveis com UAC por clique.
- `/hyperv`: lista VMs. Na main medida (`ca29ec3`), liga, desliga e reinicia por GUID, não por nome (PR #16, `7023ef2`, `792fd95`).
- Outra máquina: túnel SSH até o loopback, ou, pela exceção do ADR 0011, a rede privada ligada com `php phporto network on` (confirmação digitada, regra de firewall só no perfil Private, só para a allowlist). Sem portproxy.

Fora do teto até o Maintainer mandar:

- criar VM, ISO, template
- chave SSH na VM
- campo livre de PowerShell no browser
- ConPTY
- comentário `shortcut:` no código
- afrouxar ACL de `files/win-protected/`
- escutar em `0.0.0.0`, em IP público ou no perfil Public
- ligar a rede por tela ou por flag, sem a confirmação digitada
