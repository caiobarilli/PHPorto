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

A tela é um console: a saída em cima e o prompt `$` embaixo. O campo do prompt
parece uma linha; **Enter** envia e **Shift+Enter** quebra a linha. Não é um
terminal interativo: cada envio é um comando que roda até o fim e devolve a
saída.

O campo aceita um comando de shell, de uma ou várias linhas. Ele roda
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

O console segue o modo do site: One Half Light no claro, One Half Dark no
escuro, e o do sistema (`prefers-color-scheme`) quando nada foi escolhido.

## Anexos

O card de anexos manda **um arquivo** do navegador para dentro do WSL. Há três
peças: a zona de arquivo, o campo **Destino** e o botão **Enviar**.

- **Escolher:** clique na zona, ou chegue nela com Tab e aperte Enter ou Espaço,
  e o seletor de arquivo do sistema abre. Também dá para soltar um arquivo na
  zona; soltar só escolhe o arquivo, não envia. A zona mostra o nome e o tamanho
  do que foi escolhido.
- **Destino:** o caminho completo do arquivo visto de dentro do WSL, por exemplo
  `~/pasta/arquivo.csv` ou `/home/voce/arquivo.csv`. O `~` no começo vira o home
  do usuário do WSL, e caminho relativo parte do `PHPORTO_WSL_ROOT`. O destino
  **não pode existir**: se existir (arquivo, pasta ou link), nada é copiado e o
  registro diz por quê. Não há sobrescrever.
- **Enviar:** é o único que envia, por `POST` com `acao=anexo` em
  `multipart/form-data`, com o token de uso único como os outros formulários.

Por dentro, o PHP recebe o arquivo na pasta temporária dele, confere que veio de
um envio deste formulário (`is_uploaded_file`) e o move para a pasta de trabalho
`files/`, a mesma onde fica o `cmd.sh`, com um nome aleatório. O WSL lê essa
pasta por `/mnt/<letra>/...`, e o `cp -v` do anexo copia de lá para o destino,
pelo mesmo caminho de antes: `wsl.exe`, script em arquivo e caminhos pelo
ambiente. A cópia temporária é apagada no fim, com sucesso ou falha.

O histórico registra o nome do arquivo, o tamanho e o destino. O conteúdo nunca
vai para o registro.

O caminho inverso, da distro para o Windows, é pelo console: um `cp` para
`/mnt/c/...`.

### Limite do arquivo

O limite é o menor entre 25 MB, o `upload_max_filesize` e o `post_max_size` do
PHP que roda o servidor, lido a cada requisição. O card mostra o valor em uso.
Com o `php.ini` padrão do PHP (`upload_max_filesize = 2M`, `post_max_size = 8M`),
o limite efetivo é 2 MB. Para chegar aos 25 MB, suba os dois no `php.ini`, ou
na linha do servidor:

```
php -d upload_max_filesize=25M -d post_max_size=26M -S 127.0.0.1:4001 -t public public/router.php
```

Arquivo acima do limite volta com aviso, sem copiar. Se o envio inteiro passar do
`post_max_size`, o PHP descarta o corpo todo, inclusive o token; a tela diz que
o envio passou do limite do servidor, em vez de reclamar do token.

A [API](api.md) continua com o anexo por caminhos, sem upload: `src` e `dst`,
os dois vistos de dentro do WSL, copiados com `cp -v`. Lá o destino existente
não é recusado; a recusa é só do card.

## Limites

Recusados no servidor, iguais na tela e na [API](api.md):

| o quê | teto |
| --- | --- |
| comando | 64 KB (65.536 bytes) |
| destino do anexo (tela) e cada caminho de anexo (API) | 4 KB (4.096 bytes) |
| arquivo enviado pelo card de anexos | o menor entre 25 MB, `upload_max_filesize` e `post_max_size` |
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
