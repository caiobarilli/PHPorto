---
name: php-85
description: O que muda ao escrever ou revisar código com PHP 8.5 como piso — pipe operator, #[\NoDiscard], array_first/array_last, clone with, visibilidade assimétrica em estáticas — com o critério de quando cada recurso vale e quando atrapalha. Use ao escrever código novo em projeto com floor 8.5, ao revisar mudanças, ou ao modernizar código escrito para versões anteriores.
---

# PHP 8.5

Este projeto declara PHP 8.5 como versão mínima. Isso tem uma consequência que
vem antes de qualquer recurso: **se o piso é 8.5, o código deve usar 8.5**. Um
piso alto que não é aproveitado só exclui quem tem PHP mais antigo, sem devolver
nada em troca.

## Já é baseline — não trate como novidade

Tudo de 8.0 a 8.4 é normal aqui: property hooks, visibilidade assimétrica em
propriedades de instância, `#[\Deprecated]`, `new` sem parênteses, objetos
lazy, `array_find` / `array_find_key` / `array_any` / `array_all`,
`\Dom\HTMLDocument`, e tipos de parâmetro nuláveis explícitos (a forma
implícita está formalmente depreciada).

Não sinalize esses em revisão. O que segue é o que 8.5 acrescenta.

## Operador pipe `|>`

`$valor |> $callable` equivale a `$callable($valor)`. O lado direito precisa ser
um callable de um argumento — a sintaxe `fn(...)` e closures curtas são o
encaixe natural. É associativo à esquerda: `$x |> f(...) |> g(...)` é `g(f($x))`.

    // antes
    $slug = strtolower(str_replace(' ', '-', trim($titulo)));

    // com pipe
    $slug = $titulo
        |> trim(...)
        |> (fn (string $s) => str_replace(' ', '-', $s))
        |> strtolower(...);

**Arrow function no lado direito PRECISA de parênteses.** Sem eles o PHP 8.5
recusa em tempo de compilação: `Arrow functions on the right hand side of |> must
be parenthesized`. Não é estilo, é sintaxe.

**Use quando** a chamada aninhada tem três níveis ou mais e o fluxo do dado é
obviamente da esquerda para a direita, mas está escrito ao contrário; em
pipelines de transformação (ler, validar, transformar, gravar); ou para eliminar
variável temporária que só existe para quebrar o aninhamento.

**Não use quando** algum passo tem efeito colateral que você precisa inspecionar
— depurar uma cadeia de `(...)` é pior que depurar linhas separadas; quando há
menos de duas transformações; quando a maioria dos passos precisa de mais de um
argumento, porque a closure de embrulho custa mais legibilidade do que o pipe
devolve; ou em laço quente, já que cada passo é uma chamada de função.

Não existe conversão automática por ferramenta. Converta à mão, só onde o ganho
é evidente.

## `#[\NoDiscard]`

Marca função, método ou closure cujo retorno **precisa** ser consumido. Se a
chamada aparece como instrução e o resultado é jogado fora, o PHP avisa.

    final class Resultado
    {
        #[\NoDiscard('Erros precisam ser tratados ou suprimidos explicitamente')]
        public function unwrap(): mixed { /* ... */ }
    }

    $resultado->unwrap();            // aviso — retorno descartado
    $valor = $resultado->unwrap();   // ok
    (void) $resultado->unwrap();     // supressão explícita, quando é intencional

Vale igualmente em construtores imutáveis, onde esquecer a atribuição é um
no-op silencioso:

    #[\NoDiscard('A instância é imutável; atribua o retorno')]
    public function where(string $coluna, mixed $valor): self
    {
        return new self([...$this->condicoes, [$coluna, $valor]]);
    }

**Use em** retornos do tipo resultado/erro que o chamador tem de tratar; em
builders imutáveis e métodos `with*()`; e em primitivas criptográficas, onde
descartar a saída é falha de segurança e não descuido de estilo.

**Não use em** métodos legitimamente usados dos dois jeitos — `array_push`
devolve a contagem e quase ninguém usa; marcar geraria ruído — nem em helpers
internos, onde é exagero.

**E não use quando não pega nada.** Se todos os chamadores atuais já usam o
retorno, o atributo não muda nada hoje: ele paga quando a API ganha consumidores
de fora ou muitos pontos de chamada. Marcar por marcar é ruído com autoridade.

Nota: `#[\Deprecated]` passou a valer também em traits e mais contextos de
constante; `#[\Override]` passou a valer em propriedades.

## `array_first()` e `array_last()`

Devolvem o primeiro ou o último elemento, ou `null` se o array estiver vazio, e
**não movem o ponteiro interno** — ao contrário de `reset()` e `end()`.

    // antes
    $primeiro = reset($itens) ?: null;
    $ultimo   = end($itens)   ?: null;

    // agora
    $primeiro = array_first($itens);
    $ultimo   = array_last($itens);

A diferença que mais importa não é o ponteiro, é a ambiguidade: `reset()`
devolve `false` tanto para array vazio quanto para um array cujo primeiro
elemento **é** `false`. `array_first()` devolve `false` para o valor e `null`
só para vazio.

    $flags = [false, false];
    reset($flags);         // false — vazio ou o valor?
    array_first($flags);   // false — o valor. null seria vazio.

**Converta** toda chamada a `reset`/`end` cujo único propósito é pegar o
primeiro ou o último elemento. É mecânico. **Não converta** onde o efeito no
ponteiro é realmente usado (`current`, `next`, `prev`, `key`) — raro, e quase
sempre substituível por um iterador de verdade.

