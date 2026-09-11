# Errata do log

Neste projeto a mensagem de commit é o registro primário. Ela não descreve o
diff — o diff se lê sozinho —, ela guarda decisão, medição e custo aceito: os
porquês que o diff não mostra. Quando uma mensagem perde texto, não se perde
prosa, perde-se o único lugar onde aquele porquê estava escrito.

Quatro commits perderam texto no caminho entre quem escreveu e o repositório, e
um foi empurrado sem corpo nenhum. Está tudo publicado, e este projeto não faz
`amend` nem `rebase`: reescrever histórico empurrado trocaria uma falha visível
por uma invisível. Então o conserto é do jeito que ele conserta tudo —
acrescentando ao registro. Este arquivo é o acréscimo.

## A causa, e a regra que saiu dela

**O transporte.** As mensagens não se corromperam sendo escritas nem sendo
gravadas: elas se corromperam sendo COPIADAS E COLADAS entre o terminal e o
chat onde foram redigidas. Daí a assinatura do dano ser sempre a mesma —
parágrafo colado noutro, frase cortada no meio de uma palavra, linha repetida —,
e daí ela acertar corpos longos e poupar os curtos.

A prova veio de graça e na hora pior: a primeira versão da mensagem DESTE commit,
a que descreve a corrupção, chegou mordida no mesmo padrão. Perdeu texto em seis
pontos, entre eles "os bytes do rxto escrito hoje", "o Set-Content UTF8 dltou com
EF BB BF" e um fecho que virou "PHPStan limp61" — uma errata sobre perda de texto
perdendo texto ao ser entregue.

**A regra, então, é que a mensagem de commit vai por ARQUIVO:**

```bash
# escreve em .git/COMMIT_MSG.txt, e:
git commit -F .git/COMMIT_MSG.txt
git log -1 --format=%B    # confere o que entrou
```

O texto sai do disco para o git sem passar pela área de transferência de ninguém.
Não é preferência de fluxo: é a única mudança que age sobre a causa medida, e
custa uma linha. Mensagem curta colada à mão continua funcionando — e continua
sendo o caminho por onde isto aconteceu cinco vezes.

## Como a perda foi encontrada

Lendo as 922 linhas de `git log` e desconfiando de costura. Quatro assinaturas
aparecem, e a varredura que as acha é reproduzível:

```bash
# parágrafo que termina sem pontuação, parágrafo colado noutro,
# linha quebrada antes de aspas, linha duplicada
for h in $(git log --format='%h'); do
  git log -1 --format='%b' $h | awk -v H=$h '
    { l[NR]=$0 }
    END { for (i=1;i<=NR;i++) { cur=l[i]; nxt=l[i+1]
      if (cur ~ /[A-Za-z0-9,]$/ && (nxt=="" || i==NR)) print H" fim-sem-pontuacao L"i
      if (cur ~ /\.$/ && nxt ~ /^[a-z]/)               print H" paragrafo-colado L"i
      if (cur ~ /[A-Za-z]$/ && nxt ~ /^["(]/)          print H" costura L"i
      if (cur != "" && cur == nxt)                     print H" linha-duplicada L"i } }'
done
```

A varredura devolve também falsos positivos — linha que quebra antes de um termo
entre aspas é normal, e quatro dos sete acertos de "costura" são isso. O que
decide é ler: texto perdido não parseia como frase.

## O inventário

Sete lesões em quatro commits. As linhas são as de `git log -1 --format='%b'`.

| commit | linha | o que aconteceu |
| --- | --- | --- |
| `93a1898` | 15/16 | parágrafo colado: a abertura "O router.php" sumiu |
| `58d0af4` | 45/46 | a enumeração dos três estados sumiu |
| `58d0af4` | 66 | parágrafo cortado na última palavra |
| `58d0af4` | 89/90 | fim de uma enumeração e abertura da frase seguinte |
| `3823d07` | 26/27 | parágrafo colado: título e abertura sumiram |
| `3823d07` | 42/43 | fim de uma frase e abertura do parágrafo de verificação |
| `12e9882` | 1/2 | primeira linha duplicada — **nada perdido** |

E `05edc06`, o commit que introduziu o motor Windows e a tranca do lado elevado,
foi empurrado com assunto e nada mais: `git log -1 --format='%b'` devolve 1 byte.
1899 linhas em 8 arquivos, sem uma linha de porquê.

## Como ler as restaurações

Três marcas, e a diferença entre elas importa mais que o texto:

