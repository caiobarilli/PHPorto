# Conexão só do loopback, além do filtro de Host

## Contexto

O filtro de Host (`src/Http/Front.php`) aceita só `127.0.0.1` e `localhost` na porta servida e recusa o resto com 421. Ele protege contra DNS rebinding, mas olha um cabeçalho que o cliente escreve. Se o painel subir com `php -S 0.0.0.0:4001`, outra máquina da rede manda `Host: localhost` e passa. Aí só o token segura, e o painel executa ações de administrador.

## Decisão

Antes de qualquer rota, do login e da `/api/`, o front controller confere o `REMOTE_ADDR`, que vem da conexão TCP e não do cliente.

- Passam só `127.0.0.0/8`, `::1` e o IPv4 mapeado `::ffff:127.0.0.0/8`.
- O resto recebe 403 com o corpo curto `403 Acesso recusado`, sem detalhe.
- `REMOTE_ADDR` ausente ou inválido também recebe 403.
- A checagem roda antes do filtro de Host. As duas se somam: de fora é 403, da própria máquina com Host errado é 421.
- A recusa não conhece o `Auth`, então tentativa de fora não mexe no contador de falhas.

## Consequência

O painel não atende pela rede, mesmo que suba em `0.0.0.0`. Um proxy reverso na própria máquina passaria nesta checagem, porque o `REMOTE_ADDR` seria o dele; isso fica fora e cabe ao filtro de Host e ao token. Acesso remoto ao painel fica fora; para usar o Windows de longe existem o RDP e o Sunshine. Um IPv4 compatível (`::127.0.0.1`) é recusado de propósito.

Exceção aprovada: o ADR 0011 abre a rede privada, opt-in e fail-closed. Fora dela, esta decisão segue valendo.
