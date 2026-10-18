# `/hyperv` coleta o win-done da leitura certa

## Contexto

`collect()` sem id pegava o primeiro `win-done-*.json`. Na pasta protegida a limpeza podia falhar calada; um par de `processes` virava a resposta da `/hyperv`.

## Decisão

Opção (b): `send()` anota os win-done já presentes; `collect()` sem id ignora esses ids e só aceita a ação pedida; apaga só o par daquela leitura. Recusa do worker (sem ação) continua aceita.

## Consequência

PR `#11` (`ac0a197`) entrou, `#12` (`0adb1ac`) reverteu, `#14` (`dc359bd`) restaurou o `#11`. ACL da pasta protegida não mudou.

## Commit

`ac0a197`, `0adb1ac`, `dc359bd`

## PR

`#11`, `#12`, `#14`
