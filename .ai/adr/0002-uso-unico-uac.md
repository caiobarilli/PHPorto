# Uso único e UAC por ação sensível

## Contexto

Com o worker longo de pé, quem lesse o nonce do marcador mandava job e rodava ação sensível (winget, RDP, firewall) sem prompt.

## Decisão

Lista `$SENSIVEIS` / `WinAction::SENSITIVE`. Worker longo recusa sensível (126). Cada sensível abre o próprio UAC (`OneShot`), pedido de uso único, manifesto no clique. Só roda se a política do UAC pedir confirmação (`EnableLUA=1`, `ConsentPromptBehaviorAdmin` 1-5).

## Consequência

Sensível funciona com o elevado longo desligado. UAC silencioso ou desligado recusa. Um UAC por clique.

## Commit

`b243d1b`, `7a0d0e2`

## PR

`#5` (`ea2fb81`)
