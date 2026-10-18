# VM do Hyper-V por GUID, não por nome

## Contexto

Nome de VM não é único (`*` pegaria todas; o Hyper-V aceita duas VMs com o mesmo nome). A tela `/hyperv` só lia.

## Decisão

Ação `hyperv-vm`: `Get-VM -Id`, nunca pelo nome. Subações `start`, `stop`, `restart`. GUID na allowlist. Sensível: um UAC por clique. A `/win` recusa `hyperv-vm`. A tabela da `/hyperv` manda POST com token, subação e id.

## Consequência

VM sem id na leitura fica sem botão. Estado de passagem é recusado. Criar VM, ISO, template e chave SSH na VM continuam fora do teto.

## Commit

`7023ef2`, `792fd95`

## PR

`#16` (`42e67b6`)