- **sobreviveu** — os bytes que estão no repositório, citados como estão.
- **restaurado** — **reconstrução de sentido, não os bytes originais.** Escrita
  hoje a partir do contexto do próprio parágrafo e dos comentários do código que
  o commit introduziu. Diz a mesma coisa; não diz com as mesmas palavras.
- **não recuperável** — o que era medição daquele instante e não se refaz.

Onde a restauração se apoia em código, o arquivo está citado: quem duvidar
confere na fonte em vez de acreditar nesta errata.

---

## `93a1898` — feat: executar comando no WSL e registrar a execução no banco

### Lesão, linhas 15/16

**Sobreviveu**, com os dois parágrafos colados num só:

> ali. Defende-se com geografia o que não se deve defender com regex.
> r continua, como segunda camada e como despachante. Ele passou a
> decodificar o caminho ANTES de comparar, (...)

O "r" é a cauda de uma palavra. O parágrafo seguinte perdeu a quebra de linha e a
abertura.

**Restaurado** — a abertura do segundo parágrafo:

> O `router.php` continua, como segunda camada e como despachante. Ele passou a
> decodificar o caminho ANTES de comparar, porque `parse_url()` devolve o caminho
> ainda percent-encoded: `/%2Eenv` não casava com uma regex que procura ponto
> literal e voltava 200 com o `.env` inteiro — furo que estava publicado.

Apoio: o README do mesmo commit traz a seção "O `router.php` é a segunda camada",
com os dois motivos que o parágrafo desenvolve — barrar dotfile que apareça
dentro de `public/`, decodificando antes de comparar, e despachar as rotas
explicitamente em vez de depender do fallback do servidor embutido.

---

## `05edc06` — feat: motor Windows com allowlist no lado elevado

**Este commit não perdeu corpo: ele nunca teve um.** O que segue foi escrito hoje,
a partir do código que o próprio commit introduziu — não é recuperação, é o
registro que faltava. Os porquês existiam, espalhados em docblock e comentário; o
que não existia era o lugar onde se lê a decisão sem abrir oito arquivos.

O diff: `PsResult`, `PsRunner`, `PsScriptBuilder`, `WinAction`, `worker.ps1` e três
suítes. 1899 linhas inseridas, nada removido.

### Os porquês, do código do commit

**O MOTOR É PRÓPRIO E NÃO PASSA PELO WSL**, e a medição está no docblock do
`PsRunner`: 191/198/242 ms chamando o `powershell.exe` direto, contra
397/405/457 ms atravessando o interop da Debian, mais os ~4543 ms de acordar a VM
quando ela está fria. A volta Windows → Linux → Windows não devolvia nada em
troca, porque o canal com o processo elevado é por arquivo de qualquer forma.

**O `PsRunner` NÃO ELEVA, e isso é o desenho.** Ele roda no mesmo token do
`php -S`, integridade Média. Quem eleva é a `Elevation`, e o resultado dessa
elevação não volta por aqui — volta por arquivo. Duas responsabilidades separadas,
porque uma delas é a que o PHP consegue matar e a outra não.

**ALLOWLIST DUPLA, com papéis diferentes e rotulados.** A `WinAction` é a primeira
barreira: recusa no servidor o que o formulário mandou errado, com frase que a
pessoa entende, em vez de deixar o worker recusar em silêncio do outro lado de um
arquivo. A `$ALLOWLIST` do `worker.ps1` é a tranca — roda em integridade Alta e é
a última a validar antes de executar, então é ela que cobre quem escrever no
arquivo de trabalho SEM passar pela tela, que é justamente o cenário que a
primeira barreira não alcança. A redundância é deliberada, e as duas listas
carregam o mesmo teto de 4096 bytes por campo de texto livre. O teto existe
porque o arquivo de trabalho é lido por um processo elevado: campo sem teto é um
jeito de fazer o worker gastar memória com algo que nenhum parâmetro aceitaria.

**OS NOMES DOS PARÂMETROS SÃO OS DO winutil, sem tradução** (`Preset`, `Provider`,
`PrimaryDNS`). Renomear no meio do caminho obrigaria a manter um mapa em algum
lugar, e mapa é onde uma ponta fica para trás. É a mesma decisão que o
`bootstrap.ps1` registraria depois, ao fazer splatting direto na função.

**A REGRA DE ENCODING É A INVERSA DA DO `cmd.sh`**, e o `PsScriptBuilder` existe
para que ela viva num lugar só:

```
cmd.sh (WSL)     LF, SEM BOM
.ps1 (Windows)   CRLF, COM BOM
```

