# A tela do WSL

`/wsl` executa comandos de shell dentro da distro configurada em
`PHPORTO_DISTRO`, copia arquivos entre o Windows e a distro e mostra o histórico
dessas execuções.

## Quando ela está disponível

O botão do WSL na home, e a própria tela, dependem de duas coisas: o WSL estar
instalado e a distro do `.env` aparecer em `wsl -l -q`. Quando uma delas falta,
o botão desabilita e diz qual é o motivo, e a tela recusa executar.

`PHPORTO_WSL_ROOT` vazio também bloqueia: nada é executado sem ele. Ver
[configuracao.md](configuracao.md).

## Indicador da distro

O cabeçalho da tela diz, ao lado do nome da distro, se a VM está **acordada**
ou **dormindo — o primeiro comando a acorda, em ~5 s**.

É informação, não trava. A VM dormir é normal, e ela sobe sozinha no primeiro
comando; o indicador só avisa por que esse comando vai demorar. A fonte é
`wsl.exe -l --running -q`, que não acorda a VM e não depende do idioma do
Windows. Se o `wsl.exe` não responder, a tela não diz nada, em vez de chutar.

## Entrada e saída

O campo de entrada aceita um comando de shell, de uma ou várias linhas. Ele roda
no bash da distro, a partir de `PHPORTO_WSL_ROOT`, com stdout e stderr juntos e
na ordem real.

Depois de executar, a tela mostra a saída, o código de saída, a duração, a data
e se o tempo estourou (`timeout`). Dois botões copiam o resultado: **Copiar** leva a saída;
**Copiar como JSON** leva o registro como o banco o guarda, com a data em UTC.

O comando é mandado para um arquivo (`files/cmd.sh`) e nunca para a linha de
comando do `wsl.exe`, então não há escape a acertar. A primeira linha desse
arquivo é um cabeçalho da ferramenta (`exec 2>&1` e o `cd` para
`PHPORTO_WSL_ROOT`). Por isso as mensagens de erro do bash citam a linha **+1**
em relação ao que foi digitado: um erro na primeira linha aparece como
`cmd.sh: line 2`.

O console usa a fonte JetBrainsMono Nerd Font instalada no Windows; sem ela, cai
para Consolas e depois `ui-monospace`. A fonte não vem de CDN nem de arquivo
estático: a PHPorto só a nomeia no CSS embutido em `layout.php`, e o navegador
usa a que estiver instalada.

## Anexos

O card de anexos copia um arquivo com `cp -v`. Os **dois** caminhos, origem e
destino, são vistos de dentro do WSL, e é isso que faz o card servir nos dois
sentidos:

| sentido | origem | destino |
| --- | --- | --- |
| Windows para a distro | `/mnt/c/Users/voce/pasta/arquivo.csv` | `~/pasta/arquivo.csv` |
| distro para o Windows | `~/pasta/arquivo.csv` | `/mnt/c/Users/voce/pasta/arquivo.csv` |

O `~` no começo de um caminho vira o home do usuário do WSL. Os caminhos viajam
por variável de ambiente, não interpolados no script.

O botão **Inverter origem e destino** troca os dois campos, para devolver um
arquivo pelo mesmo caminho por onde ele foi. Ele roda só no navegador: não envia
o formulário e não gasta o token de uso único.

O histórico registra o anexo com o comando efetivo e os dois caminhos.

## Limites

Recusados no servidor, iguais na tela e na [API](api.md):

| o quê | teto |
| --- | --- |
| comando | 64 KB (65.536 bytes) |
| cada caminho de anexo | 4 KB (4.096 bytes) |
| saída de uma execução | 1 MiB (1.048.576 bytes) |
| tempo | `PHPORTO_TIMEOUT`, com piso de 30 s |

Os tetos são em bytes, não em caracteres. Comando ou caminho acima do teto
volta com aviso, sem executar nem gravar.

A saída acima de 1 MiB é cortada, e o aviso de corte aparece no fim da própria
saída. Para guardar a saída inteira, redirecione para arquivo no próprio
comando (`... > saida.txt`).

Quando o tempo estoura, o processo é morto também do lado Linux, e o registro
fica marcado como `timeout`.

## Registros

A tabela mostra só as execuções do WSL — comandos e anexos —, com a data em
`dd/mm/aaaa hh:mm:ss` no fuso de `PHPORTO_TZ`.

**Apagar registros do WSL** apaga só esses tipos; o histórico do Windows fica.

Toda saída é gravada em texto puro, sem cifra e sem prazo. Um `cat` num arquivo
com credencial grava a credencial no banco. Ver [seguranca.md](seguranca.md).

## Envio duplicado

Cada formulário leva um token de uso único. Recarregar a página, voltar no
navegador ou reenviar o mesmo POST não executa o comando de novo: o envio
repetido é recusado com aviso. Com duas abas abertas, a mais antiga tem o token
vencido e o envio dela é recusado.
