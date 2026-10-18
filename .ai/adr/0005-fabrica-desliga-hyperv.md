# Fábrica desliga o painel Hyper-V

## Contexto

A restauração apagava só `storage/flags.json`. `storage/hyperv.json` ficava, e o painel seguia ligado.

## Decisão

A restauração chama `HypervGate::disable()` e põe o resultado na mensagem, como faz com o `flags.json`.

## Consequência

O painel continua nascendo desligado. Clone sem o arquivo local não herda a decisão de outra máquina.

## Commit

`d2a0e51`

## PR

`#15` (`be41799`)
