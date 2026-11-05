# PHPorto

[![CI](https://github.com/caiobarilli/PHPorto/actions/workflows/ci.yml/badge.svg)](https://github.com/caiobarilli/PHPorto/actions/workflows/ci.yml)

**Você é o dono do porto.**

O PHPorto é para quem usa Windows e se recusa a ser usuário de segunda classe.
Pelo navegador, você fiscaliza a sua máquina: reduz a telemetria ao mínimo que
a edição do Windows permite, barra os apps que chegam empurrados nas
instalações, vigia o tráfego da rede e opera o essencial
sem decorar comando de PowerShell.

Liga o RDP em alta qualidade, joga pelo Moonlight, usa o WSL direto no navegador
e comanda a sua frota de VMs no Hyper-V. Seu PC vira um servidor de verdade, com
várias máquinas trabalhando a seu favor.

Aqui você não aluga nada: nenhum fabricante decide quando a sua máquina fica
velha. E nada atraca sem fiscalização: os downloads do Ollama, do
WinMemoryCleaner e do Tiny C Compiler têm versão fixada e hash conferido, e a
atualização do PHP confere o SHA-256 publicado na lista oficial.

WSL, Hyper-V, PowerShell e o navegador trabalhando juntos, provando que o
Windows, nas mãos certas, é estação de trabalho e servidor de verdade. Quebre as
correntes e mostre o verdadeiro potencial da sua máquina.

> **Aviso de segurança**
>
> - O PHPorto **executa comando de shell arbitrário** na máquina onde roda, com
>   os privilégios do usuário do WSL.
> - Na tela `/win`, as ações rodam **como Administrador do Windows**.
> - Ele deve escutar **só em `127.0.0.1`**. Acesso de outra máquina é por túnel
>   SSH, nunca abrindo a porta.
> - Sem token configurado no `.env`, a aplicação não serve nada.
>
> Detalhes em [docs/seguranca.md](docs/seguranca.md).

Por baixo, o PHPorto recebe um
comando pelo navegador ou por HTTP, executa dentro da distro do WSL ou como ação
do Windows, devolve a saída e grava cada execução num banco — SQLite por padrão,
MySQL ou MongoDB por configuração. PHP 8.5 sem framework, servido pelo servidor
embutido do PHP.

## Requisitos

- Windows 11 (desenvolvido e testado nele)
- PHP 8.5 na linha de comando, com as extensões `json`, `pdo`, `pdo_sqlite` e
  `pdo_mysql`
- Composer
- WSL2 com uma distro instalada, visível em `wsl -l -q`
- Windows PowerShell 5.1, para a tela `/win`

Opcionais, por recurso:

- Hyper-V: Windows 11 Pro ou Enterprise
- Captura de rede: TShark (Wireshark)
- Sunshine/Moonlight: winget
- Ollama: placa NVIDIA com `nvidia-smi` recomendada; sem ela, a medição da
  memória de vídeo é aproximada

## Instalação

1. Clone o repositório:

   ```bash
   git clone https://github.com/caiobarilli/PHPorto.git
   cd PHPorto
   ```

2. Instale as dependências:

   ```bash
   composer install
   ```

3. Copie o `.env.example` para `.env` e ajuste. O mínimo é `PHPORTO_WSL_ROOT`,
   a pasta onde os comandos começam, vista de dentro do WSL. As chaves estão em
   [docs/configuracao.md](docs/configuracao.md).

4. Gere o token de acesso:

   ```bash
   php token.php
   ```

   O token é impresso e gravado no `.env`. No navegador, cole-o no campo de
   senha do diálogo de login; o usuário é ignorado.

5. Suba o servidor:

   ```bash
   php -S 127.0.0.1:4001 -t public public/router.php
   ```

   E abra <http://127.0.0.1:4001>. O painel recusa conexões que não venham da própria máquina.

## Telas

| rota | o que faz |
| --- | --- |
| `/` | Início, com os botões de configuração, WSL e Windows e o motivo de cada um estar desabilitado. |
| `/config` | Banco ativo, interruptor da API, PowerShell elevado e restauração de fábrica. |
| `/wsl` | Executa comandos e copia arquivos na distro, com o histórico do WSL. |
| `/win` | As ações do Windows, executadas por um PowerShell elevado, com o histórico do Windows. |
| `/hyperv` | Liga, desliga e reinicia as VMs. |
| `/ollama` | Instala, inicia e para o Ollama e baixa ou remove modelos, sem PowerShell elevado. |

## Ollama

A tela `/ollama` cuida do [Ollama](https://ollama.com) no Windows, pelo usuário
comum, sem UAC. Também é listada na aba Aplicativos da `/win`.

- **Versão fixa:** o instalador é o `v0.40.13`, e só é executado se o SHA-256
  bater com `9e3c76e54e7184c68e88a2378b909bf59bbe454f83c076cb4f4a717e946ae719`.
- **Só loopback:** o Ollama sobe com `OLLAMA_HOST=127.0.0.1:11434`, e o PHPorto
  só fala com ele nesse endereço.
- **Modelos permitidos:** `qwen3:4b` e `llama3.2:3b`. Qualquer outro nome é
  recusado.

### Memória de vídeo

Antes de baixar um modelo, o PHPorto mede a placa de vídeo pelo `nvidia-smi`;
sem ele, usa o maior `AdapterRAM` do `Win32_VideoController`, marcado como
aproximado na tela. A memória mínima de cada modelo é o tamanho dele mais
1536 MiB:

| modelo | tamanho | memória de vídeo mínima |
| --- | --- | --- |
| `qwen3:4b` | 2560 MiB | 4096 MiB |
| `llama3.2:3b` | 2048 MiB | 3584 MiB |

Se a placa tiver menos que o mínimo, ou se a memória não puder ser medida, o
download é recusado com uma mensagem e nada é baixado. Não há modo reduzido nem
download em CPU. A conferência vale só para baixar modelos; instalar o Ollama
não depende da placa.

## Recursos

- **Telemetria e apps empurrados:** os ajustes da aba Sistema da `/win` reduzem
  a telemetria ao mínimo que a edição do Windows permite, e *Remover apps* tira os pacotes APPX marcados.
- **Tráfego da rede:** a *Captura de rede* grava pacotes com o TShark por um
  tempo definido e gera um relatório em `runtime/Reports`. Sem o TShark, instala o Wireshark pelo
  winget, que confere o hash do instalador declarado no manifesto.
- **RDP em alta qualidade:** liga o vídeo H.264/AVC 444 no RDP
  (`AVC444ModePreferred=1`).
- **Moonlight:** instala o Sunshine pelo winget, que confere o hash do
  instalador declarado no manifesto, define as credenciais e pareia o Moonlight
  com o PIN.
- **WSL no navegador:** a `/wsl` executa comandos e copia arquivos na distro.
- **Hyper-V:** a `/hyperv` liga, desliga e reinicia as VMs.
- **Memória:** a ação *Memória* roda o WinMemoryCleaner 3.0.8 (versão e SHA-256
  fixos) como Administrador, esvaziando a standby list, a modified page list, a
  combined page list e os working sets dos processos e do sistema. A tela mostra
  a RAM livre antes e depois.

## Portão

```bash
composer gate
```

Roda a suíte Pest, o PHPStan, o PHP-CS-Fixer e os testes Pester. O CI do
GitHub Actions roda as mesmas verificações em Linux e Windows a cada push e
pull request para a `main` (selo no topo). Detalhes em
[docs/desenvolvimento.md](docs/desenvolvimento.md).

## Licença e créditos

MIT — texto em [LICENSE](LICENSE).

A tela `/win` executa ações que vieram do
[WinUtil](https://github.com/ChrisTitusTech/winutil), de Chris Titus Tech, sob
MIT © 2022 CT Tech Group LLC. O que veio de onde está em
[docs/terceiros.md](docs/terceiros.md).

## Documentação

- [Configuração](docs/configuracao.md) — cada chave do `.env`
- [WSL](docs/wsl.md) — a tela `/wsl`
- [Windows](docs/windows.md) — a tela `/win` e as ações
- [API](docs/api.md) — `/api/executions`
- [Segurança](docs/seguranca.md) — o modelo de proteção e o acesso remoto
- [Desenvolvimento](docs/desenvolvimento.md) — árvore, portão, testes e convenções
- [Terceiros](docs/terceiros.md) — código do upstream e divergências
- [Errata do log](docs/ERRATA.md) — mensagens de commit que perderam texto
