---
name: php-wsl-interop
description: Executar comandos do WSL a partir de PHP rodando no Windows — process spawning, passagem de variáveis, encoding de arquivos, timeout e as travas de segurança de uma ferramenta local que executa comando arbitrário. Use ao escrever ou revisar qualquer código PHP que chame wsl.exe, monte scripts para o Linux, ou sirva uma interface que dispare comandos.
---

# PHP no Windows executando no WSL

Cada regra abaixo foi medida, não inferida. Onde há um sintoma anotado, ele é o
que aparece na tela quando a regra é violada — use-o para reconhecer o problema
em vez de depurar do zero.

## 1. Não monte o comando como string interpolada

O comando do usuário nunca entra na linha de comando. Grave-o num arquivo `.sh`
e mande o `bash` executar o arquivo.

Motivo: comandos reais têm aspas duplas, cifrão, crase e subshell `$(...)`.
Qualquer escape que você escrever vai falhar em algum deles, e a falha aparece
como erro *do comando*, não do wrapper — o que manda quem depura para o lugar
errado.

    // errado
    $cmd = 'wsl.exe -d Debian -- bash -lc "' . $input . '"';

    // certo
    file_put_contents($scriptPath, $script);   // LF, sem BOM — ver §3
    // e execute o ARQUIVO

## 2. `proc_open` em modo array não serve no Windows

No Windows, o modo array envolve **todo** argumento em aspas, inclusive as
flags. O `wsl.exe` deixa de reconhecer o próprio `-d`, ele vira argumento
comum, e o restante é entregue ao shell de login.

Sintoma: `zsh:1: command not found: -d` (ou `bash: -d: command not found`).

A forma que funciona é linha única com `bypass_shell`, que chama `CreateProcess`
direto no executável, sem `cmd.exe` nem `powershell.exe` no meio:

    $process = proc_open(
        'wsl.exe -d ' . $distro . ' -- bash -l ' . $scriptPathInsideWsl,
        $descriptors,
        $pipes,
        null,
        $env,
        ['bypass_shell' => true],
    );

Isso continua seguro porque o texto do usuário **não** está nessa string — só
o nome da distro e um caminho que o seu código controla.

## 3. O arquivo de script precisa ser LF e UTF-8 sem BOM

O PHP no Windows escreve CRLF por padrão em vários caminhos, e um editor pode
acrescentar BOM. O bash do Linux engasga com o `\r` e com o BOM.

Sintomas: `$'\r': command not found`, ou a primeira linha do script sendo
ignorada.

Normalize antes de gravar e confirme com `cat -A` do lado Linux: nenhum `^M`
deve aparecer.

## 4. Variáveis não atravessam a fronteira sem `WSLENV`

Definir a variável no ambiente do processo Windows **não** a torna visível
dentro do WSL. É preciso listar os nomes em `WSLENV`, separados por `:`.

    $env = [
        'WSLENV'   => 'APP_ROOT:SRC_PATH:DST_PATH',
        'APP_ROOT' => $root,
        'SRC_PATH' => $src,
        'DST_PATH' => $dst,
    ];

Sintoma quando falta: a variável chega vazia e o erro aparece longe da causa —
um `cd "$APP_ROOT"` falhando com "diretório não encontrado", que parece erro de
configuração do usuário.

**Armadilha de manutenção:** ao renomear uma variável, o `WSLENV` precisa ser
atualizado junto. Renomeação parcial quebra em silêncio.

## 5. Passe caminhos por ambiente, nunca interpolados

Caminho com espaço, acento ou aspas quebra qualquer escape. Por ambiente não
existe escape para errar:

    // no script
    cp -v -- "$SRC_PATH" "$DST_PATH"

O `--` protege caminho que comece com hífen. O `-v` faz o `cp` declarar o que
copiou, o que serve de comprovante na saída e no log.

## 6. O til não expande dentro de uma variável

`"$DST_PATH"` contendo `~/algo` cria literalmente uma pasta chamada `~`. Resolva
só o til inicial, e **sem `eval`** — `eval` transformaria um campo de texto em
execução de código arbitrário no exato lugar onde isso é mais difícil de notar:

    case "$DST_PATH" in
      "~")   DST_PATH="$HOME" ;;
      "~/"*) DST_PATH="$HOME/${DST_PATH#\~/}" ;;
    esac

## 7. Funda stdout e stderr dentro do bash, não em dois pipes

Dois pipes separados no Windows entregam as linhas embaralhadas pelo buffer, e
a ordem que você mostra não é a ordem real dos eventos. Ponha na primeira linha
do script:

    exec 2>&1

**Efeito colateral a documentar:** as mensagens de erro do bash passam a citar
o número da linha deslocado pelo tamanho do cabeçalho que você inseriu. Se o
cabeçalho tem uma linha, o desvio é +1. Mantenha o cabeçalho com o número de
linhas que o README declara — mudar de uma para duas linhas faz a documentação
mentir sem ninguém perceber.

## 8. Timeout precisa matar do lado de lá também

Um comando que trava pendura a página. Aplique um limite, mate o processo e
devolva a saída parcial mais uma linha dizendo que estourou.

Verifique que o kill **atravessa a fronteira**: rode um `sleep` longo, deixe o
timeout disparar, e confirme com `pgrep -a sleep` dentro da distro que nada
ficou pendurado. Matar o `wsl.exe` no Windows sem que o processo Linux morra é
uma falha silenciosa que só aparece quando a máquina fica lenta.

## 9. Travas de uma ferramenta que executa comando arbitrário

Se o seu código expõe uma interface que dispara comandos, estas não são
opcionais:

- **Escute apenas em `127.0.0.1`.** Nunca `0.0.0.0`, nunca o IP da rede, nunca
  atrás de proxy. Uma página que executa comando exposta na rede é acesso
  remoto irrestrito à máquina, para qualquer um que alcance a porta.
- **O servidor embutido do PHP serve estáticos, inclusive dotfiles.**
  `http://host:porta/.env` devolve o arquivo. Se a ferramenta tem `.env`, suba
  com um router script que bloqueie caminhos começando com ponto:

      // router.php
      $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
      if (preg_match('#(^|/)\.#', $path)) {
          http_response_code(404);
          return true;
      }
      return false;   // deixa o servidor embutido servir o resto

  E documente a linha de subida com o router, não sem ele.
- **Não há autenticação, e isso é desenho.** Ferramenta local de uma pessoa só.
  Diga isso no README em vez de deixar implícito — quem clonar precisa saber
  antes de subir.
- **A saída vira arquivo em texto puro.** Registre no README que segredos não
  devem passar pela ferramenta: um `cat` num `.env` de outro projeto grava a
  credencial no log.

## Ordem de investigação quando algo quebra

1. O script chegou com `\r`? (`cat -A`)
2. A variável atravessou? (`echo "[$APP_ROOT]"` na primeira linha)
3. O `wsl.exe` recebeu as flags? (sintoma do §2)
4. O erro cita uma linha? Lembre do deslocamento do §7.