O bash engasga com `\r` e com BOM; o PowerShell 5.1 — o único instalado nesta
máquina, não há `pwsh` — lê script sem BOM como ANSI, e qualquer acento vira lixo
na saída. As duas regras convivem sem se contradizer porque valem para arquivos
diferentes, gerados por lados diferentes, e quem alinhar uma com a outra em nome
de consistência quebra o lado oposto.

**E EXISTE O CAMINHO DE VOLTA**, que não é simetria gratuita: o
`Set-Content -Encoding UTF8` do 5.1 **grava** BOM, então toda saída que o worker
escreve chega ao PHP com três bytes na frente. Medido na sondagem da elevação: a
prova voltou com `EF BB BF` antes do `PID=`, e sem o `stripBom()` esses três
bytes aparecem colados na primeira palavra da saída, na tela.

**`literal()` USA APÓSTROFO SIMPLES**, e é o que permite um valor validado entrar
num script gerado sem virar código: dentro de apóstrofo simples o PowerShell não
interpola nada — nem `$variavel`, nem `$(...)`, nem crase —, e o único escape que
existe ali é o próprio apóstrofo, dobrado. Byte nulo é recusado sem análise,
porque trunca string em camadas abaixo desta.

**O TETO DE SAÍDA É 1 MiB, e ele nasce aqui porque este código é novo.** O Runner
do WSL ainda lê a saída inteira com `file_get_contents`, e um `find /` derruba o
PHP no `memory_limit` antes de chegar ao banco. Essa dívida é de lá e segue de lá;
escrevê-lo neste motor custou uma constante e uma leitura em pedaços de 64 KiB. Em
PEDAÇOS e não lendo tudo para cortar depois: ler tudo já teria estourado o
`memory_limit`, que é exatamente a falha que o teto existe para evitar. E o aviso
de corte entra na PRÓPRIA saída, não num campo separado — quem lê a saída na tela
precisa ver ali que ela não está inteira.

**`toUtf8()` EXISTE PORQUE SAÍDA INVÁLIDA DESAPARECE.** Com UTF-8 quebrado o
`htmlspecialchars()` devolve string vazia, e a saída SOME da tela em vez de
aparecer com lixo — falha que se lê como "o comando não imprimiu nada", mandando
quem depura para o lugar errado. O PowerShell 5.1 sem BOM na saída cai no codepage
ANSI da máquina, que no Brasil é Windows-1252.

**`PsResult` NÃO É A `RunResult` DO WSL, de propósito.** Ela carrega `$truncated`,
que o lado WSL não tem porque o Runner de lá ainda lê tudo. Compartilhar a classe
faria o campo aparecer nos dois lugares significando "sempre falso" num deles — e
campo que só um lado preenche é onde alguém confia num valor que ninguém escreveu.

**`bypass_shell` PELO MESMO MOTIVO DO LADO WSL**: a forma de array do `proc_open`
aspeia TODO argumento no Windows, e o `powershell.exe` deixaria de reconhecer os
próprios flags. Continua seguro porque nenhum texto de usuário entra nessa string
— só flags fixos e um caminho que este código escreveu. `-NoProfile` é economia e
previsibilidade: o perfil do usuário pode imprimir coisa na saída e mudar o que
este código lê.

**O `/T` DO taskkill ALCANÇA OS FILHOS** — um `winget` ou um `tshark` iniciado pelo
script sobreviveria à morte só do pai. E vem com a ressalva medida que molda o
resto do projeto: isso só funciona contra processo do MESMO nível de integridade.
Contra o worker elevado o taskkill devolve "Acesso negado", e é por isso que o
cancelamento de ação elevada é por arquivo, e não por aqui.

**O `worker.ps1` JÁ NASCEU COM A TABELA DE MEDIÇÕES NO DOCBLOCK**, e ela é o
fundamento de todo o desenho do canal:

```
escrever de Média para Alta ....... PASSA (arquivo)
ler de Alta para Média ............ PASSA (arquivo)
pipe nomeado criado pelo elevado .. RECUSADO, "Permission denied"
pipe com rótulo Low/NW explícito .. NÃO PODE SER CRIADO (falta SeSecurityPrivilege)
taskkill de Média contra Alta ..... rc=128, "Acesso negado"
```

Daí as duas consequências: o canal é arquivo, não pipe; e o PHP não consegue matar
o worker nem o filho dele, então desligar e cancelar chegam como ORDEM, num
arquivo que o laço lê e que o próprio worker obedece.

