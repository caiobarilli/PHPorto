# Acesso pela rede privada: exceção opt-in e fail-closed

## Contexto

O ADR 0009 fecha o painel à conexão do loopback, e o teto dizia: `php -S` só em `127.0.0.1`, outra máquina só por túnel SSH, sem abrir a porta do PHPorto na LAN. O Maintainer aprovou uma exceção: o notebook da mesma rede privada acessa o painel autenticando com o token da instância.

## Decisão

A exceção vale só com todas as condições abaixo. Qualquer uma faltando é nenhum listener de rede e nenhuma conexão de fora aceita.

- **Opt-in, só pelo terminal:** `php phporto network on` pergunta o IP de escuta (IPv4 privado de uma interface da máquina), a porta e a allowlist (IPv4 privado, sozinho ou em CIDR, nunca vazia). Mostra o risco do HTTP sem TLS e exige digitar `sim`, sempre, sem flag e sem atalho para script. Nenhuma tela liga a rede.
- **Estado validado:** `files/network.json` (`enabled`, `bind_ip`, `port`, `allow`, `updated_at`). Inválido ou ausente vira estado fechado.
- **Porta:** a mesma do loopback (4001 por padrão), no `bind_ip`. Nunca `0.0.0.0`.
- **Dois processos:** `php phporto serve` sobe o loopback e, só se tudo conferir, um segundo `php -S` no `bind_ip:port`. Se o de rede cair ou não subir, o loopback continua e o motivo vai para o histórico, sem segredo.
- **O listener só sobe com:** rede ligada; `bind_ip` ainda pertencendo a uma interface da máquina; perfil da rede desse IP igual a `Private`; regra de firewall existente e igual ao estado (habilitada, entrada, permitir, perfil Private, TCP, `LocalPort`, `LocalAddress` e `RemoteAddress` iguais ao estado, uma regra só).
- **Firewall:** ação elevada `net-firewall` (`add` e `remove`), sensível, um UAC por ação (ADR 0001 e 0002). Regra de entrada TCP só no perfil Private, `LocalAddress=bind_ip`, `LocalPort=port`, `RemoteAddress=allowlist`, programa `php.exe`, grupo fixo `PHPorto Rede`. A regra nunca é criada fora de uma ação do usuário com UAC.
- **Mudança de IP:** o `bind_ip` não acompanha a rede. Se o IP sumir, o listener não sobe e a tela e o `status` pedem um novo `network on`.
- **Defesa na aplicação, antes de qualquer rota:** fora do loopback, a requisição só passa se chegou no `bind_ip:port`, com o `Host` certo e o IP de origem na allowlist. Os demais recebem 403 ou 421 como antes. O login de fora usa o mesmo token, com contador de falhas por IP (guardado como sha256) e aviso na tela.
- **Desligar:** `php phporto network off` e o botão Desligar já da `/config` usam a mesma rotina. O estado fecha primeiro (o `Front` já recusa tudo); depois remove a regra, com UAC. Se o UAC for recusado, o estado continua fechado e a tela e o terminal dizem que a regra ficou para remover. O `serve` vigia o estado e derruba só o listener de rede. É idempotente e não mexe no início com o Windows.
- **Histórico, sem segredo:** ligada, desligada, regra criada ou removida, regra não removida, listener subiu, caiu, encerrado ou não subiu, login de fora aceito ou recusado.

## Consequência

O HTTP segue sem TLS: na rede, o token e o conteúdo do painel viajam em texto puro, e o painel executa ações de administrador. A allowlist, o perfil Private e o limite de tentativas por IP reduzem o alcance, mas não substituem TLS. Quem tem acesso ao painel pela rede pode tentar o token.

Um processo filho de uma sessão SSH não é o caminho: o acesso não deve depender dele. A subida no boot é outra parte (iniciar com o Windows).

O teto passa a dizer que `php -S` escuta em `127.0.0.1` e, só nesta exceção, também no IP privado escolhido. O ADR 0009 continua valendo para o loopback; esta decisão é a exceção dele.

Mapeamento N1/N2/N3: o texto do escopo aprovado não está no repositório. Leitura registrada, a confirmar pelo Maintainer: N1 é o núcleo (estado, matriz de acesso do `Front`, contadores e auditoria de login, PR #77); N2 é o comando `network`, o segundo listener e o firewall; N3 é a seção na `/config`, o Desligar já e este ADR com o teto.

Não testado no Windows real, só em testes automatizados com fakes:

- ler o perfil da rede (`Get-NetConnectionProfile`) e a regra (`Get-NetFirewallRule`, filtros de porta e endereço) sem elevação
- o formato de `RemoteAddress` devolvido pelo Windows (a comparação normaliza máscara pontilhada, mas isso não foi visto)
- o UAC pelo `network on` e pelo Desligar já, e a recusa do UAC
- a regra no perfil Private deixando o notebook entrar e o perfil Public barrando
- o acesso do notebook, o contador por IP e o aviso de login
- o `serve` derrubando o listener de rede ao desligar, e o loopback seguindo de pé
- o que acontece com os processos filhos ao fechar a sessão SSH

## Commit

`9f9e8dd`, `7c4c768`, `d3acf65`, `641e38b` (base: `f01b053`, `ddee4e6`, `4c90539`, `2af8cfb`)

## PR

Sem PR: branch único `feat/cli-phporto`, com merge do Maintainer. O núcleo da rede entrou antes pelo `#77`.