Ao varrer por candidatos, cuidado com falso positivo: `key(` casa também com
texto em comentário, como "duplicate key". Confira que é chamada, não prosa.

## `clone with`

`clone ($objeto, ['propriedade' => $novoValor])` clona e atribui numa expressão
só. Mas há uma restrição que muda o conselho inteiro, e ela não é óbvia:

**Propriedade `readonly` é implicitamente `protected(set)`.** Isso significa que
`clone with` só a alcança de DENTRO do escopo da classe. De fora, estoura:

    $dobro = clone ($preco, ['valor' => $preco->valor * 2]);
    // Fatal error: Cannot modify protected(set) readonly property
    //              Dinheiro::$valor from global scope

Então `clone with` **não elimina** o método `with*()` num value object
`readonly` — ele encurta o corpo do método:

    final readonly class Dinheiro
    {
        public function __construct(
            public int $valor,
            public string $moeda,
        ) {}

        public function comValor(int $valor): self
        {
            return clone ($this, ['valor' => $valor]);   // dentro da classe: ok
        }
    }

O ganho real aparece quando o objeto tem muitas propriedades: o `with*()` deixa
de repetir a lista inteira do construtor para trocar um campo. Em classe de duas
propriedades, `new self(...)` é igualmente claro.

**Fora do escopo da classe**, `clone with` funciona apenas em propriedade que
não seja `readonly` e cuja escrita seja pública.

**Não use quando** o método `with*()` **valida** — `clone with` atribui direto e
pula a validação do construtor. Atualização de várias propriedades que precisam
ser checadas em conjunto continua sendo método com validação explícita.

## Visibilidade assimétrica em propriedades estáticas

O que 8.4 trouxe para propriedades de instância vale agora para estáticas:

    final class Registro
    {
        public private(set) static int $total = 0;
    }

    Registro::$total;       // leitura pública
    Registro::$total = 10;  // erro — escrita privada

**Use em** registradores e portadores de flag onde o mundo pode observar mas não
escrever. Substitui o par `private static` mais getter público.

**Cuidado:** estado estático em volume já é cheiro por si só. Consertar a
visibilidade numa classe cheia de estáticas é meio conserto — pergunte antes se
aquilo não deveria ser um objeto de serviço.

## Rastro de pilha

8.5 inclui os argumentos das funções nos backtraces de erro fatal (respeitando
`zend.exception_string_param_max_len`) e passou a emitir backtrace quando o
tempo máximo de execução estoura, que antes era ponto cego.

Não há passo de migração. Se quiser os argumentos visíveis, aumente aquele
limite; e ajuste filtros de log, porque os quadros ficam maiores. Parâmetros
marcados com `#[\SensitiveParameter]` continuam redigidos.

## Handles cURL persistentes

`curl_share_init_persistent(array $opcoes)` devolve um handle cujo estado
(cache de DNS, de conexão, de sessão TLS, cookies) sobrevive entre requisições
do mesmo worker.

    $share = curl_share_init_persistent([
        CURL_LOCK_DATA_DNS,
        CURL_LOCK_DATA_CONNECT,
        CURL_LOCK_DATA_SSL_SESSION,
    ]);

    $ch = curl_init('https://exemplo.test/v1/recurso');
    curl_setopt($ch, CURLOPT_SHARE, $share);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $corpo = curl_exec($ch);
    curl_close($ch);

**Vale em** integrações de alto volume contra os mesmos hosts, workers longos
(consumidores de fila, RoadRunner, FrankenPHP, Swoole), e cargas onde o
handshake domina a latência.

**Não vale em** PHP-FPM clássico com requisição curta e uma chamada só — a
economia é desprezível e o estado persistente complica a recuperação de falha.
Cuidado também com rotação de certificado e com worker multi-inquilino, onde
vazar estado de conexão entre clientes é inaceitável.

## Outros, caso a caso

- **Extensão URI** (`Uri\Rfc3986\Uri`, `Uri\WhatWg\Url`) — substitui
  `parse_url()` e bibliotecas de userland para análise e acesso a componentes.
  Migre quando a biblioteca atual é um invólucro fino; mantenha quando ela
  entrega integração PSR-7 de que você depende.
- **`Closure::getCurrent()`** — recursão em função anônima sem o truque do
  `use (&$f)`.
- **`final` em propriedade promovida no construtor** — elimina o risco de
  subclasse sobrescrever o campo.
- **Closures e first-class callable em expressão constante** — permite atributo
  carregar callback padrão. Nicho.
- **`grapheme_levenshtein()`**, `setcookie(... 'partitioned' => true)`,
  `get_error_handler()` / `get_exception_handler()` e adições em `mb_*` —
  conheça, mas não são motivo de reescrita em massa.

## Ao revisar código neste projeto

1. `reset(...)` ou `end(...)` usado só para pegar extremidade → deve ser
   `array_first` / `array_last`. Confira o tratamento de array vazio na troca.
2. Chamada aninhada de três níveis ou mais em transformação de dado →
   candidata a pipe. Só converta se ficar mais legível.
3. Método que devolve resultado, erro ou nova instância imutável → candidato a
   `#[\NoDiscard]`, se houver chamador que possa descartar.
4. Value object `readonly` com `with*()` que só repassa ao construtor → o
   corpo do método pode virar `clone with` (dentro da classe — de fora não
   alcança propriedade readonly). Se o `with*()` valida, deixe como está.
5. `private static` com getter público → `public private(set) static`.
6. Análise estática no nível declarado, sem baseline. Código novo que não passa
   não entra.