**O FILHO É ASSÍNCRONO PORQUE O HEARTBEAT NÃO PODE PARAR.** Se o worker esperasse
a ação de forma síncrona, o PHP concluiria que ele morreu exatamente durante a
ação mais longa — um install de dez minutos derrubaria o indicador dele mesmo. Um
laço só faz tudo: heartbeat, vigia do pai, ordens, job novo e sondagem do filho. O
tique de 500 ms é medido: a ordem de cancelamento foi obedecida em 711 ms entre a
escrita do arquivo e a saída do processo.

### Cobertura

67 testes novos, contados no próprio diff: 13 no `PsRunnerTest`, 15 no
`PsScriptBuilderTest`, 39 no `WinActionTest`. Nenhum eleva nada — o que exige
integridade Alta é abrir o worker, e um teste que pedisse isso penduraria a suíte
num prompt de UAC em máquina de fábrica.

### Duas razões deste commit que o projeto derrubou depois

Ficam registradas porque errata que esconde o que envelheceu não serve:

- **`performance` sem `-State`.** O comentário afirmava, com medição, que passar
  `-State` devolvia `NamedParameterNotFound`. Era verdade para aquele ponto de
  entrada, o `winutil-cli.ps1`, que não declarava o parâmetro. `a2e2c08` matou esse
  ponto de entrada e `79ace61` corrigiu o comentário: `Invoke-Performance`
  **declara** `-State [ValidateSet('on','off')]`, e hoje o que impede é só a
  allowlist.
- **A ordem do enum existir para a tela iterar.** `79ace61` mediu e derrubou: a
  `win.php` NÃO itera o enum, tem seção por ação, porque cada uma tem campos
  próprios.

### Não recuperável

**Os números do portão.** Todo commit deste projeto fecha com a contagem da suíte,
do PHPStan e do fixer daquele instante. A do `05edc06` não existe em lugar nenhum,
e não se refaz: rodar o portão hoje mede a árvore de hoje, não a daquele dia.
Inventá-la seria pôr no registro um número que ninguém mediu — exatamente o que
este projeto não faz. Fica o buraco, nomeado.

---

## `58d0af4` — feat: interruptor de PowerShell elevado na /config

### Lesão 1, linhas 45/46

**Sobreviveu** um título sem o conteúdo dele:

> TRÊS ESTADOS E NÃO UM BOOLEANO,
> interruptor nasce desabilitado dizendo qual dos dois é — não há padrão
> para esse caminho, pelo mesmo motivo do PHPORTO_WSL_ROOT: um default
> seria palpite sobre a máquina de quem clonou.

Sumiram a enumeração dos três estados e a abertura da frase que termina em "o
interruptor nasce desabilitado dizendo qual dos dois é".

**Restaurado**:

> TRÊS ESTADOS E NÃO UM BOOLEANO, pelo mesmo motivo do `DistroStatus`: a tela
> precisa dizer QUAL é o problema. "Não configurado", "desligado" e "o elevado
> parou de responder" têm soluções diferentes, e uma mensagem genérica manda a
> pessoa procurar no lugar errado. Configuração é o único impedimento que nem
> deixa TENTAR — com `PHPORTO_WINUTIL_PATH` vazio, ou apontando para arquivo que
> não existe, o interruptor nasce desabilitado dizendo qual dos dois é; não há
> padrão para esse caminho, pelo mesmo motivo do `PHPORTO_WSL_ROOT`: um default
> seria palpite sobre a máquina de quem clonou.

Apoio, em três lugares do mesmo commit: o docblock de `ElevationState` traz os
três estados com estas palavras e o `canTry()` com "configuração faltando é o
único impedimento"; o `configProblem()` tem exatamente os dois casos de
configuração, vazio e arquivo inexistente; e dois dos 25 testes são
`bloqueia quando PHPORTO_WINUTIL_PATH está vazio` e
`bloqueia quando o caminho aponta para arquivo que não existe`.

### Lesão 2, linha 66

**Sobreviveu** um parágrafo cortado na última palavra:

> (...) a guarda do PHP continua existindo, só passa a ficar acima da nossa, e
> quem corta primeiro é o timeout que sabe explicar o que

**Restaurado** — o fecho:

> (...) e quem corta primeiro é o timeout que sabe explicar o que aconteceu.

### Lesão 3, linhas 89/90

**Sobreviveu**, com uma enumeração cortada no meio de uma palavra:

> O que dá
> para verificar sem elevar é justamente o que decide se a tela mente: PID
> casando, heartbeat fresco, hea
> "desligado", que é o lado seguro. O caminho de ligar de verdade foi
> exercitado à mão, por HTTP, contra o worker real.

**Restaurado**:

> O que dá para verificar sem elevar é justamente o que decide se a tela mente:
> PID casando, heartbeat fresco, heartbeat velho, marcador de outra execução do
> servidor e JSON ilegível — e em todo caminho de dúvida a resposta é
> "desligado", que é o lado seguro.

Apoio: são os testes desse commit, nome por nome —
`com PID casando e heartbeat fresco, está ligado`,
`aceita heartbeat de idade dentro do limite`,
`HEARTBEAT VELHO desliga e explica, em vez de mentir`,
`marcador válido sem heartbeat nenhum desliga e explica`,
`MARCADOR DE OUTRA EXECUÇÃO DO SERVIDOR lê como desligado` e
`JSON ILEGÍVEL VIRA DESLIGADO, e não erro`.

---

## `3823d07` — feat: botão WIN na home, nascendo desabilitado

### Lesão 1, linhas 26/27

**Sobreviveu**, com o parágrafo do botão WIN colado no fim do parágrafo anterior e
sem título nem abertura:

> (...) porque é isso que permite o router ser lista de
> permissão.
> o servidor não eleva nada. Reusa o .btn.is-disabled que o WSL já tinha,
> sem CSS novo (...)

**Restaurado** — título e abertura:

> O WIN NASCE DESABILITADO, SEMPRE: subir o servidor não eleva nada. Reusa o
> `.btn.is-disabled` que o WSL já tinha, sem CSS novo — o `span` em vez do `<a>`
> é o que impede o clique, e o `pointer-events: none` é a segunda camada.

Apoio: o docblock de `src/Views/home.php`, no mesmo commit — "O WIN NASCE
desabilitado, sempre. Subir o servidor não eleva nada: o que o habilita é o
interruptor da /config, e o estado morre com o servidor. Não é defeito nem falta
de configuração — é o padrão."

### Lesão 2, linhas 42/43

**Sobreviveu** uma frase cortada na terceira letra de uma palavra, seguida por um
parágrafo de verificação que perdeu a abertura:

> Um parágrafo só tentando di
> desabilitado com a nota apontando para a configuração; depois de ligar
> na /config, sai como <a href="/win"> e a lista de notas desaparece
> inteira; depois de desligar, volta ao span.

São duas perdas numa costura só: o fim do parágrafo sobre as notas, e o começo do
parágrafo sobre a verificação no navegador.

**Restaurado**, as duas:

> Um parágrafo só tentando dizer os dois motivos ao mesmo tempo sairia genérico
> nos dois, e nota genérica não diz o que fazer em seguida.
>
> Verificado no navegador: com o interruptor da /config desligado, o WIN sai como
> `span` desabilitado com a nota apontando para a configuração; depois de ligar
> na /config, sai como `<a href="/win">` e a lista de notas desaparece inteira;
> depois de desligar, volta ao `span`. Os três SVG renderizam e nenhuma classe do
> Tailwind sobrou no HTML.

Apoio: o docblock de `home.php` diz "isso é a razão de haver uma nota por botão em
vez de um parágrafo só", e a view tem os três caminhos citados — o `<a>`, o `span`
com `aria-disabled` e a `<ul class="home-notas">`, que só existe quando um dos dois
botões está desabilitado.

---

## `12e9882` — feat: recolher execução do Windows que terminou sem virar linha

### Lesão, linhas 1/2 — e aqui não há nada a restaurar

A primeira linha aparece duas vezes, idêntica:

> A promessa do projeto é que o banco diz se algo executou. Ela não valia: a
> A promessa do projeto é que o banco diz se algo executou. Ela não valia: a
> espera é síncrona (...)

É duplicação, não perda: a frase continua e fecha. O parágrafo se lê inteiro
pulando a linha 1. Fica registrado porque a mesma costura que duplica aqui é a que
apaga nos outros três — e quem encontrar a repetição amanhã não precisa investigar
se falta algo atrás dela.

---

## O que esta errata não conserta

O histórico publicado continua com os bytes que tem. Este arquivo acrescenta, não
substitui: `git log` segue mostrando as lesões, e é assim que se quis. Quem lê o
log encontra o texto quebrado; quem lê o repositório encontra aqui o que estava
escrito e onde a costura cedeu.

Uma medição não volta — os números do portão do `05edc06`. Está nomeado na seção
dele.
