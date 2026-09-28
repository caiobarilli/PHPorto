# PHPorto

O PHPorto faz a ponte entre o Windows e o WSL numa máquina local. Recebe um
comando pelo navegador ou por HTTP, executa dentro da distro do WSL ou como ação
do Windows, devolve a saída e grava cada execução num banco — SQLite por padrão,
MySQL ou MongoDB por configuração. PHP 8.5 sem framework, servido pelo servidor
embutido do PHP.

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

## Requisitos

- Windows 11 (desenvolvido e testado nele)
- PHP 8.5 na linha de comando, com as extensões `json`, `pdo`, `pdo_sqlite` e
  `pdo_mysql`
- Composer
- WSL2 com uma distro instalada, visível em `wsl -l -q`
- Windows PowerShell 5.1, para a tela `/win`

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

   E abra <http://127.0.0.1:4001>.

## Telas

| rota | o que faz |
| --- | --- |
| `/` | Início, com os botões de configuração, WSL e Windows e o motivo de cada um estar desabilitado. |
| `/config` | Banco ativo, interruptor da API, PowerShell elevado e restauração de fábrica. |
| `/wsl` | Executa comandos e copia arquivos na distro, com o histórico do WSL. |
| `/win` | As treze ações do Windows, executadas por um PowerShell elevado, com o histórico do Windows. |

## Portão

```bash
composer gate
```

Roda a suíte Pest, o PHPStan, o PHP-CS-Fixer e os testes Pester. O projeto não
tem CI; o portão é este comando. Detalhes em
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
- [Windows](docs/windows.md) — a tela `/win` e as treze ações
- [API](docs/api.md) — `/api/executions`
- [Segurança](docs/seguranca.md) — o modelo de proteção e o acesso remoto
- [Desenvolvimento](docs/desenvolvimento.md) — árvore, portão, testes e convenções
- [Terceiros](docs/terceiros.md) — código do upstream e divergências
- [Errata do log](docs/ERRATA.md) — mensagens de commit que perderam texto
